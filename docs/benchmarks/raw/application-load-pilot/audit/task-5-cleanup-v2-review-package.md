# Task5 post-data owned cleanup delta — zero new HTTP


## A/pilot/run.php
SHA256 5c2afef03fb8bc968a7374317114917551fa9cdf384ecaccf7e8ed7dd1e26389

```diff
--- original-executed/pilot/run.php
+++ post-data-cleanup/pilot/run.php
@@ -154,6 +154,66 @@
     return $artifacts;
 }
 
+function pilotOwnCleanup(array $compose,array $docker,string $project,?string $generator,string $log,float $deadline): void {
+    if(!preg_match('/^mezzio-pilot-[a-z0-9-]+$/D',$project) || !in_array('--project-name',$compose,true)
+        || ($compose[array_search('--project-name',$compose,true)+1]??null)!==$project) throw new RuntimeException('Own cleanup project mismatch');
+    $phase=['project'=>$project,'deadline_monotonic'=>$deadline,'started_monotonic'=>hrtime(true)/1e9,'events'=>[],'failures'=>[]];
+    $call=static function(array $argv,float $limit=1)use($deadline,$log,&$phase):array {
+        // Existing stop waits50ms; polling/reaping/durable capture also consume
+        // time. Keep100ms INSIDE this phase for all termination/teardown work.
+        $seconds=min($limit,$deadline-hrtime(true)/1e9-.1);
+        if($seconds<=0) throw new RuntimeException('Own cleanup phase deadline exhausted before teardown reserve');
+        $capture=$log.'.command-'.(count($phase['events'])+1).'.stdout';
+        $result=pilotStreamChild($argv,$log,$seconds,$capture,65536);
+        $phase['events'][]=['argv'=>$argv,'capture'=>basename($capture),'result'=>$result];
+        pilotWriteJson($log.'.phase.json',$phase);
+        return $result+['stdout'=>$result['status']==='copied'?file_get_contents($capture):''];
+    };
+    $read=static function(array $argv)use($call):string {
+        $result=$call($argv);
+        if($result['status']!=='copied') throw new RuntimeException('Own cleanup metadata unavailable');
+        return $result['stdout'];
+    };
+    try {
+        if($generator===null) {
+            $ids=trim($read([...$docker,'ps','-aq','--no-trunc','--filter','label=com.docker.compose.project='.$project,'--filter','label=com.docker.compose.service=generator','--filter','label=com.docker.compose.oneoff=True']));
+            if($ids!=='') {
+                $matches=preg_split('/\s+/',$ids);
+                if(count($matches)!==1) throw new RuntimeException('Ambiguous own generator cleanup identity');
+                $generator=$matches[0];
+            }
+        }
+        if($generator!==null) {
+            if(!preg_match('/^[a-f0-9]{64}$/D',$generator)) throw new RuntimeException('Full owned generator ID required for cleanup');
+            $inspected=$call([...$docker,'inspect',$generator]);
+            if($inspected['status']!=='copied') $phase['failures'][]='Generator inspect unavailable; no unverified removal';
+            else {
+                $inventory=json_decode($inspected['stdout'],true,512,JSON_THROW_ON_ERROR);
+                $container=$inventory[0]??[]; $labels=$container['Config']['Labels']??[];
+                if(count($inventory)!==1 || ($container['Id']??null)!==$generator || ($labels['com.docker.compose.project']??null)!==$project
+                    || ($labels['com.docker.compose.service']??null)!=='generator' || ($labels['com.docker.compose.oneoff']??null)!=='True'
+                    || ($container['Image']??null)!=='sha256:bfa9adb4f73593aaa9647cd339ecd5e6103827bce63267618a4ec51d5db56f72') throw new RuntimeException('Owned generator cleanup labels/identity mismatch');
+                if($call([...$docker,'rm','-f',$generator])['status']!=='copied') $phase['failures'][]='Exact generator removal unavailable; own down still attempted';
+            }
+        }
+        if($call([...$compose,'--profile','generator','down','--timeout','2'],5)['status']!=='copied') throw new RuntimeException('Own project cleanup failed');
+        if(trim($read([...$docker,'ps','-aq','--no-trunc','--filter','label=com.docker.compose.project='.$project]))!=='') throw new RuntimeException('Own project cleanup left container resources');
+        $network=trim($read([...$docker,'network','ls','-q','--no-trunc','--filter','label=com.docker.compose.project='.$project]));
+        if($network!=='') {
+            if(!preg_match('/^[a-f0-9]{64}$/D',$network)) throw new RuntimeException('Exact single owned network ID required');
+            $inventory=json_decode($read([...$docker,'network','inspect',$network]),true,512,JSON_THROW_ON_ERROR); $row=$inventory[0]??[];
+            if(count($inventory)!==1 || ($row['Id']??null)!==$network || ($row['Labels']['com.docker.compose.project']??null)!==$project
+                || ($row['Labels']['com.docker.compose.network']??null)!=='pilot' || !isset($row['Containers']) || $row['Containers']!==[]) throw new RuntimeException('Owned network identity/labels/empty membership mismatch');
+            if($call([...$docker,'network','rm',$network])['status']!=='copied'
+                || trim($read([...$docker,'network','ls','-q','--no-trunc','--filter','label=com.docker.compose.project='.$project]))!=='') throw new RuntimeException('Exact owned empty network removal failed');
+        }
+        $phase['final_empty']=true;
+        if($phase['failures']) throw new RuntimeException('Generator unavailable/disappeared; own cleanup attempted, failure retained');
+        $phase['status']='complete';
+    } catch(Throwable $error) { $phase['status']='failed'; $phase['failures'][]=$error->getMessage(); throw $error; }
+    finally { $phase['finished_monotonic']=hrtime(true)/1e9; pilotWriteJson($log.'.phase.json',$phase); }
+}
+
 function pilotObserve(array $cell,string $directory,PilotBudget $budget,bool $preflight): array {
     mkdir($directory,0770,true);
     $runtime=$cell['runtime']; $revision=$cell['revision'];
@@ -240,8 +300,9 @@
             pilotVerifyLocalDocker();
             if ($nativeStarted && !$salvaged) { $salvaged=true; pilotNativeSalvage($docker,$generator,$project,$directory,hrtime(true)/1e9+min(12,max(0,$budget->remaining()-5))); }
             if ($nativeStarted) pilotWriteJson($directory.'/child.json',['exit'=>$exit,'project'=>$project,'failure'=>$failure]);
-            $cleanup=pilotChild([...$compose,'--profile','generator','down','--timeout','2'],$directory.'/cleanup.log',min(5,$budget->remaining()));
-            if ($cleanup!==0) throw new RuntimeException('Own project cleanup failed');
+            // Discovery/verification/removal/down/empty checks share the original
+            // five-second cleanup phase, not a new allowance beyond22seconds.
+            pilotOwnCleanup($compose,$docker,$project,$generator,$directory.'/cleanup.log',hrtime(true)/1e9+min(5,$budget->remaining()));
         } }
         catch(Throwable $error) { pilotWriteJson($directory.'/cleanup-failure.json',['reason'=>$error->getMessage(),'project'=>$project]); throw $error; }
         finally { pcntl_sigprocmask(SIG_SETMASK,$previousMask); }
```

