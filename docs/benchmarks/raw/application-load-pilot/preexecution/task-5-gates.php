<?php
declare(strict_types=1);
// Task5 empirical zero-HTTP start gates; outside the accepted replay roots.
require '/home/sirix/PhpStormProjects/mezzio-valinor-request-mapper/.worktrees/mezzio-load-app/pilot/run.php';
const APP='/home/sirix/PhpStormProjects/mezzio-valinor-request-mapper/.worktrees/mezzio-load-app';
const OUTPUT='/home/sirix/PhpStormProjects/mezzio-valinor-request-mapper/.worktrees/mezzio-hardening/docs/benchmarks/raw/application-load-pilot';
$directory=__DIR__.'/task5-gates';
if (!is_dir($directory)) mkdir($directory,0770,true);
$events=is_file($directory.'/events.json')?pilotReadJson($directory.'/events.json'):[];
$nativeDeadline=null; $nativeCleanup=false;
function gateCommand(array $argv,float $seconds=10,?int $expected=0): string {
    global $directory,$events,$nativeDeadline,$nativeCleanup;
    if($nativeDeadline!==null) {
        $remaining=$nativeDeadline-hrtime(true)/1e9-($nativeCleanup?0:5);
        if($remaining<=0) throw new RuntimeException('Native gate has only its cleanup reserve remaining');
        $seconds=min($seconds,$remaining);
    }
    $index=count($events); $path=$directory.'/command-'.$index;
    $started=hrtime(true)/1e9; $utc=gmdate('c');
    $process=proc_open(['timeout','--signal=TERM','--kill-after=1s',(string)$seconds.'s',...$argv],
        [0=>['file','/dev/null','r'],1=>['file',$path.'.stdout','w'],2=>['file',$path.'.stderr','w']],$pipes,APP);
    if (!is_resource($process)) throw new RuntimeException('Cannot start gate subprocess');
    $exit=proc_close($process);
    $events[]=['argv'=>$argv,'started_utc'=>$utc,'started_monotonic'=>$started,'ended_utc'=>gmdate('c'),
        'elapsed_seconds'=>hrtime(true)/1e9-$started,'timeout_seconds'=>$seconds,'exit'=>$exit,
        'stdout'=>basename($path.'.stdout'),'stderr'=>basename($path.'.stderr')];
    pilotWriteJson($directory.'/events.json',$events);
    if ($expected!==null && $exit!==$expected) throw new RuntimeException("Gate command $index exit$exit (expected$expected)");
    return file_get_contents($path.'.stdout');
}
function gateAssert(bool $ok,string $reason): void { if (!$ok) throw new RuntimeException($reason); }
function gateStream(array $argv,string $output,int $cap,?string $input=null): array {
    global $directory,$events,$nativeDeadline;
    $started=hrtime(true)/1e9; $limit=min(4,$nativeDeadline-$started-5);
    $proof=pilotStreamChild($argv,$directory.'/stream-stderr.log',$limit,$output,$cap,$input);
    $events[]=['argv'=>$argv,'started_utc'=>gmdate('c',(int)microtime(true)),'started_monotonic'=>$started,'elapsed_seconds'=>hrtime(true)/1e9-$started,'timeout_seconds'=>$limit,'stream'=>$proof,'input_bytes'=>$input===null?0:strlen($input),'input_sha256'=>$input===null?null:hash('sha256',$input)];
    pilotWriteJson($directory.'/events.json',$events); return $proof;
}
function gateInventory(string $project): array {
    $ids=trim(gateCommand([...pilotDockerCommand(),'ps','-q','--no-trunc','--filter','label=com.docker.compose.project='.$project]));
    gateAssert($ids!=='','Own project has no running containers');
    return json_decode(gateCommand([...pilotDockerCommand(),'inspect',...preg_split('/\s+/',$ids)]),true,512,JSON_THROW_ON_ERROR);
}
function gateLaunch(): string {
    // Use the accepted production wrapper verbatim, not another signal implementation.
    preg_match('/\$launch=<<<\x27SH\x27\n(.*?)\nSH;/s',file_get_contents(APP.'/pilot/run.php'),$match);
    gateAssert(isset($match[1]),'Accepted native wrapper not found'); return $match[1];
}
function gateReplay(string $archive,array $files): array {
    $replay=trim(gateCommand(['mktemp','-d','/tmp/mezzio-pilot-task5-replay-XXXXXX']));
    gateAssert(str_starts_with($replay,'/tmp/mezzio-pilot-task5-replay-'),'Unexpected replay root');
    $listing=explode("\n",rtrim(gateCommand(['tar','-tf',$archive],15),"\n"));
    sort($listing); gateAssert($listing===array_keys($files),'Replay archive inventory mismatch');
    gateCommand(['tar','--no-same-owner','--no-same-permissions','-xf',$archive,'-C',$replay],15);
    $actual=[];
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($replay,FilesystemIterator::SKIP_DOTS)) as $file) {
        gateAssert($file->isFile()&&!$file->isLink(),'Replay contains a non-regular file');
        $actual[substr($file->getPathname(),strlen($replay)+1)]=hash_file('sha256',$file->getPathname());
    }
    ksort($actual); gateAssert($actual===$files,'Clean replay content mismatch');
    return ['status'=>'passed','directory'=>$replay,'regular_files'=>count($actual),'archive_sha256'=>hash_file('sha256',$archive),'file_manifest_sha256'=>hash('sha256',json_encode($actual,JSON_UNESCAPED_SLASHES)),'http_requests'=>0];
}
try {
    $mode=$argv[1]??'';
    if ($mode==='images') {
        $daemon=json_decode(gateCommand([...pilotDockerCommand(),'info','--format','{"ID":{{json .ID}},"Name":{{json .Name}},"DockerRootDir":{{json .DockerRootDir}},"NCPU":{{json .NCPU}},"MemTotal":{{json .MemTotal}},"Architecture":{{json .Architecture}}}'],15),true,512,JSON_THROW_ON_ERROR);
        verifyDaemon($daemon); gateAssert($daemon['NCPU']===14&&$daemon['MemTotal']===27316473856,'Current frozen capacity mismatch');
        $manifest=pilotManifest(); $images=[];
        foreach(['app'=>['mezzio-pilot-app:task3',$manifest['serving_image_config_id']],
            'generator'=>[$manifest['versions']['images']['k6']['reference'],'sha256:bfa9adb4f73593aaa9647cd339ecd5e6103827bce63267618a4ec51d5db56f72'],
            'gateway'=>[$manifest['versions']['images']['gateway']['reference'],'sha256:34e04bb6b4bb37d45845842374be0cd181723daffb230849b1984aaeaa96faba']] as $role=>[$reference,$id]) {
            $image=json_decode(gateCommand([...pilotDockerCommand(),'image','inspect',$reference,'--format','{"Id":{{json .Id}},"RepoDigests":{{json .RepoDigests}},"Os":{{json .Os}},"Architecture":{{json .Architecture}}}']),true,512,JSON_THROW_ON_ERROR);
            gateAssert($image['Id']===$id&&$image['Os']==='linux'&&$image['Architecture']==='amd64',"Pinned $role image/platform mismatch"); $images[$role]=['reference'=>$reference,...$image];
        }
        pilotWriteJson($directory.'/images.json',['status'=>'passed','captured_utc'=>gmdate('c'),'daemon'=>$daemon,'images'=>$images,'http_requests'=>0]);
    } elseif ($mode==='replay') {
        $old=__DIR__.'/task4-dryrun'; $manifest=pilotReadJson($old.'/manifest.json');
        gateAssert(hash_file('sha256',$old.'/app-replay.tar')==='15734c7a4bce6a10615365b85dca7a195bba2950fd4f2ae011a50fbc370e1efb','Accepted preparation archive changed');
        gateAssert(hash_file('sha256',$old.'/manifest.json')==='2f71e0d28bda837b1c4aedd9d82ee08ad4aa228a77c630809b9a25566d86bf46','Accepted preparation manifest changed');
        gateAssert(pilotManifestIssues($manifest)===[],'Accepted app source drift');
        pilotWriteJson($directory.'/replay.json',gateReplay($old.'/app-replay.tar',$manifest['files']));
    } elseif ($mode==='native') {
        gateAssert((pilotReadJson($directory.'/images.json')['status']??'')==='passed','Current image gate missing');
        foreach(['native.json','native-current.json','native-inventory.json','failure-native.json'] as $prior) {
            if(is_file($directory.'/'.$prior)) {
                $previous=$directory.'/prior-'.count($events).'-'.$prior;
                gateAssert(!file_exists($previous),'Prior native attempt already retained');
                gateAssert(rename($directory.'/'.$prior,$previous),'Cannot preserve prior native attempt');
            }
        }
        $project='mezzio-pilot-task5-gate-'.bin2hex(random_bytes(6));
        $compose=pilotCompose($project,'fpm','candidate'); $docker=pilotDockerCommand(); $owns=false; $child=null; $generator=null;
        $gateStarted=hrtime(true)/1e9; $abortDeadline=$nativeDeadline=$gateStarted+30;
        $proof=['project'=>$project,'http_requests'=>0,'wrapper_source_sha256'=>hash_file('sha256',APP.'/pilot/run.php'),'probe_sha256'=>hash_file('sha256',__DIR__.'/task5-native-abort.js')];
        pilotWriteJson($directory.'/native-current.json',$proof);
        try {
            gateAssert(trim(gateCommand([...$docker,'ps','-aq','--filter','label=com.docker.compose.project='.$project]))==='','Refusing existing native gate project');
            $owns=true;
            gateCommand([...$compose,'--profile','generator','run','-d','--no-deps','--pull','never','--entrypoint','sh','generator','-c','sleep 600']);
            $inventory=gateInventory($project); gateAssert(count($inventory)===1,'Only gate generator may be active');
            $generator=$inventory[0]['Id'];
            gateAssert($inventory[0]['Image']==='sha256:bfa9adb4f73593aaa9647cd339ecd5e6103827bce63267618a4ec51d5db56f72','Native generator identity drift');
            gateAssert($inventory[0]['HostConfig']['NanoCpus']===2000000000&&$inventory[0]['HostConfig']['Memory']===1073741824,'Native generator caps mismatch');
            pilotWriteJson($directory.'/native-inventory.json',$inventory);
            $utilities=gateCommand([...$docker,'exec',$generator,'sh','-c','set -eu; for x in sh awk printf mv test kill sleep k6; do command -v "$x"; done; read uptime rest < /proc/uptime; test -r /proc/$$/stat; ticks=$(awk \'{print $22}\' /proc/$$/stat); test "$ticks" -gt 0; printf "pid=%s ticks=%s uptime=%s\\n" "$$" "$ticks" "$uptime"; cat /sys/fs/cgroup/cpu.max /sys/fs/cgroup/memory.max']);
            $proof['utilities']=$utilities;
            $inspect=json_decode(file_get_contents($directory.'/command-12.stdout'),true,512,JSON_THROW_ON_ERROR);
            $options=$inspect; $scenario=$options['scenarios']['pilot'];
            gateAssert($scenario['executor']==='constant-arrival-rate'&&$scenario['rate']===10&&$scenario['preAllocatedVUs']===8&&$scenario['maxVUs']===8&&$scenario['duration']==='40s'&&$scenario['gracefulStop']==='2s','Native load options mismatch');
            $proof['native_load_inspect']=$inspect;
            $environment=['-e','PILOT_PROJECT='.$project];
            $base=[...$docker,'exec',...$environment,$generator,'sh','-c',gateLaunch(),'pilot-owned-k6','run','--no-usage-report','--log-format','raw','--out','json=/tmp/pilot-raw.json.gz','--summary-export','/tmp/pilot-summary.json'];
            $proof['probe_injection']=gateStream([...$docker,'exec','-i',$generator,'sh','-c','cat > /tmp/task5-native-abort.js'],$directory.'/'.$project.'-injection.stdout',16384,file_get_contents(__DIR__.'/task5-native-abort.js'));
            gateAssert($proof['probe_injection']['status']==='copied','Exact stdin probe injection failed');
            $proof['probe_roundtrip']=gateStream([...$docker,'exec',$generator,'cat','/tmp/task5-native-abort.js'],$directory.'/'.$project.'-probe-roundtrip.js',16384);
            gateAssert($proof['probe_roundtrip']['status']==='copied'&&$proof['probe_roundtrip']['sha256']===$proof['probe_sha256'],'Native probe byte/hash mismatch');
            $proof['native_argv']=[...$base,'/tmp/task5-native-abort.js'];
            pilotWriteJson($directory.'/native-current.json',$proof);
            $child=pilotStartChild([...$base,'/tmp/task5-native-abort.js'],$directory.'/native-abort.log');
            $proof['native_child_host_pid']=$child[1]; pilotWriteJson($directory.'/native-current.json',$proof);
            usleep(3000000);
            $pidMarker=gateCommand([...$docker,'exec',$generator,'cat','/tmp/pilot-k6.pid'],min(3,$abortDeadline-hrtime(true)/1e9-17));
            $proof['owned_pid_start_project']=$pidMarker;
            mkdir($directory.'/native-abort',0770,true);
            $artifacts=pilotNativeSalvage($docker,$generator,$project,$directory.'/native-abort',min($abortDeadline-5,hrtime(true)/1e9+12));
            $proof['native_abort_artifacts']=$artifacts;
            gateAssert($artifacts['native_stop']['status']==='stopped'&&$artifacts['raw']['status']==='copied','Native interruption stop/raw salvage unproven');
            $proof['native_child_exit']=pilotWaitChild(...[...$child,min(2,$abortDeadline-hrtime(true)/1e9-5)]); $child=null;
            $points=pilotJsonLines($directory.'/native-abort/k6-raw.json.gz'); $metricPoints=0;
            foreach($points as $point) if(($point['type']??'')==='Point') {
                gateAssert(!str_starts_with($point['metric'],'http_'),'Combined native gate unexpectedly contains HTTP metrics');
                if($point['metric']==='task5_gate_invocation_ms') {
                    $tags=$point['data']['tags'];
                    gateAssert(is_numeric($tags['scenario_start_ms'])&&is_finite((float)$tags['scenario_start_ms'])&&ctype_digit($tags['iteration'])&&is_finite((float)$point['data']['value']),'Native origin/index API invalid');
                    ++$metricPoints;
                }
            }
            gateAssert($metricPoints>0&&pilotNativeRequestCount($points)===0,'No meaningful real native zero-HTTP partial points');
            $proof['second_raw_stream']=gateStream([...$docker,'exec',$generator,'cat','/tmp/pilot-raw.json.gz'],$directory.'/native-abort/k6-raw-second-copy.json.gz',67108864);
            gateAssert($proof['second_raw_stream']['status']==='copied','Second raw stream failed');
            $hash=hash_file('sha256',$directory.'/native-abort/k6-raw.json.gz');
            gateAssert($hash===hash_file('sha256',$directory.'/native-abort/k6-raw-second-copy.json.gz'),'Salvaged native raw bytes differ before cleanup');
            $proof['native_partial_points']=$metricPoints; $proof['native_partial_raw_sha256']=$hash;
            $stopped=gateCommand([...$docker,'exec',$generator,'sh','-c','read pid ticks owner < /tmp/pilot-k6.pid; test "$owner" = "$1"; if test -f /proc/$pid/stat; then test "$(awk \'{print $22}\' /proc/$pid/stat)" = "$ticks"; test "$(awk \'{print $3}\' /proc/$pid/stat)" = Z; fi; printf "owned_native_absent_or_zombie\\n"','gate-stop-check',$project],min(3,$abortDeadline-hrtime(true)/1e9-5));
            gateAssert(str_contains($stopped,'owned_native_absent_or_zombie'),'Native process stop missing');
            // Original wrapper sees the retained stop-side latch and cannot start another native process.
            gateCommand([...$base,'/tmp/task5-native-abort.js'],min(3,$abortDeadline-hrtime(true)/1e9-5),108);
            $proof['abort_latch_exit']=108; $proof['status']='passed';
        } finally {
            if($child!==null) pilotStopChild(...$child);
            if($owns) {
                if($generator!==null&&($proof['status']??'')!=='passed') pilotNativeSalvage($docker,$generator,$project,$directory,hrtime(true)/1e9+min(12,max(0,($abortDeadline??(hrtime(true)/1e9+17))-hrtime(true)/1e9-5)));
                $nativeCleanup=true;
                $limit=$abortDeadline===null?5:min(5,max(0.1,$abortDeadline-hrtime(true)/1e9));
                gateCommand([...$compose,'--profile','generator','down','--timeout','2'],$limit);
                $proof['cleanup_utc']=gmdate('c');
                if($abortDeadline!==null) { $proof['abort_elapsed_including_cleanup_seconds']=hrtime(true)/1e9-$gateStarted; gateAssert($proof['abort_elapsed_including_cleanup_seconds']<=30,'Native abort gate deadline exceeded'); }
            }
            pilotWriteJson($directory.'/native.json',$proof);
        }
    } else throw new RuntimeException('Unknown gate mode');
    echo "PASS Task5 $mode; zero application HTTP\n";
} catch(Throwable $error) {
    pilotWriteJson($directory.'/failure-'.($mode??'unknown').'.json',['reason'=>$error->getMessage(),'utc'=>gmdate('c'),'http_requests'=>0]);
    fwrite(STDERR,"FAIL: {$error->getMessage()}\n"); exit(1);
}
