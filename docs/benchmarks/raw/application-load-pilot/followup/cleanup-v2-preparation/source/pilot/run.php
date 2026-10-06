<?php
declare(strict_types=1);
require_once __DIR__.'/freeze.php';

function pilotStopChild($process,int $pid): void {
    if (is_resource($process)) {
        @posix_kill(-$pid,SIGTERM); @proc_terminate($process);
        usleep(50000);
        @posix_kill(-$pid,SIGKILL); @proc_terminate($process,SIGKILL);
        proc_close($process);
    }
}

function pilotWaitChild($process,int $pid,float $seconds): int {
    $deadline=hrtime(true)+(int)($seconds*1e9);
    try {
        do {
            $status=proc_get_status($process);
            if (!$status['running']) { $exit=$status['exitcode']; proc_close($process); return $exit; }
            if (hrtime(true)>=$deadline) { pilotStopChild($process,$pid); return 124; }
            usleep(10000);
        } while(true);
    } catch(Throwable $error) { pilotStopChild($process,$pid); throw $error; }
}

function pilotStartChild(array $command,string $log): array {
    $process=proc_open(['setsid',...$command],[0=>['file','/dev/null','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,dirname(__DIR__));
    if (!is_resource($process)) throw new RuntimeException('Cannot start own subprocess');
    return [$process,proc_get_status($process)['pid']];
}

function pilotChild(array $command,string $log,float $seconds): int {
    if (!is_finite($seconds) || $seconds<=0) throw new RuntimeException('No remaining subprocess deadline');
    [$process,$pid]=pilotStartChild($command,$log);
    return pilotWaitChild($process,$pid,$seconds);
}

// Bounded binary transport for native tmpfs files; incomplete captures never
// receive the canonical artifact name. No Docker cp crosses a tmpfs boundary.
function pilotStreamChild(array $command,string $log,float $seconds,string $output,int $cap,?string $input=null): array {
    if(!is_finite($seconds)||$seconds<=0||$cap<1||$cap>67108864) throw new RuntimeException('Invalid stream deadline/byte cap');
    if($input!==null&&strlen($input)>16384) throw new RuntimeException('Probe exceeds input byte cap');
    $partial=$output.'.partial';
    if(file_exists($output)||file_exists($partial)) throw new RuntimeException('Refusing existing stream artifact');
    $handle=fopen($partial,'xb'); if(!$handle) throw new RuntimeException('Cannot open partial stream capture');
    $process=null; $pid=0; $pipes=[]; $bytes=0; $offset=0; $exit=null; $reason=null; $deadline=hrtime(true)/1e9+$seconds;
    try {
        $process=proc_open(['setsid',...$command],[0=>$input===null?['file','/dev/null','r']:['pipe','r'],1=>['pipe','w'],2=>['file',$log,'a']],$pipes,dirname(__DIR__));
        if(!is_resource($process)) throw new RuntimeException('Cannot start stream child');
        $pid=proc_get_status($process)['pid']; stream_set_blocking($pipes[1],false);
        if(isset($pipes[0])) stream_set_blocking($pipes[0],false);
        while(true) {
            if(hrtime(true)/1e9>=$deadline) { $reason='stream deadline exhausted'; $exit=124; break; }
            if(isset($pipes[0])) {
                $written=fwrite($pipes[0],substr($input,$offset));
                if($written===false) throw new RuntimeException('Cannot send exact probe bytes');
                $offset+=$written;
                if($offset===strlen($input)) { fclose($pipes[0]); unset($pipes[0]); }
            }
            $chunk=fread($pipes[1],min(65536,$cap-$bytes+1));
            if($chunk===false) throw new RuntimeException('Cannot read native artifact stream');
            $keep=substr($chunk,0,$cap-$bytes);
            if($keep!==''&&fwrite($handle,$keep)!==strlen($keep)) throw new RuntimeException('Cannot retain partial stream bytes');
            $bytes+=strlen($keep);
            if(strlen($chunk)>strlen($keep)) { $reason='artifact exceeds byte cap'; $exit=125; break; }
            if(feof($pipes[1])) {
                $exit=pilotWaitChild($process,$pid,max(.001,$deadline-hrtime(true)/1e9)); $process=null;
                if($exit!==0) $reason=$exit===124?'stream deadline exhausted':'stream child nonzero exit';
                break;
            }
            if($chunk==='') usleep(1000);
        }
    } catch(Throwable $error) { $reason=$error->getMessage(); throw $error; }
    finally {
        foreach($pipes as $pipe) if(is_resource($pipe)) fclose($pipe);
        if(is_resource($process)) pilotStopChild($process,$pid);
        $durable=fflush($handle)&&fsync($handle); fclose($handle);
        if(!$durable) throw new RuntimeException('Cannot durably retain partial stream capture');
    }
    if($exit===0&&$reason===null) {
        if(!rename($partial,$output)) throw new RuntimeException('Cannot promote complete stream artifact');
        return ['status'=>'copied','exit'=>0,'bytes'=>$bytes,'sha256'=>hash_file('sha256',$output),'byte_cap'=>$cap];
    }
    return ['status'=>'unavailable','exit'=>$exit,'reason'=>$reason,'partial'=>basename($partial),'bytes'=>$bytes,'sha256'=>hash_file('sha256',$partial),'byte_cap'=>$cap];
}

function pilotRunSequence(PilotBudget $budget,array &$schedule,callable $readiness,callable $window): void {
    foreach($schedule as &$cell) $cell['status']='skipped'; unset($cell);
    $budget->reservePreflight();
    foreach(['fpm'=>'http://app-fpm','roadrunner'=>'http://app-rr','swoole'=>'http://app-swoole'] as $runtime=>$target)
        foreach(['baseline','candidate'] as $revision) {
            $expected=$revision==='baseline'?15:16;
            $proof=$readiness(['runtime'=>$runtime,'target'=>$target,'revision'=>$revision]);
            pilotReadiness($proof['exit'],$proof['log'],$expected,$revision,$target,$proof['native_count']);
        }
    foreach($schedule as &$cell) {
        $cell['status']='reserved';
        try { $budget->launch($cell,static fn()=>$window($cell)); $cell['status']='valid'; }
        catch(Throwable $error) { $cell['status']='invalid'; $cell['reason']=$error->getMessage(); throw $error; }
    }
    unset($cell);
}

function pilotCompose(string $project,string $runtime,string $revision): array {
    if (!preg_match('/^mezzio-pilot-[a-z0-9-]+$/D',$project) || !in_array($runtime,['fpm','roadrunner','swoole'],true) || !in_array($revision,['baseline','candidate'],true)) throw new RuntimeException('Own project/runtime/revision required');
    return ['env','PILOT_REVISION='.$revision,...pilotDockerCommand(),'compose','--file',dirname(__DIR__).'/compose.yaml','--project-name',$project,'--profile',$runtime];
}

function pilotNativeRequestCount(array $points): int {
    $count=0;
    foreach($points as $point) if (($point['type']??'')==='Point' && ($point['metric']??'')==='http_reqs') $count+=(int)$point['data']['value'];
    return $count;
}

function pilotNativeSalvage(array $docker,string $generator,string $project,string $directory,float $deadline): array {
    if (!preg_match('/^[a-f0-9]{64}$/D',$generator) || !preg_match('/^mezzio-pilot-[a-z0-9-]+$/D',$project)) throw new RuntimeException('Exact owned generator/project required for salvage');
    // The abort latch also prevents a not-yet-exec'd wrapper from starting k6.
    // PID + start ticks + owner avoid signaling a reused or unrelated process.
    $stop=<<<'SH'
: > /tmp/pilot-k6.abort
if [ ! -f /tmp/pilot-k6.pid ]; then exit 0; fi
read pid ticks owner < /tmp/pilot-k6.pid || exit 3
case "$pid:$ticks" in *[!0-9:]*|:*|*:) exit 3;; esac
[ "$pid" -gt 1 ] && [ "$owner" = "$1" ] || exit 3
if [ ! -f "/proc/$pid/stat" ]; then exit 0; fi
[ "$(awk '{print $22}' /proc/$pid/stat)" = "$ticks" ] || exit 3
kill -INT "$pid" || exit 3
sleep 0.2
if [ -f "/proc/$pid/stat" ]; then
  [ "$(awk '{print $22}' /proc/$pid/stat)" = "$ticks" ] || exit 3
  kill -KILL "$pid" 2>/dev/null || true
  sleep 0.1
  if [ -f "/proc/$pid/stat" ] && [ "$(awk '{print $3}' /proc/$pid/stat)" != Z ]; then exit 3; fi
fi
SH;
    $artifacts=[];
    foreach(['native_stop'=>[2,[...$docker,'exec',$generator,'sh','-c',$stop,'pilot-owned-native-stop',$project]],
        'raw'=>[4,[...$docker,'exec',$generator,'cat','/tmp/pilot-raw.json.gz']],
        'summary'=>[4,[...$docker,'exec',$generator,'cat','/tmp/pilot-summary.json']]] as $name=>[$limit,$argv]) {
        $remaining=$deadline-hrtime(true)/1e9;
        try {
            if ($remaining<=0) throw new RuntimeException('Bounded salvage deadline exhausted');
            if($name==='native_stop') {
                $exit=pilotChild($argv,$directory.'/salvage.log',min($limit,$remaining));
                $artifacts[$name]=['status'=>$exit===0?'stopped':'unproven','exit'=>$exit];
            } else $artifacts[$name]=pilotStreamChild($argv,$directory.'/salvage.log',min($limit,$remaining),$directory.($name==='raw'?'/k6-raw.json.gz':'/k6-summary.json'),$name==='raw'?67108864:1048576);
        } catch(Throwable $error) {
            $artifacts[$name]=['status'=>$name==='native_stop'?'unproven':'unavailable','reason'=>$error->getMessage()];
            $partial=$directory.($name==='raw'?'/k6-raw.json.gz.partial':'/k6-summary.json.partial');
            if($name!=='native_stop'&&is_file($partial)) $artifacts[$name]+=['partial'=>basename($partial),'bytes'=>filesize($partial),'sha256'=>hash_file('sha256',$partial),'byte_cap'=>$name==='raw'?67108864:1048576];
        }
    }
    pilotWriteJson($directory.'/artifacts.json',$artifacts);
    return $artifacts;
}

function pilotOwnCleanup(array $compose,array $docker,string $project,?string $generator,string $log,float $deadline): void {
    if(!preg_match('/^mezzio-pilot-[a-z0-9-]+$/D',$project) || !in_array('--project-name',$compose,true)
        || ($compose[array_search('--project-name',$compose,true)+1]??null)!==$project) throw new RuntimeException('Own cleanup project mismatch');
    $phase=['project'=>$project,'deadline_monotonic'=>$deadline,'started_monotonic'=>hrtime(true)/1e9,'events'=>[],'failures'=>[]];
    $call=static function(array $argv,float $limit=1)use($deadline,$log,&$phase):array {
        // Existing stop waits50ms; polling/reaping/durable capture also consume
        // time. Keep100ms INSIDE this phase for all termination/teardown work.
        $seconds=min($limit,$deadline-hrtime(true)/1e9-.1);
        if($seconds<=0) throw new RuntimeException('Own cleanup phase deadline exhausted before teardown reserve');
        $capture=$log.'.command-'.(count($phase['events'])+1).'.stdout';
        $result=pilotStreamChild($argv,$log,$seconds,$capture,65536);
        $phase['events'][]=['argv'=>$argv,'capture'=>basename($capture),'result'=>$result];
        pilotWriteJson($log.'.phase.json',$phase);
        return $result+['stdout'=>$result['status']==='copied'?file_get_contents($capture):''];
    };
    $read=static function(array $argv)use($call):string {
        $result=$call($argv);
        if($result['status']!=='copied') throw new RuntimeException('Own cleanup metadata unavailable');
        return $result['stdout'];
    };
    try {
        if($generator===null) {
            $ids=trim($read([...$docker,'ps','-aq','--no-trunc','--filter','label=com.docker.compose.project='.$project,'--filter','label=com.docker.compose.service=generator','--filter','label=com.docker.compose.oneoff=True']));
            if($ids!=='') {
                $matches=preg_split('/\s+/',$ids);
                if(count($matches)!==1) throw new RuntimeException('Ambiguous own generator cleanup identity');
                $generator=$matches[0];
            }
        }
        if($generator!==null) {
            if(!preg_match('/^[a-f0-9]{64}$/D',$generator)) throw new RuntimeException('Full owned generator ID required for cleanup');
            $inspected=$call([...$docker,'inspect',$generator]);
            if($inspected['status']!=='copied') $phase['failures'][]='Generator inspect unavailable; no unverified removal';
            else {
                $inventory=json_decode($inspected['stdout'],true,512,JSON_THROW_ON_ERROR);
                $container=$inventory[0]??[]; $labels=$container['Config']['Labels']??[];
                if(count($inventory)!==1 || ($container['Id']??null)!==$generator || ($labels['com.docker.compose.project']??null)!==$project
                    || ($labels['com.docker.compose.service']??null)!=='generator' || ($labels['com.docker.compose.oneoff']??null)!=='True'
                    || ($container['Image']??null)!=='sha256:bfa9adb4f73593aaa9647cd339ecd5e6103827bce63267618a4ec51d5db56f72') throw new RuntimeException('Owned generator cleanup labels/identity mismatch');
                if($call([...$docker,'rm','-f',$generator])['status']!=='copied') $phase['failures'][]='Exact generator removal unavailable; own down still attempted';
            }
        }
        if($call([...$compose,'--profile','generator','down','--timeout','2'],5)['status']!=='copied') throw new RuntimeException('Own project cleanup failed');
        if(trim($read([...$docker,'ps','-aq','--no-trunc','--filter','label=com.docker.compose.project='.$project]))!=='') throw new RuntimeException('Own project cleanup left container resources');
        $network=trim($read([...$docker,'network','ls','-q','--no-trunc','--filter','label=com.docker.compose.project='.$project]));
        if($network!=='') {
            if(!preg_match('/^[a-f0-9]{64}$/D',$network)) throw new RuntimeException('Exact single owned network ID required');
            $inventory=json_decode($read([...$docker,'network','inspect',$network]),true,512,JSON_THROW_ON_ERROR); $row=$inventory[0]??[];
            if(count($inventory)!==1 || ($row['Id']??null)!==$network || ($row['Labels']['com.docker.compose.project']??null)!==$project
                || ($row['Labels']['com.docker.compose.network']??null)!=='pilot' || !isset($row['Containers']) || $row['Containers']!==[]) throw new RuntimeException('Owned network identity/labels/empty membership mismatch');
            if($call([...$docker,'network','rm',$network])['status']!=='copied'
                || trim($read([...$docker,'network','ls','-q','--no-trunc','--filter','label=com.docker.compose.project='.$project]))!=='') throw new RuntimeException('Exact owned empty network removal failed');
        }
        $phase['final_empty']=true;
        if($phase['failures']) throw new RuntimeException('Generator unavailable/disappeared; own cleanup attempted, failure retained');
        $phase['status']='complete';
    } catch(Throwable $error) { $phase['status']='failed'; $phase['failures'][]=$error->getMessage(); throw $error; }
    finally { $phase['finished_monotonic']=hrtime(true)/1e9; pilotWriteJson($log.'.phase.json',$phase); }
}

function pilotObserve(array $cell,string $directory,PilotBudget $budget,bool $preflight): array {
    mkdir($directory,0770,true);
    $runtime=$cell['runtime']; $revision=$cell['revision'];
    $project='mezzio-pilot-'.bin2hex(random_bytes(8));
    $compose=pilotCompose($project,$runtime,$revision); $docker=pilotDockerCommand(); $sampler=null; $generator=null; $ownsProject=false; $nativeStarted=false; $salvaged=false; $exit=null; $failure=null;
    $command=function(array $argv,float $limit=10)use($directory,$budget):void {
        $remaining=$budget->remaining()-22; // Native stop, salvage, daemon check and own down within75min.
        if ($remaining<=0) throw new RuntimeException('Execution deadline exhausted');
        $exit=pilotChild($argv,$directory.'/commands.log',min($limit,$remaining));
        if ($exit!==0) throw new RuntimeException("Own project subprocess exit $exit");
    };
    $read=function(array $argv)use($budget):string {
        $remaining=$budget->remaining()-22;
        if ($remaining<=0) throw new RuntimeException('Execution deadline leaves only own cleanup');
        return pilotCommand($argv,min(5,$remaining));
    };
    try {
        if ($budget->remaining()<66) throw new RuntimeException('Next window/readiness and bounded salvage/cleanup cannot fit remaining time');
        pilotVerifyLocalDocker();
        if (trim($read([...$docker,'ps','-aq','--filter','label=com.docker.compose.project='.$project]))!=='') throw new RuntimeException('Refusing existing project');
        $ownsProject=true; // An up failure can still leave this new project partial.
        $command([...$compose,'up','-d','--no-build','--pull','never']);
        // Fixed process settling pause only; no HTTP health polling/retries.
        usleep(500000);
        $command([...$compose,'--profile','generator','run','-d','--no-deps','--pull','never','--entrypoint','sh','generator','-c','sleep 600']);
        $ids=trim($read([...$docker,'ps','-q','--no-trunc','--filter','label=com.docker.compose.project='.$project]));
        $inventory=json_decode($read([...$docker,'inspect',...preg_split('/\s+/',$ids)]),true,512,JSON_THROW_ON_ERROR);
        $app=null; $gateway=null;
        foreach($inventory as $container) {
            $service=$container['Config']['Labels']['com.docker.compose.service']??'';
            if (($container['Config']['Labels']['com.docker.compose.project']??'')!==$project || !$container['State']['Running'] || $container['State']['OOMKilled'] || $container['RestartCount']!==0) throw new RuntimeException('Project identity/restart/OOM mismatch');
            $expectedCpu=$service==='generator'?2000000000:($runtime==='fpm' && $service==='app-fpm'?1000000000:4000000000);
            $expectedMemory=$service==='generator'?1073741824:($runtime==='fpm' && $service==='app-fpm'?268435456:2147483648);
            if ($container['HostConfig']['NanoCpus']!==$expectedCpu || $container['HostConfig']['Memory']!==$expectedMemory) throw new RuntimeException('Actual container resource ceilings mismatch');
            if ($service==='generator') $generator=$container['Id'];
            elseif ($runtime==='fpm' && $service==='app-fpm') $gateway=$container['Id'];
            elseif ($service===match($runtime){'fpm'=>'php-fpm','roadrunner'=>'app-rr','swoole'=>'app-swoole'}) {
                $app=$container['Id'];
                if ($container['Image']!=='sha256:4102d15e94a811c89a440ee1be462c641c83ef30660d3efa70340f2ec9e4f144') throw new RuntimeException('Serving image identity drift');
            } else throw new RuntimeException('Unexpected active runtime/service');
        }
        if ($app===null || $generator===null || ($runtime==='fpm' && $gateway===null)) throw new RuntimeException('Missing exact app/generator/gateway');
        pilotWriteJson($directory.'/inventory-before.json',$inventory);
        $verification=json_decode($read([...$docker,'exec',$app,'php','/app/pilot/runtime/verify.php']),true,512,JSON_THROW_ON_ERROR);
        pilotWriteJson($directory.'/runtime-verification.json',$verification);
        if ($budget->remaining()<66) throw new RuntimeException('Prepared window and bounded salvage/cleanup cannot fit remaining time');
        if (!$preflight) $sampler=pilotStartChild(['timeout','--signal=TERM','--kill-after=1s','46s','sh',__DIR__.'/sample.sh',$runtime,'44',$directory.'/samples.jsonl',$project,$app,$generator,...($gateway?[$gateway]:[])],$directory.'/sampler.log');
        $environment=['-e','PILOT_TARGET='.$cell['target'],'-e','PILOT_REVISION='.$revision,'-e','PILOT_PROJECT='.$project];
        if (!$preflight) $environment=[...$environment,'-e','PILOT_RATE='.$cell['rate'],'-e','PILOT_PROFILE='.$cell['profile'],'-e','PILOT_CELL='.$cell['id']];
        $launch=<<<'SH'
printf '%s %s %s\n' "$$" "$(awk '{print $22}' /proc/$$/stat)" "$PILOT_PROJECT" > /tmp/pilot-k6.pid.pending
mv /tmp/pilot-k6.pid.pending /tmp/pilot-k6.pid
[ ! -f /tmp/pilot-k6.abort ] || exit 108
exec k6 "$@"
SH;
        $nativeStarted=true;
        $exit=pilotChild([...$docker,'exec',...$environment,$generator,'sh','-c',$launch,'pilot-owned-k6','run','--no-usage-report','--log-format','raw','--out','json=/tmp/pilot-raw.json.gz','--summary-export','/tmp/pilot-summary.json',$preflight?'/pilot/runtime/preflight.js':'/pilot/load.js'],$directory.'/k6.log',min($preflight?36:44,$budget->remaining()-22));
        $salvaged=true;
        $artifacts=pilotNativeSalvage($docker,$generator,$project,$directory,hrtime(true)/1e9+min(12,$budget->remaining()-11));
        if ($artifacts['native_stop']['status']!=='stopped' || $artifacts['raw']['status']!=='copied' || $artifacts['summary']['status']!=='copied') throw new RuntimeException('Native stop/artifacts incomplete; available partial files retained');
        if ($sampler!==null) {
            if ($exit!==0) { pilotStopChild(...$sampler); $sampler=null; }
            else { $sampleExit=pilotWaitChild(...[...$sampler,min(46,$budget->remaining()-22)]); $sampler=null; if ($sampleExit!==0) throw new RuntimeException('Sampler child failed; partial artifacts retained'); }
        }
        $after=json_decode($read([...$docker,'inspect',...array_column($inventory,'Id')]),true,512,JSON_THROW_ON_ERROR);
        pilotWriteJson($directory.'/inventory-after.json',$after);
        foreach($after as $index=>$container) if (!$container['State']['Running'] || $container['State']['OOMKilled'] || $container['RestartCount']!==0 || $container['State']['StartedAt']!==$inventory[$index]['State']['StartedAt']) throw new RuntimeException('Unexpected stopped/restarted/OOM container');
        $verificationAfter=json_decode($read([...$docker,'exec',$app,'php','/app/pilot/runtime/verify.php']),true,512,JSON_THROW_ON_ERROR);
        pilotWriteJson($directory.'/runtime-verification-after.json',$verificationAfter);
        if (($verificationAfter['inventory']['workers'][0]['pid']??null)!==($verification['inventory']['workers'][0]['pid']??null)
            || ($verificationAfter['inventory']['workers'][0]['start_ticks']??null)!==($verification['inventory']['workers'][0]['start_ticks']??null)) throw new RuntimeException('Runtime worker endpoint identity changed');
        $points=pilotJsonLines($directory.'/k6-raw.json.gz');
        if ($preflight) return ['exit'=>$exit,'log'=>file_get_contents($directory.'/k6.log'),'native_count'=>pilotNativeRequestCount($points)];
        $evidence=pilotCellEvidence($cell,$points,pilotJsonLines($directory.'/samples.jsonl'),$exit);
        pilotWriteJson($directory.'/evidence.json',$evidence);
        if (!$evidence['valid']) throw new RuntimeException(implode('; ',$evidence['reasons']));
        return $evidence;
    } catch(Throwable $error) { $failure=$error->getMessage(); throw $error; }
    finally {
        pcntl_sigprocmask(SIG_BLOCK,[SIGINT,SIGTERM,SIGHUP,SIGUSR1],$previousMask);
        if ($sampler!==null) pilotStopChild(...$sampler);
        // Stop native workload and salvage while tmpfs is alive, THEN own down.
        try { if ($ownsProject) {
            pilotVerifyLocalDocker();
            if ($nativeStarted && !$salvaged) { $salvaged=true; pilotNativeSalvage($docker,$generator,$project,$directory,hrtime(true)/1e9+min(12,max(0,$budget->remaining()-5))); }
            if ($nativeStarted) pilotWriteJson($directory.'/child.json',['exit'=>$exit,'project'=>$project,'failure'=>$failure]);
            // Discovery/verification/removal/down/empty checks share the original
            // five-second cleanup phase, not a new allowance beyond22seconds.
            pilotOwnCleanup($compose,$docker,$project,$generator,$directory.'/cleanup.log',hrtime(true)/1e9+min(5,$budget->remaining()));
        } }
        catch(Throwable $error) { pilotWriteJson($directory.'/cleanup-failure.json',['reason'=>$error->getMessage(),'project'=>$project]); throw $error; }
        finally { pcntl_sigprocmask(SIG_SETMASK,$previousMask); }
    }
}

function pilotMain(array $arguments): int {
    if (count($arguments)!==3 || !in_array($arguments[0],['--dry-run','--execute'],true) || $arguments[1]!=='--output') {
        throw new RuntimeException('Usage: php pilot/run.php --dry-run|--execute --output DIR');
    }
    $mode=$arguments[0]; $output=$arguments[2];
    if (!str_starts_with($output,'/')) throw new RuntimeException('Absolute output directory required');
    if ($mode==='--dry-run') {
        $manifest=pilotFreeze($output);
        echo "PASS dry-run:81 windows,75600 nominal arrivals +93 frozen readiness, zero HTTP; preparation_only, replay verification pending\n";
        return 0;
    }
    $output=realpath($output)?:throw new RuntimeException('Frozen execution output missing');
    $manifest=json_decode(file_get_contents($output.'/manifest.json'),true,512,JSON_THROW_ON_ERROR);
    if (($manifest['preparation_only']??true)!==false || ($manifest['replay_verified']??false)!==true || ($manifest['native_load_api_verified']??false)!==true || ($issues=pilotManifestIssues($manifest))) throw new RuntimeException('Task5 verified replay/native load API execution freeze required: '.implode('; ',$issues??[]));
    if (($manifest['replay_archive']??'')!=='app-replay.tar' || hash_file('sha256',$output.'/app-replay.tar')!==($manifest['replay_archive_sha256']??'')) throw new RuntimeException('Replay archive hash mismatch');
    $budget=new PilotBudget(pilotLedgerDirectory().'/budget-execution.json',hash_file('sha256',$output.'/manifest.json'),$output,pilotLedgerDirectory().'/budget-build.json');
    if ($budget->state()['started_monotonic']!==null) throw new RuntimeException('Study already started; no automatic recovery or reruns');
    $actualDaemon=json_decode(pilotCommand([...pilotDockerCommand(),'info','--format','{{json .}}']),true,512,JSON_THROW_ON_ERROR);
    verifyDaemon($actualDaemon);
    if ($actualDaemon['NCPU']!==$manifest['daemon_observation']['NCPU'] || $actualDaemon['MemTotal']!==$manifest['daemon_observation']['MemTotal']) throw new RuntimeException('Frozen daemon capacity drift before first HTTP');
    $schedule=$manifest['schedule'];
    pcntl_async_signals(true);
    foreach([SIGINT,SIGTERM,SIGHUP] as $signal) pcntl_signal($signal,static function(int $signal):void { throw new RuntimeException("Interrupted by signal $signal"); });
    try {
        pilotRunSequence($budget,$schedule,
            static fn(array $cell)=>pilotObserve($cell,$output.'/preflight/'.$cell['runtime'].'-'.$cell['revision'],$budget,true),
            static fn(array $cell)=>pilotObserve($cell,$output.'/runs/'.$cell['id'],$budget,false));
        pilotWriteJson($output.'/execution.json',['status'=>'complete','budget'=>$budget->state()+['elapsed_seconds'=>4500-$budget->remaining()]]);
        echo "PASS complete native pilot; run check.php for independent accounting\n";
        return 0;
    } catch(Throwable $error) {
        pilotWriteJson($output.'/execution.json',['status'=>'partial','reason'=>$error->getMessage(),'budget'=>$budget->state()+['elapsed_seconds'=>4500-$budget->remaining()]]);
        throw $error;
    } finally { pilotWriteJson($output.'/schedule.json',$schedule); $budget->close(); }
}

if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {
    try { exit(pilotMain(array_slice($argv,1))); }
    catch(Throwable $error) { fwrite(STDERR,'FAIL: '.$error->getMessage()."\n"); exit(1); }
}
