<?php
declare(strict_types=1);
// Catches failed readiness continuing load, failed children losing artifacts,
// and timeout/interruption cleanup which leaves own subprocess running.
if (!is_file(__DIR__.'/../run.php')) { fwrite(STDERR,"FAIL: Missing bounded sequential driver\n"); exit(1); }
require __DIR__.'/../run.php';
$dir=sys_get_temp_dir().'/pilot-synthetic-driver-'.bin2hex(random_bytes(6)); mkdir($dir);
// Breaks caught: tmpfs transport loses binary bytes/mixes stderr, writes beyond
// its cap, launches oversized input, or discards partial bytes on timeout.
if(!function_exists('pilotStreamChild')) { fwrite(STDERR,"FAIL: Missing bounded binary-safe tmpfs stream transport\n"); exit(1); }
$binary=str_repeat("\0\xff\x80\n",256);
$stream=pilotStreamChild([PHP_BINARY,'-r','stream_copy_to_stream(STDIN,STDOUT); fwrite(STDERR,"separate diagnostic");'],$dir.'/stream.log',2,$dir.'/binary.raw',2048,$binary);
assert($stream['status']==='copied'&&$stream['bytes']===1024&&file_get_contents($dir.'/binary.raw')===$binary,'Binary stdin/stdout roundtrip changed bytes');
assert(str_contains(file_get_contents($dir.'/stream.log'),'separate diagnostic')&&!str_contains(file_get_contents($dir.'/binary.raw'),'diagnostic'),'Stderr contaminated raw bytes');
$stream=pilotStreamChild([PHP_BINARY,'-r','fwrite(STDOUT,str_repeat("x",32));'],$dir.'/exact-cap.log',2,$dir.'/exact-cap.raw',32);
assert($stream['status']==='copied'&&$stream['bytes']===32&&filesize($dir.'/exact-cap.raw')===32&&!file_exists($dir.'/exact-cap.raw.partial'),'Exact byte cap was incorrectly rejected or left partial');
$stream=pilotStreamChild([PHP_BINARY,'-r','fwrite(STDERR,"synthetic missing native file"); exit(17);'],$dir.'/missing.log',2,$dir.'/missing.raw',2048);
assert($stream['status']==='unavailable'&&$stream['exit']===17&&$stream['bytes']===0&&!file_exists($dir.'/missing.raw')&&is_file($dir.'/missing.raw.partial'),'Missing file became a copied artifact');
$stream=pilotStreamChild([PHP_BINARY,'-r','fwrite(STDOUT,str_repeat("x",10000)); sleep(2);'],$dir.'/oversize.log',1,$dir.'/oversize.raw',32);
assert($stream['status']==='unavailable'&&$stream['reason']==='artifact exceeds byte cap'&&filesize($dir.'/oversize.raw.partial')===32&&!file_exists($dir.'/oversize.raw'),'Oversized stream was not bounded during write or promoted');
$started=hrtime(true);
$stream=pilotStreamChild([PHP_BINARY,'-r','fwrite(STDOUT,"partial"); sleep(2);'],$dir.'/stream-timeout.log',.1,$dir.'/stream-timeout.raw',32);
assert($stream['status']==='unavailable'&&$stream['exit']===124&&file_get_contents($dir.'/stream-timeout.raw.partial')==='partial'&&!file_exists($dir.'/stream-timeout.raw')&&(hrtime(true)-$started)/1e9<1.5,'Timeout lost partial stream, promoted it, or exceeded deadline');
try { pilotStreamChild([PHP_BINARY,'-r','file_put_contents($argv[1],"launched");',$dir.'/forbidden-input-child'],$dir.'/input-limit.log',1,$dir.'/input-limit.raw',32,str_repeat('x',16385)); throw new LogicException('Accepted oversized probe input'); }
catch(RuntimeException $error) { assert(str_contains($error->getMessage(),'input byte cap')&&!file_exists($dir.'/forbidden-input-child'),'Oversized input launched a child'); }
$build=$dir.'/build.json'; file_put_contents($build,json_encode(['used_seconds'=>357.271023,'limit_seconds'=>3600,'active'=>null]));
foreach(['child-exit','marker','native-count','measured'] as $failure) {
    $budget=new PilotBudget($dir.'/'.$failure.'.json','synthetic',$dir,$build,fn()=>1000.0);
    $schedule=pilotSchedule(); $loadCalls=0; $readyCalls=0;
    $readiness=function(array $cell)use($failure,&$readyCalls):array {
        ++$readyCalls; $count=$cell['revision']==='baseline'?15:16;
        return ['exit'=>$failure==='child-exit'?108:0,'log'=>$failure==='marker'?'':json_encode(['preflight'=>'passed','revision'=>$cell['revision'],'target'=>$cell['target'],'requests'=>$count,'health_requests'=>0]),'native_count'=>$failure==='native-count'?0:$count];
    };
    $window=function(array $cell)use(&$loadCalls,$dir):void { ++$loadCalls; file_put_contents($dir.'/partial.txt','synthetic retained partial'); throw new RuntimeException('synthetic invalid evidence'); };
    try { pilotRunSequence($budget,$schedule,$readiness,$window); throw new LogicException('Failed sequence returned success'); }
    catch(RuntimeException $error){}
    assert($loadCalls===($failure==='measured'?1:0));
    assert($readyCalls===($failure==='measured'?6:1));
    assert(count(array_filter($schedule,fn($cell)=>$cell['status']==='skipped'))===($failure==='measured'?80:81));
    if($failure==='measured') assert(file_get_contents($dir.'/partial.txt')==='synthetic retained partial');
    $budget->close();
}
$log=$dir.'/exit.log';
$result=pilotChild([PHP_BINARY,'-r','fwrite(STDOUT,"synthetic partial\\n"); exit(17);'],$log,2);
assert($result===17 && str_contains(file_get_contents($log),'synthetic partial'));
$started=hrtime(true);
$result=pilotChild([PHP_BINARY,'-r','usleep(2000000);'],$dir.'/timeout.log',.1);
assert($result!==0 && (hrtime(true)-$started)/1e9<1.5);
pcntl_async_signals(true);
pcntl_signal(SIGALRM,static function():void { throw new RuntimeException('synthetic interruption'); });
pcntl_alarm(1);
try { pilotChild([PHP_BINARY,'-r','file_put_contents($argv[1],(string)getmypid()); sleep(10);',$dir.'/interrupted.pid'],$dir.'/interrupt.log',12); throw new LogicException('Interruption ignored'); }
catch(RuntimeException $error) { assert($error->getMessage()==='synthetic interruption'); }
finally { pcntl_alarm(0); }
$pid=(int)file_get_contents($dir.'/interrupted.pid');
assert(!posix_kill($pid,0),'Own interrupted child remained active');
$command=pilotCompose('mezzio-pilot-synthetic','fpm','candidate');
assert(in_array('--project-name',$command,true) && in_array('mezzio-pilot-synthetic',$command,true));
chmod(__DIR__.'/fake-docker.php',0755);
symlink(__DIR__.'/fake-docker.php',$dir.'/docker');
$savedPath=getenv('PATH'); putenv('PATH='.$dir.':'.$savedPath); putenv('PILOT_TEST_LOG='.$dir.'/fake-docker.log');
$budget=new PilotBudget($dir.'/existing-project.json','synthetic',$dir,$build,fn()=>1000.0);
try { pilotObserve(pilotSchedule()[0],$dir.'/existing-project',$budget,false); throw new LogicException('Accepted existing project'); }
catch(RuntimeException $error) { assert(str_contains($error->getMessage(),'existing project')); }
finally { putenv('PATH='.$savedPath); putenv('PILOT_TEST_LOG'); $budget->close(); }
$calls=pilotJsonLines($dir.'/fake-docker.log');
assert(!array_filter($calls,fn($command)=>in_array('down',$command,true)),'Deleted existing project before owning it');
putenv('PATH='.$dir.':'.$savedPath); putenv('PILOT_TEST_LOG='.$dir.'/partial-docker.log'); putenv('PILOT_TEST_MODE=partial');
$budget=new PilotBudget($dir.'/partial-project.json','synthetic',$dir,$build,fn()=>1000.0);
try { pilotObserve(pilotSchedule()[0],$dir.'/partial-project',$budget,false); throw new LogicException('Failed up returned success'); }
catch(RuntimeException $error) { assert(str_contains($error->getMessage(),'exit17') || str_contains($error->getMessage(),'exit 17')); }
finally { putenv('PATH='.$savedPath); putenv('PILOT_TEST_LOG'); putenv('PILOT_TEST_MODE'); $budget->close(); }
$calls=pilotJsonLines($dir.'/partial-docker.log');
$up=array_values(array_filter($calls,fn($command)=>in_array('up',$command,true)));
$down=array_values(array_filter($calls,fn($command)=>in_array('down',$command,true)));
assert(count($up)===1 && count($down)===1,'Failed partial creation did not clean own project exactly once');
assert($up[0][array_search('--project-name',$up[0],true)+1]===$down[0][array_search('--project-name',$down[0],true)+1]);
// Real independent inert process models native k6, outside the Docker client
// group. Synthetic partial file lives in the model container until own down.
$fixture=pilotLedgerDirectory().'/task4-synthetic-abort-'.bin2hex(random_bytes(4)); mkdir($fixture);
pilotWriteJson($fixture.'/LABEL.json',['evidence_kind'=>'SYNTHETIC TEST ONLY, zero HTTP; not measurement raw']);
$inventory=[];
foreach(['php-fpm'=>[str_repeat('a',64),4000000000,2147483648],'generator'=>[str_repeat('b',64),2000000000,1073741824],'app-fpm'=>[str_repeat('c',64),1000000000,268435456]] as $service=>[$id,$cpu,$memory])
    $inventory[]=['Id'=>$id,'Image'=>$service==='php-fpm'?'sha256:4102d15e94a811c89a440ee1be462c641c83ef30660d3efa70340f2ec9e4f144':($service==='generator'?'sha256:bfa9adb4f73593aaa9647cd339ecd5e6103827bce63267618a4ec51d5db56f72':'sha256:'.str_repeat('d',64)),'Config'=>['Labels'=>['com.docker.compose.project'=>'unused-until-up','com.docker.compose.service'=>$service,'com.docker.compose.oneoff'=>$service==='generator'?'True':'False']],'HostConfig'=>['NanoCpus'=>$cpu,'Memory'=>$memory],'State'=>['Running'=>true,'OOMKilled'=>false,'StartedAt'=>'2026-10-06T00:00:00Z'],'RestartCount'=>0];