## A/pilot/tests/driver.php
SHA256 b8bb535285ebc201c5cd56554ed55d65332da7f0bbe1ae2f0a05f582e3c65126

```diff
--- original-executed/pilot/tests/driver.php
+++ post-data-cleanup/pilot/tests/driver.php
@@ -81,7 +81,7 @@
 pilotWriteJson($fixture.'/LABEL.json',['evidence_kind'=>'SYNTHETIC TEST ONLY, zero HTTP; not measurement raw']);
 $inventory=[];
 foreach(['php-fpm'=>[str_repeat('a',64),4000000000,2147483648],'generator'=>[str_repeat('b',64),2000000000,1073741824],'app-fpm'=>[str_repeat('c',64),1000000000,268435456]] as $service=>[$id,$cpu,$memory])
-    $inventory[]=['Id'=>$id,'Image'=>$service==='php-fpm'?'sha256:4102d15e94a811c89a440ee1be462c641c83ef30660d3efa70340f2ec9e4f144':'sha256:'.str_repeat('d',64),'Config'=>['Labels'=>['com.docker.compose.project'=>'unused-until-up','com.docker.compose.service'=>$service]],'HostConfig'=>['NanoCpus'=>$cpu,'Memory'=>$memory],'State'=>['Running'=>true,'OOMKilled'=>false,'StartedAt'=>'2026-10-06T00:00:00Z'],'RestartCount'=>0];
+    $inventory[]=['Id'=>$id,'Image'=>$service==='php-fpm'?'sha256:4102d15e94a811c89a440ee1be462c641c83ef30660d3efa70340f2ec9e4f144':($service==='generator'?'sha256:bfa9adb4f73593aaa9647cd339ecd5e6103827bce63267618a4ec51d5db56f72':'sha256:'.str_repeat('d',64)),'Config'=>['Labels'=>['com.docker.compose.project'=>'unused-until-up','com.docker.compose.service'=>$service,'com.docker.compose.oneoff'=>$service==='generator'?'True':'False']],'HostConfig'=>['NanoCpus'=>$cpu,'Memory'=>$memory],'State'=>['Running'=>true,'OOMKilled'=>false,'StartedAt'=>'2026-10-06T00:00:00Z'],'RestartCount'=>0];
 pilotWriteJson($fixture.'/inventory.json',$inventory); pilotWriteJson($fixture.'/verification.json',['runtime'=>'fpm','revision'=>'baseline']);
 [$native,$nativePid]=pilotStartChild([PHP_BINARY,'-r','sleep(10);'],$fixture.'/native.log');
 putenv('PATH='.$dir.':'.$savedPath); putenv('PILOT_TEST_LOG='.$fixture.'/calls.jsonl'); putenv('PILOT_TEST_MODE=interrupt'); putenv('PILOT_TEST_FIXTURE='.$fixture); putenv('PILOT_TEST_PARENT='.getmypid()); putenv('PILOT_TEST_NATIVE_PID='.$nativePid);
@@ -91,13 +91,75 @@
 catch(RuntimeException $error) { file_put_contents($fixture.'/caught.txt',$error->getMessage()); $nativeSurvived=proc_get_status($native)['running']; }
 finally { putenv('PATH='.$savedPath); foreach(['PILOT_TEST_LOG','PILOT_TEST_MODE','PILOT_TEST_FIXTURE','PILOT_TEST_PARENT','PILOT_TEST_NATIVE_PID'] as $key) putenv($key); pcntl_signal(SIGUSR1,SIG_DFL); $budget->close(); pilotStopChild($native,$nativePid); }
 assert(is_file($fixture.'/output/k6-raw.json.gz'),'Signal lost already-copyable synthetic partial raw before down; fixture '.$fixture);
+assert(!is_file($fixture.'/generator-present')&&is_file($fixture.'/network-removed'),'Observed cleanup regression: own down returned0 but left sleep600 generator and busy network');
 assert($nativeSurvived===false,'Native synthetic workload still running before test fallback cleanup');
 assert(file_get_contents($fixture.'/output/k6-raw.json.gz')===file_get_contents($fixture.'/raw-lost-on-removal.gz'),'Salvaged partial raw content changed');
 $calls=pilotJsonLines($fixture.'/calls.jsonl'); $stops=[];$copies=[];$downs=[];
-foreach($calls as $index=>$call) { if(in_array('pilot-owned-native-stop',$call,true))$stops[]=$index; if(in_array('exec',$call,true)&&in_array('cat',$call,true))$copies[]=$index; if(in_array('down',$call,true))$downs[]=$index; }
-assert(count($stops)===1 && count($copies)===2 && count($downs)===1 && $stops[0]<$copies[0] && max($copies)<$downs[0],'Native stop → independent artifact salvage → own down ordering failed');
+foreach($calls as $index=>$call) { if(in_array('pilot-owned-native-stop',$call,true))$stops[]=$index; if(in_array('exec',$call,true)&&in_array('cat',$call,true))$copies[]=$index; if(in_array('down',$call,true))$downs[]=$index; if(in_array('rm',$call,true))$removal=$index; }
+assert(count($stops)===1 && count($copies)===2 && count($downs)===1 && $stops[0]<$copies[0] && max($copies)<$removal && $removal<$downs[0],'Native stop → independent artifact salvage → exact generator removal → own down ordering failed');
 assert(!posix_kill($nativePid,0),'Owned native synthetic process survived cleanup');
 assert(pilotReadJson($fixture.'/output/artifacts.json')['summary']['status']==='unavailable','Missing abort summary was not explicitly reported');
 assert(is_file($fixture.'/output/k6-summary.json.partial')&&!file_exists($fixture.'/output/k6-summary.json'),'Missing summary received canonical artifact name');
 assert(pilotReadJson($fixture.'/output/artifacts.json')['raw']['sha256']===hash_file('sha256',$fixture.'/raw-lost-on-removal.gz'),'Salvage status lacks exact retained byte SHA');
+// Failure after run-d but before inventory assigns the generator ID.
+$early=$dir.'/before-inventory'; mkdir($early); pilotWriteJson($early.'/inventory.json',$inventory);
+putenv('PATH='.$dir.':'.$savedPath); putenv('PILOT_TEST_LOG='.$early.'/calls.jsonl'); putenv('PILOT_TEST_MODE=before-inventory'); putenv('PILOT_TEST_FIXTURE='.$early);
+$budget=new PilotBudget($dir.'/before-inventory-budget.json','synthetic',$dir,$build,fn()=>1000.0);
+try { pilotObserve(pilotSchedule()[0],$early.'/output',$budget,true); throw new LogicException('Failed inventory returned success'); }
+catch(RuntimeException $error) { assert(str_contains($error->getMessage(),'17')); }
+finally { putenv('PATH='.$savedPath); foreach(['PILOT_TEST_LOG','PILOT_TEST_MODE','PILOT_TEST_FIXTURE'] as $key) putenv($key); $budget->close(); }
+assert(!is_file($early.'/generator-present')&&is_file($early.'/network-removed'),'Pre-inventory failure left created one-off/network');
+$calls=pilotJsonLines($early.'/calls.jsonl');
+assert(count(array_filter($calls,fn(array $call):bool=>in_array('label=com.docker.compose.service=generator',$call,true)&&in_array('label=com.docker.compose.oneoff=True',$call,true)))===1,'Unknown generator was not resolved by exact project/service/oneoff labels');
+assert(!array_filter($calls,fn(array $call):bool=>in_array('pilot-owned-k6',$call,true)),'Inventory failure launched native workload');
+foreach(['success','discovery','foreign-project','reused-role','mismatched-id','ambiguous','leftover','deadline'] as $case) {
+    $model=$dir.'/cleanup-'.$case; mkdir($model); $project='mezzio-pilot-synthetic-cleanup';
+    $rows=$inventory; foreach($rows as &$row) $row['Config']['Labels']['com.docker.compose.project']=$project; unset($row);
+    if($case==='foreign-project') $rows[1]['Config']['Labels']['com.docker.compose.project']='mezzio-pilot-foreign';
+    if($case==='reused-role') $rows[1]['Config']['Labels']['com.docker.compose.service']='php-fpm';
+    if($case==='mismatched-id') $rows[1]['Id']=str_repeat('e',64);
+    if(in_array($case,['ambiguous','leftover'],true)) file_put_contents($model.'/'.$case,'synthetic boundary failure');
+    pilotWriteJson($model.'/inventory.json',$rows); file_put_contents($model.'/project',$project); file_put_contents($model.'/generator-present','sleep600'); file_put_contents($model.'/foreign-untouched','unrelated sentinel');
+    putenv('PATH='.$dir.':'.$savedPath); putenv('PILOT_TEST_LOG='.$model.'/calls.jsonl'); putenv('PILOT_TEST_MODE=cleanup'); putenv('PILOT_TEST_FIXTURE='.$model);
+    $success=false;
+    try { pilotOwnCleanup(pilotCompose($project,'fpm','baseline'),pilotDockerCommand(),$project,in_array($case,['discovery','ambiguous'],true)?null:str_repeat('b',64),$model.'/cleanup.log',hrtime(true)/1e9+($case==='deadline'?-1:5)); $success=true; }
+    catch(RuntimeException $error) {}
+    finally { putenv('PATH='.$savedPath); foreach(['PILOT_TEST_LOG','PILOT_TEST_MODE','PILOT_TEST_FIXTURE'] as $key) putenv($key); }
+    assert($success===in_array($case,['success','discovery'],true),'Owned cleanup case incorrectly accepted/rejected: '.$case);
+    assert(file_get_contents($model.'/foreign-untouched')==='unrelated sentinel','Cleanup touched unrelated resource');
+    $calls=is_file($model.'/calls.jsonl')?pilotJsonLines($model.'/calls.jsonl'):[];
+    if(in_array($case,['foreign-project','reused-role','mismatched-id','ambiguous','deadline'],true)) assert(!array_filter($calls,fn(array $call):bool=>in_array('rm',$call,true)||in_array('down',$call,true))&&is_file($model.'/generator-present'),'Rejected identity/deadline mutated resources');
+    if($success) assert(!is_file($model.'/generator-present')&&is_file($model.'/network-removed'),'Successful cleanup left resources');
+    foreach($calls as $call) if(in_array('rm',$call,true)) assert(end($call)===str_repeat('b',64)&&in_array('-f',$call,true),'Cleanup removal was not exact full-ID scoped');
+}
+$reviewFailures=[];
+foreach(['near-expiry-capture','term-ignoring-down','gone-before-inspect','gone-before-rm','network-only','foreign-network','busy-network','network-id-mismatch'] as $case) {
+    $model=$dir.'/review-'.$case; mkdir($model); $project='mezzio-pilot-synthetic-review';
+    $rows=$inventory; foreach($rows as &$row) $row['Config']['Labels']['com.docker.compose.project']=$project; unset($row);
+    pilotWriteJson($model.'/inventory.json',$rows); file_put_contents($model.'/project',$project); file_put_contents($model.'/foreign-untouched','unrelated sentinel');
+    if($case!=='gone-before-inspect') file_put_contents($model.'/generator-present','sleep600');
+    $marker=match($case){'near-expiry-capture'=>'slow-inspect','term-ignoring-down'=>'slow-down','gone-before-rm'=>'disappear-at-rm',default=>$case};
+    file_put_contents($model.'/'.$marker,'review boundary');
+    if(in_array($case,['foreign-network','busy-network','network-id-mismatch'],true)) file_put_contents($model.'/network-only','leftover network');
+    putenv('PATH='.$dir.':'.$savedPath); putenv('PILOT_TEST_LOG='.$model.'/calls.jsonl'); putenv('PILOT_TEST_MODE=cleanup'); putenv('PILOT_TEST_FIXTURE='.$model);
+    $phase=$case==='near-expiry-capture'?.3:5; $started=hrtime(true)/1e9; $success=false;
+    try { pilotOwnCleanup(pilotCompose($project,'fpm','baseline'),pilotDockerCommand(),$project,str_repeat('b',64),$model.'/cleanup.log',$started+$phase); $success=true; }
+    catch(RuntimeException $error) {}
+    finally { putenv('PATH='.$savedPath); foreach(['PILOT_TEST_LOG','PILOT_TEST_MODE','PILOT_TEST_FIXTURE'] as $key) putenv($key); }
+    $elapsed=hrtime(true)/1e9-$started; $calls=pilotJsonLines($model.'/calls.jsonl');
+    $downs=array_filter($calls,fn(array $call):bool=>in_array('down',$call,true));
+    $networkRemovals=array_filter($calls,fn(array $call):bool=>in_array('network',$call,true)&&in_array('rm',$call,true));
+    $good=file_get_contents($model.'/foreign-untouched')==='unrelated sentinel';
+    if(in_array($case,['near-expiry-capture','term-ignoring-down'],true)) $good=$good&&$elapsed<=$phase&&!$success&&!is_file($model.'/late-write');
+    elseif(in_array($case,['gone-before-inspect','gone-before-rm'],true)) {
+        $phaseProof=is_file($model.'/cleanup.log.phase.json')?pilotReadJson($model.'/cleanup.log.phase.json'):[];
+        $good=$good&&count($downs)===1&&is_file($model.'/network-removed')&&!$success&&!empty($phaseProof['failures']);
+    } elseif($case==='network-only') $good=$good&&$success&&count($networkRemovals)===1&&is_file($model.'/network-removed');
+    else $good=$good&&!$success&&count($networkRemovals)===0&&!is_file($model.'/network-removed');
+    if(is_file($model.'/slow-child.pid')) { $child=(int)file_get_contents($model.'/slow-child.pid'); $stat=@file_get_contents('/proc/'.$child.'/stat'); $good=$good&&($stat===false||explode(' ',substr($stat,strrpos($stat,')')+2))[0]==='Z'); }
+    echo ($good?'PASS':'RED')." cleanup review $case elapsed=$elapsed down=".count($downs)." network_rm=".count($networkRemovals)." fixture=$model\n";
+    if(!$good) $reviewFailures[]=$case;
+}
+if($reviewFailures) { fwrite(STDERR,'FAIL cleanup review boundaries: '.implode(',',$reviewFailures)."\n"); exit(1); }
+echo "PASS owned cleanup: observed one-off/network regression, abort salvage→exact rm→down, pre-inventory discovery, foreign/reused/ambiguous identity refusal, empty checks and cumulative deadline; zero HTTP\n";
 echo "PASS inert driver: six-cell readiness gates, failure stops/skips all escalation, partial retention, bounded child exit/timeout; zero HTTP\n";
```

