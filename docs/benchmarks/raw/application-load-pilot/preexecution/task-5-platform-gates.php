<?php
declare(strict_types=1);
require '/home/sirix/PhpStormProjects/mezzio-valinor-request-mapper/.worktrees/mezzio-load-app/pilot/run.php';
$manifest=pilotReadJson(__DIR__.'/task5-stream-dryrun/manifest.json');
$native=pilotReadJson(__DIR__.'/task5-gates/native.json');
if(($native['status']??'')!=='passed'||pilotManifestIssues($manifest)!==[]) throw new RuntimeException('Native/source start gate missing');
$directory=__DIR__.'/task5-platform';
if(file_exists($directory)) throw new RuntimeException('Refusing runtime proof rerun/overwrite');
mkdir($directory,0770,true); $events=[]; $results=[]; $docker=pilotDockerCommand();
function platformCommand(array $argv,float $limit=10): string {
    global $directory,$events;
    $index=count($events); $started=hrtime(true)/1e9;
    $process=proc_open(['timeout','--signal=TERM','--kill-after=1s',(string)$limit.'s',...$argv],[0=>['file','/dev/null','r'],1=>['file',$directory.'/command-'.$index.'.stdout','w'],2=>['file',$directory.'/command-'.$index.'.stderr','w']],$pipes,dirname(__DIR__,4).'/mezzio-load-app');
    if(!is_resource($process)) throw new RuntimeException('Cannot start bounded runtime gate command');
    $exit=proc_close($process); $events[]=['argv'=>$argv,'started_monotonic'=>$started,'ended_utc'=>gmdate('c'),'elapsed_seconds'=>hrtime(true)/1e9-$started,'timeout_seconds'=>$limit,'exit'=>$exit];
    pilotWriteJson($directory.'/events.json',$events);
    if($exit!==0) throw new RuntimeException("Runtime gate command $index exit$exit");
    return file_get_contents($directory.'/command-'.$index.'.stdout');
}
function platformAssert(bool $ok,string $reason):void {if(!$ok)throw new RuntimeException($reason);}
try {
    $daemon=json_decode(platformCommand([...$docker,'info','--format','{"ID":{{json .ID}},"Name":{{json .Name}},"DockerRootDir":{{json .DockerRootDir}},"NCPU":{{json .NCPU}},"MemTotal":{{json .MemTotal}}}']),true,512,JSON_THROW_ON_ERROR);
    verifyDaemon($daemon); platformAssert($daemon['NCPU']===14&&$daemon['MemTotal']===27316473856,'Actual daemon capacity drift');
    foreach(['fpm','roadrunner','swoole'] as $runtime) foreach(['baseline','candidate'] as $revision) {
        $project='mezzio-pilot-task5-platform-'.bin2hex(random_bytes(6)); $compose=pilotCompose($project,$runtime,$revision); $owns=false;
        $result=['runtime'=>$runtime,'revision'=>$revision,'project'=>$project,'http_requests'=>0];
        try {
            platformAssert(trim(platformCommand([...$docker,'ps','-aq','--filter','label=com.docker.compose.project='.$project]))==='','Refusing existing runtime proof project');
            $owns=true; platformCommand([...$compose,'up','-d','--no-build','--pull','never']); usleep(500000);
            $ids=trim(platformCommand([...$docker,'ps','-q','--no-trunc','--filter','label=com.docker.compose.project='.$project]));
            platformAssert($ids!=='','Missing active runtime proof containers');
            $inventory=json_decode(platformCommand([...$docker,'inspect',...preg_split('/\s+/',$ids)]),true,512,JSON_THROW_ON_ERROR);
            platformAssert(count($inventory)===($runtime==='fpm'?2:1),'Wrong runtime container count');
            $app=null;
            foreach($inventory as $container) {
                $labels=$container['Config']['Labels']; $service=$labels['com.docker.compose.service'];
                platformAssert($labels['com.docker.compose.project']===$project&&$container['State']['Running']&&!$container['State']['OOMKilled']&&$container['RestartCount']===0,'Owned runtime container identity/state drift');
                platformAssert($container['HostConfig']['ReadonlyRootfs']&&!$container['HostConfig']['Privileged']&&empty($container['HostConfig']['PortBindings'])&&$container['Config']['User']==='1001:1001'&&$container['HostConfig']['CapDrop']===['ALL']&&empty($container['Config']['Healthcheck']),'Unsafe actual runtime/hidden health requests');
                if($service===($runtime==='fpm'?'php-fpm':($runtime==='roadrunner'?'app-rr':'app-swoole'))) {
                    platformAssert($container['Image']===$manifest['serving_image_config_id']&&$container['HostConfig']['NanoCpus']===4000000000&&$container['HostConfig']['Memory']===2147483648,'Pinned serving image/caps drift');
                    platformAssert(in_array('PILOT_REVISION='.$revision,$container['Config']['Env'],true)&&in_array('PILOT_RUNTIME='.$runtime,$container['Config']['Env'],true),'Serving process environment mismatch');
                    $app=$container['Id'];
                } else {
                    platformAssert($runtime==='fpm'&&$service==='app-fpm'&&$container['Image']==='sha256:34e04bb6b4bb37d45845842374be0cd181723daffb230849b1984aaeaa96faba'&&$container['HostConfig']['NanoCpus']===1000000000&&$container['HostConfig']['Memory']===268435456,'Unexpected gateway/service/caps');
                    $result['gateway_cgroups']=platformCommand([...$docker,'exec',$container['Id'],'sh','-c','cat /sys/fs/cgroup/cpu.max /sys/fs/cgroup/memory.max']);
                    platformAssert($result['gateway_cgroups']==="100000 100000\n268435456\n",'Gateway cgroup ceiling drift');
                }
            }
            platformAssert($app!==null,'Missing exact serving app'); $result['inventory_before']=$inventory;
            foreach(['before','after'] as $phase) {
                $proof=json_decode(platformCommand([...$docker,'exec',$app,'php','/app/pilot/runtime/verify.php']),true,512,JSON_THROW_ON_ERROR);
                foreach(['php','extensions','policy','cpu_max','memory_max'] as $key) platformAssert($proof[$key]===$manifest['platform'][$runtime][$key],"Actual $runtime platform/cache/cgroup mismatch: $key");
                platformAssert($proof['runtime']===$runtime&&$proof['revision']===$revision&&$proof['source']==='/app/pilot/library/'.$revision.'/src/ConfigProvider.php','Actual source/revision mismatch');
                platformAssert(count($proof['inventory']['workers'])===1,'Actual endpoint does not have one worker');
                $result['verification_'.$phase]=$proof;
            }
            $before=$result['verification_before']['inventory']['workers'][0]; $after=$result['verification_after']['inventory']['workers'][0];
            platformAssert([$before['pid'],$before['start_ticks']]===[$after['pid'],$after['start_ticks']],'Worker endpoint identity changed');
            $sourceFiles=[];
            foreach($manifest['files'] as $path=>$hash) if(in_array($path,['composer.json','composer.lock','pilot/bootstrap.php','pilot/library/'.$revision.'/composer.json'],true)||str_starts_with($path,'config/')||str_starts_with($path,'src/Pilot/')||str_starts_with($path,'public/')||str_starts_with($path,'pilot/library/'.$revision.'/src/')) $sourceFiles[$path]=$hash;
            $result['excluded_source_roots']=['pilot/library/'.$revision.'/docs','src/App','src/MezzioInstaller'];
            $hashProof=json_decode(platformCommand([...$docker,'exec',$app,'php','-r','$files=json_decode($argv[1],true,512,JSON_THROW_ON_ERROR); foreach($files as $path=>$hash)if(hash_file("sha256","/app/".$path)!==$hash)throw new RuntimeException("Serving source hash mismatch: ".$path); echo json_encode(["verified_files"=>count($files),"file_manifest_sha256"=>hash("sha256",json_encode($files,JSON_UNESCAPED_SLASHES))]),"\n";',json_encode($sourceFiles,JSON_UNESCAPED_SLASHES)]),true,512,JSON_THROW_ON_ERROR);
            $result['serving_source']=$hashProof;
            $result['status']='passed';
        } finally {
            if($owns) { platformCommand([...$compose,'down','--timeout','2'],5); $result['cleanup_utc']=gmdate('c'); }
            pilotWriteJson($directory.'/'.$runtime.'-'.$revision.'.json',$result);
        }
        $results[]=$result; pilotWriteJson($directory.'/results.json',['status'=>'in_progress','cells'=>$results,'http_requests'=>0]);
        echo "PASS actual $runtime/$revision serving platform/source/one-worker endpoints; zeroHTTP\n";
    }
    pilotWriteJson($directory.'/results.json',['status'=>'passed','cells'=>$results,'daemon'=>$daemon,'http_requests'=>0]);
} catch(Throwable $error) { pilotWriteJson($directory.'/failure.json',['reason'=>$error->getMessage(),'utc'=>gmdate('c'),'http_requests'=>0]); fwrite(STDERR,'FAIL: '.$error->getMessage()."\n"); exit(1); }