pilotWriteJson($fixture.'/inventory.json',$inventory); pilotWriteJson($fixture.'/verification.json',['runtime'=>'fpm','revision'=>'baseline']);
[$native,$nativePid]=pilotStartChild([PHP_BINARY,'-r','sleep(10);'],$fixture.'/native.log');
putenv('PATH='.$dir.':'.$savedPath); putenv('PILOT_TEST_LOG='.$fixture.'/calls.jsonl'); putenv('PILOT_TEST_MODE=interrupt'); putenv('PILOT_TEST_FIXTURE='.$fixture); putenv('PILOT_TEST_PARENT='.getmypid()); putenv('PILOT_TEST_NATIVE_PID='.$nativePid);
pcntl_signal(SIGUSR1,static function():void { throw new RuntimeException('synthetic workload interruption'); });
$budget=new PilotBudget($dir.'/interrupt-project.json','synthetic',$dir,$build,fn()=>1000.0);
try { pilotObserve(pilotSchedule()[0],$fixture.'/output',$budget,true); throw new LogicException('Synthetic interruption ignored'); }
catch(RuntimeException $error) { file_put_contents($fixture.'/caught.txt',$error->getMessage()); $nativeSurvived=proc_get_status($native)['running']; }
finally { putenv('PATH='.$savedPath); foreach(['PILOT_TEST_LOG','PILOT_TEST_MODE','PILOT_TEST_FIXTURE','PILOT_TEST_PARENT','PILOT_TEST_NATIVE_PID'] as $key) putenv($key); pcntl_signal(SIGUSR1,SIG_DFL); $budget->close(); pilotStopChild($native,$nativePid); }
assert(is_file($fixture.'/output/k6-raw.json.gz'),'Signal lost already-copyable synthetic partial raw before down; fixture '.$fixture);
assert(!is_file($fixture.'/generator-present')&&is_file($fixture.'/network-removed'),'Observed cleanup regression: own down returned0 but left sleep600 generator and busy network');
assert($nativeSurvived===false,'Native synthetic workload still running before test fallback cleanup');
assert(file_get_contents($fixture.'/output/k6-raw.json.gz')===file_get_contents($fixture.'/raw-lost-on-removal.gz'),'Salvaged partial raw content changed');
$calls=pilotJsonLines($fixture.'/calls.jsonl'); $stops=[];$copies=[];$downs=[];
foreach($calls as $index=>$call) { if(in_array('pilot-owned-native-stop',$call,true))$stops[]=$index; if(in_array('exec',$call,true)&&in_array('cat',$call,true))$copies[]=$index; if(in_array('down',$call,true))$downs[]=$index; if(in_array('rm',$call,true))$removal=$index; }
assert(count($stops)===1 && count($copies)===2 && count($downs)===1 && $stops[0]<$copies[0] && max($copies)<$removal && $removal<$downs[0],'Native stop → independent artifact salvage → exact generator removal → own down ordering failed');
assert(!posix_kill($nativePid,0),'Owned native synthetic process survived cleanup');
assert(pilotReadJson($fixture.'/output/artifacts.json')['summary']['status']==='unavailable','Missing abort summary was not explicitly reported');
assert(is_file($fixture.'/output/k6-summary.json.partial')&&!file_exists($fixture.'/output/k6-summary.json'),'Missing summary received canonical artifact name');
assert(pilotReadJson($fixture.'/output/artifacts.json')['raw']['sha256']===hash_file('sha256',$fixture.'/raw-lost-on-removal.gz'),'Salvage status lacks exact retained byte SHA');
// Failure after run-d but before inventory assigns the generator ID.
$early=$dir.'/before-inventory'; mkdir($early); pilotWriteJson($early.'/inventory.json',$inventory);
putenv('PATH='.$dir.':'.$savedPath); putenv('PILOT_TEST_LOG='.$early.'/calls.jsonl'); putenv('PILOT_TEST_MODE=before-inventory'); putenv('PILOT_TEST_FIXTURE='.$early);
$budget=new PilotBudget($dir.'/before-inventory-budget.json','synthetic',$dir,$build,fn()=>1000.0);
try { pilotObserve(pilotSchedule()[0],$early.'/output',$budget,true); throw new LogicException('Failed inventory returned success'); }
catch(RuntimeException $error) { assert(str_contains($error->getMessage(),'17')); }
finally { putenv('PATH='.$savedPath); foreach(['PILOT_TEST_LOG','PILOT_TEST_MODE','PILOT_TEST_FIXTURE'] as $key) putenv($key); $budget->close(); }
assert(!is_file($early.'/generator-present')&&is_file($early.'/network-removed'),'Pre-inventory failure left created one-off/network');
$calls=pilotJsonLines($early.'/calls.jsonl');
assert(count(array_filter($calls,fn(array $call):bool=>in_array('label=com.docker.compose.service=generator',$call,true)&&in_array('label=com.docker.compose.oneoff=True',$call,true)))===1,'Unknown generator was not resolved by exact project/service/oneoff labels');
assert(!array_filter($calls,fn(array $call):bool=>in_array('pilot-owned-k6',$call,true)),'Inventory failure launched native workload');
foreach(['success','discovery','foreign-project','reused-role','mismatched-id','ambiguous','leftover','deadline'] as $case) {
    $model=$dir.'/cleanup-'.$case; mkdir($model); $project='mezzio-pilot-synthetic-cleanup';
    $rows=$inventory; foreach($rows as &$row) $row['Config']['Labels']['com.docker.compose.project']=$project; unset($row);
    if($case==='foreign-project') $rows[1]['Config']['Labels']['com.docker.compose.project']='mezzio-pilot-foreign';
    if($case==='reused-role') $rows[1]['Config']['Labels']['com.docker.compose.service']='php-fpm';
    if($case==='mismatched-id') $rows[1]['Id']=str_repeat('e',64);
    if(in_array($case,['ambiguous','leftover'],true)) file_put_contents($model.'/'.$case,'synthetic boundary failure');
    pilotWriteJson($model.'/inventory.json',$rows); file_put_contents($model.'/project',$project); file_put_contents($model.'/generator-present','sleep600'); file_put_contents($model.'/foreign-untouched','unrelated sentinel');
    putenv('PATH='.$dir.':'.$savedPath); putenv('PILOT_TEST_LOG='.$model.'/calls.jsonl'); putenv('PILOT_TEST_MODE=cleanup'); putenv('PILOT_TEST_FIXTURE='.$model);
    $success=false;
    try { pilotOwnCleanup(pilotCompose($project,'fpm','baseline'),pilotDockerCommand(),$project,in_array($case,['discovery','ambiguous'],true)?null:str_repeat('b',64),$model.'/cleanup.log',hrtime(true)/1e9+($case==='deadline'?-1:5)); $success=true; }
    catch(RuntimeException $error) {}
    finally { putenv('PATH='.$savedPath); foreach(['PILOT_TEST_LOG','PILOT_TEST_MODE','PILOT_TEST_FIXTURE'] as $key) putenv($key); }
    assert($success===in_array($case,['success','discovery'],true),'Owned cleanup case incorrectly accepted/rejected: '.$case);
    assert(file_get_contents($model.'/foreign-untouched')==='unrelated sentinel','Cleanup touched unrelated resource');
    $calls=is_file($model.'/calls.jsonl')?pilotJsonLines($model.'/calls.jsonl'):[];
    if(in_array($case,['foreign-project','reused-role','mismatched-id','ambiguous','deadline'],true)) assert(!array_filter($calls,fn(array $call):bool=>in_array('rm',$call,true)||in_array('down',$call,true))&&is_file($model.'/generator-present'),'Rejected identity/deadline mutated resources');
    if($success) assert(!is_file($model.'/generator-present')&&is_file($model.'/network-removed'),'Successful cleanup left resources');
    foreach($calls as $call) if(in_array('rm',$call,true)) assert(end($call)===str_repeat('b',64)&&in_array('-f',$call,true),'Cleanup removal was not exact full-ID scoped');
}
$reviewFailures=[];
foreach(['near-expiry-capture','term-ignoring-down','gone-before-inspect','gone-before-rm','network-only','foreign-network','busy-network','network-id-mismatch'] as $case) {
    $model=$dir.'/review-'.$case; mkdir($model); $project='mezzio-pilot-synthetic-review';
    $rows=$inventory; foreach($rows as &$row) $row['Config']['Labels']['com.docker.compose.project']=$project; unset($row);
    pilotWriteJson($model.'/inventory.json',$rows); file_put_contents($model.'/project',$project); file_put_contents($model.'/foreign-untouched','unrelated sentinel');
    if($case!=='gone-before-inspect') file_put_contents($model.'/generator-present','sleep600');
    $marker=match($case){'near-expiry-capture'=>'slow-inspect','term-ignoring-down'=>'slow-down','gone-before-rm'=>'disappear-at-rm',default=>$case};
    file_put_contents($model.'/'.$marker,'review boundary');
    if(in_array($case,['foreign-network','busy-network','network-id-mismatch'],true)) file_put_contents($model.'/network-only','leftover network');
    putenv('PATH='.$dir.':'.$savedPath); putenv('PILOT_TEST_LOG='.$model.'/calls.jsonl'); putenv('PILOT_TEST_MODE=cleanup'); putenv('PILOT_TEST_FIXTURE='.$model);
    $phase=$case==='near-expiry-capture'?.3:5; $started=hrtime(true)/1e9; $success=false;
    try { pilotOwnCleanup(pilotCompose($project,'fpm','baseline'),pilotDockerCommand(),$project,str_repeat('b',64),$model.'/cleanup.log',$started+$phase); $success=true; }
    catch(RuntimeException $error) {}
    finally { putenv('PATH='.$savedPath); foreach(['PILOT_TEST_LOG','PILOT_TEST_MODE','PILOT_TEST_FIXTURE'] as $key) putenv($key); }
    $elapsed=hrtime(true)/1e9-$started; $calls=pilotJsonLines($model.'/calls.jsonl');
    $downs=array_filter($calls,fn(array $call):bool=>in_array('down',$call,true));
    $networkRemovals=array_filter($calls,fn(array $call):bool=>in_array('network',$call,true)&&in_array('rm',$call,true));
    $good=file_get_contents($model.'/foreign-untouched')==='unrelated sentinel';
    if(in_array($case,['near-expiry-capture','term-ignoring-down'],true)) $good=$good&&$elapsed<=$phase&&!$success&&!is_file($model.'/late-write');
    elseif(in_array($case,['gone-before-inspect','gone-before-rm'],true)) {
        $phaseProof=is_file($model.'/cleanup.log.phase.json')?pilotReadJson($model.'/cleanup.log.phase.json'):[];
        $good=$good&&count($downs)===1&&is_file($model.'/network-removed')&&!$success&&!empty($phaseProof['failures']);
    } elseif($case==='network-only') $good=$good&&$success&&count($networkRemovals)===1&&is_file($model.'/network-removed');
    else $good=$good&&!$success&&count($networkRemovals)===0&&!is_file($model.'/network-removed');
    if(is_file($model.'/slow-child.pid')) { $child=(int)file_get_contents($model.'/slow-child.pid'); $stat=@file_get_contents('/proc/'.$child.'/stat'); $good=$good&&($stat===false||explode(' ',substr($stat,strrpos($stat,')')+2))[0]==='Z'); }
    echo ($good?'PASS':'RED')." cleanup review $case elapsed=$elapsed down=".count($downs)." network_rm=".count($networkRemovals)." fixture=$model\n";
    if(!$good) $reviewFailures[]=$case;
}
if($reviewFailures) { fwrite(STDERR,'FAIL cleanup review boundaries: '.implode(',',$reviewFailures)."\n"); exit(1); }
echo "PASS owned cleanup: observed one-off/network regression, abort salvage→exact rm→down, pre-inventory discovery, foreign/reused/ambiguous identity refusal, empty checks and cumulative deadline; zero HTTP\n";
echo "PASS inert driver: six-cell readiness gates, failure stops/skips all escalation, partial retention, bounded child exit/timeout; zero HTTP\n";