## A/pilot/tests/fake-docker.php
SHA256 77ab4dfb4b09cf2b05579810a83664b87d14aa9258c1073a8d2ae33d5ac740ab

```diff
--- original-executed/pilot/tests/fake-docker.php
+++ post-data-cleanup/pilot/tests/fake-docker.php
@@ -3,13 +3,31 @@
 declare(strict_types=1);
 // Inert CLI boundary for the existing-project cleanup refusal test. No socket.
 file_put_contents(getenv('PILOT_TEST_LOG'),json_encode($argv)."\n",FILE_APPEND);
-if (getenv('PILOT_TEST_MODE')==='interrupt') {
+if(in_array(getenv('PILOT_TEST_MODE'),['interrupt','before-inventory','cleanup'],true)) {
     $fixture=getenv('PILOT_TEST_FIXTURE');
     if (in_array('info',$argv,true)) { echo file_get_contents(__DIR__.'/../runtime/daemon.json'); exit(0); }
     if (in_array('up',$argv,true)) { file_put_contents($fixture.'/project',$argv[array_search('--project-name',$argv,true)+1]); exit(0); }
-    if (in_array('compose',$argv,true) && in_array('run',$argv,true)) exit(0);
-    if (in_array('ps',$argv,true)) { if(is_file($fixture.'/project')) echo str_repeat('a',64),' ',str_repeat('b',64),' ',str_repeat('c',64),"\n"; exit(0); }
-    if (in_array('inspect',$argv,true)) { $inventory=json_decode(file_get_contents($fixture.'/inventory.json'),true); foreach($inventory as &$row)$row['Config']['Labels']['com.docker.compose.project']=trim(file_get_contents($fixture.'/project')); echo json_encode($inventory); exit(0); }
+    if (in_array('compose',$argv,true) && in_array('run',$argv,true)) { file_put_contents($fixture.'/generator-present','sleep600 one-off'); exit(0); }
+    if (in_array('ps',$argv,true)) {
+        if(is_file($fixture.'/network-removed')) { if(is_file($fixture.'/leftover')) echo str_repeat('f',64),"\n"; exit(0); }
+        if(in_array('label=com.docker.compose.service=generator',$argv,true)) { if(is_file($fixture.'/generator-present')) echo str_repeat('b',64),is_file($fixture.'/ambiguous')?"\n".str_repeat('e',64):'',"\n"; exit(0); }
+        if(is_file($fixture.'/app-removed')) { if(is_file($fixture.'/generator-present')) echo str_repeat('b',64),"\n"; exit(0); }
+        if(is_file($fixture.'/project')) echo str_repeat('a',64),' ',str_repeat('b',64),' ',str_repeat('c',64),"\n"; exit(0);
+    }
+    if (in_array('network',$argv,true) && in_array('ls',$argv,true)) { if(!is_file($fixture.'/network-removed')) echo str_repeat('d',64),"\n"; exit(0); }
+    if (in_array('inspect',$argv,true)) {
+        if(in_array('network',$argv,true)) {
+            echo json_encode([['Id'=>is_file($fixture.'/network-id-mismatch')?str_repeat('e',64):str_repeat('d',64),'Labels'=>['com.docker.compose.project'=>is_file($fixture.'/foreign-network')?'mezzio-pilot-foreign':trim(file_get_contents($fixture.'/project')),'com.docker.compose.network'=>'pilot'],'Containers'=>is_file($fixture.'/busy-network')?[str_repeat('f',64)=>['Name'=>'foreign']]:[]]]); exit(0);
+        }
+        if(is_file($fixture.'/slow-inspect')) { pcntl_async_signals(true); pcntl_signal(SIGTERM,SIG_IGN); file_put_contents($fixture.'/slow-child.pid',(string)getmypid()); usleep(2000000); file_put_contents($fixture.'/late-write','forbidden after deadline'); }
+        $ids=array_slice($argv,array_search('inspect',$argv,true)+1);
+        if(count($ids)===1&&!is_file($fixture.'/generator-present')) { fwrite(STDERR,'No such container'); exit(1); }
+        if(getenv('PILOT_TEST_MODE')==='before-inventory'&&count($ids)>1) exit(17);
+        $inventory=json_decode(file_get_contents($fixture.'/inventory.json'),true);
+        foreach($inventory as &$row) if(getenv('PILOT_TEST_MODE')!=='cleanup') $row['Config']['Labels']['com.docker.compose.project']=trim(file_get_contents($fixture.'/project')); unset($row);
+        if(count($ids)===1) $inventory=[$inventory[1]];
+        echo json_encode($inventory); exit(0);
+    }
     if (in_array('/app/pilot/runtime/verify.php',$argv,true)) { echo file_get_contents($fixture.'/verification.json'); exit(0); }
     if (in_array('exec',$argv,true) && (in_array('k6',$argv,true) || in_array('pilot-owned-k6',$argv,true))) {
         file_put_contents($fixture.'/native-raw.json.gz',gzencode("{\"evidence_kind\":\"synthetic_partial_no_http\"}\n"));
@@ -21,15 +39,27 @@
         if($source==='/tmp/pilot-raw.json.gz' && is_file($fixture.'/native-raw.json.gz')) { echo file_get_contents($fixture.'/native-raw.json.gz'); exit(0); }
         fwrite(STDERR,'Synthetic missing summary'); exit(1); // Raw remains independently readable.
     }
-    if (in_array('down',$argv,true)) {
-        posix_kill(-(int)getenv('PILOT_TEST_NATIVE_PID'),SIGTERM);
+    if(in_array('network',$argv,true)&&in_array('rm',$argv,true)) { if(end($argv)!==str_repeat('d',64)) exit(17); file_put_contents($fixture.'/network-removed','exact owned empty network'); exit(0); }
+    if(in_array('rm',$argv,true)) {
+        if(end($argv)!==str_repeat('b',64)||!in_array('-f',$argv,true)) exit(17);
+        if(is_file($fixture.'/disappear-at-rm')) { unlink($fixture.'/generator-present'); fwrite(STDERR,'No such container'); exit(1); }
+        unlink($fixture.'/generator-present'); file_put_contents($fixture.'/generator-removed','exact owned one-off');
         if(is_file($fixture.'/native-raw.json.gz')) rename($fixture.'/native-raw.json.gz',$fixture.'/raw-lost-on-removal.gz');
         exit(0);
     }
+    if (in_array('down',$argv,true)) {
+        if(is_file($fixture.'/slow-down')) { pcntl_async_signals(true); pcntl_signal(SIGTERM,SIG_IGN); file_put_contents($fixture.'/slow-child.pid',(string)getmypid()); sleep(10); file_put_contents($fixture.'/late-write','forbidden after deadline'); }
+        file_put_contents($fixture.'/app-removed','only own app');
+        if((int)getenv('PILOT_TEST_NATIVE_PID')>1) posix_kill(-(int)getenv('PILOT_TEST_NATIVE_PID'),SIGTERM);
+        if(is_file($fixture.'/generator-present')) fwrite(STDERR,'Network own_pilot Resource is still in use');
+        elseif(!is_file($fixture.'/network-only')) file_put_contents($fixture.'/network-removed','own empty network');
+        exit(0);
+    }
     exit(99);
 }
 if (in_array('info',$argv,true)) { echo file_get_contents(__DIR__.'/../runtime/daemon.json'); exit(0); }
 if (in_array('ps',$argv,true)) { if (getenv('PILOT_TEST_MODE')!=='partial') echo str_repeat('a',64),"\n"; exit(0); }
 if (in_array('up',$argv,true) && getenv('PILOT_TEST_MODE')==='partial') exit(17);
 if (in_array('down',$argv,true)) exit(0);
+if (in_array('network',$argv,true) && in_array('ls',$argv,true) && getenv('PILOT_TEST_MODE')==='partial') exit(0);
 fwrite(STDERR,"Unexpected synthetic command\n"); exit(99);
```

Unexecuted follow-up archive SHA256 562add0edc792a53816278084325c150a925a6cd6974626a1b2b0b46fb364b92
Unexecuted follow-up manifest SHA256 10504c02a89e5d7e1b22fa157be2d9308cd3e795e3051859937a6e9840ef7c2b
Original executed archive/manifest/raw remain immutable. No retry; post-data flags remain preparation true, replay/native false.
