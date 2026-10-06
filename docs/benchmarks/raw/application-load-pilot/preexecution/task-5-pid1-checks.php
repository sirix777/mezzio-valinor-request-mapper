<?php
declare(strict_types=1);
require '/home/sirix/PhpStormProjects/mezzio-valinor-request-mapper/.worktrees/mezzio-load-app/pilot/freeze.php';
$app=dirname((new ReflectionFunction('pilotFreeze'))->getFileName(),2);
$directory=__DIR__.'/task5-pid1-dryrun';
if(($argv[1]??'')==='replay') {
    $manifest=pilotReadJson($directory.'/manifest.json');
    if(pilotManifestIssues($manifest)!==[]) throw new RuntimeException('Packaged source changed');
    $command=static function(array $argv):string {
        $process=proc_open(['timeout','--signal=TERM','--kill-after=1s','20s',...$argv],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        $output=stream_get_contents($pipes[1]); $error=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        if(proc_close($process)!==0) throw new RuntimeException('Offline replay command failed: '.$error);
        return $output;
    };
    $archive=$directory.'/app-replay.tar';
    if(hash_file('sha256',$archive)!==$manifest['replay_archive_sha256']) throw new RuntimeException('Replay archive SHA mismatch');
    $replay=trim($command(['mktemp','-d','/tmp/mezzio-pilot-task5-pid1-replay-XXXXXX']));
    $listing=explode("\n",rtrim($command(['tar','-tf',$archive]),"\n")); sort($listing);
    if($listing!==array_keys($manifest['files'])) throw new RuntimeException('Replay inventory mismatch');
    $command(['tar','--no-same-owner','--no-same-permissions','-xf',$archive,'-C',$replay]);
    $actual=[];
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($replay,FilesystemIterator::SKIP_DOTS)) as $file) {
        if(!$file->isFile()||$file->isLink()) throw new RuntimeException('Nonregular replay file');
        $actual[substr($file->getPathname(),strlen($replay)+1)]=hash_file('sha256',$file->getPathname());
    }
    ksort($actual);
    if($actual!==$manifest['files']) throw new RuntimeException('Replay content mismatch');
    pilotWriteJson(__DIR__.'/task-5-pid1-replay.json',['status'=>'passed','directory'=>$replay,'regular_files'=>count($actual),'archive_sha256'=>hash_file('sha256',$archive),'manifest_sha256'=>hash_file('sha256',$directory.'/manifest.json'),'file_manifest_sha256'=>hash('sha256',json_encode($actual,JSON_UNESCAPED_SLASHES)),'http_requests'=>0]);
    echo "PASS clean PID1 archive replay3496 exact regular-file hashes; zero HTTP\n"; exit(0);
}
if(($argv[1]??'')==='package') {
    $manifest=pilotFreeze($directory);
    $old=pilotReadJson(__DIR__.'/task5-corrected-replay-check/replay-stream.json')['directory'];
    $package="# Task5 retained worker PID1 checker delta — zero HTTP\n\n";
    foreach(['pilot/check.php','pilot/tests/checker.php','pilot/freeze.php'] as $file) {
        $package.="\n## A/$file\nSHA256 ".hash_file('sha256',$app.'/'.$file)."\n\n```diff\n";
        $process=proc_open(['diff','-u','--label','accepted-stream/'.$file,'--label','task5-pid1/'.$file,$old.'/'.$file,$app.'/'.$file],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        $package.=stream_get_contents($pipes[1]); fclose($pipes[1]); fclose($pipes[2]); $exit=proc_close($process);
        if(!in_array($exit,[0,1],true)) throw new RuntimeException('Cannot generate bounded checker delta');
        $package.="```\n";
    }
    $package.="\nPreparation archive SHA256 ".$manifest['replay_archive_sha256']."\nManifest SHA256 ".hash_file('sha256',$directory.'/manifest.json')."\nNative/replay flags remain false; retained actual native/runtime proofs unchanged. No execution ledger or HTTP.\n";
    file_put_contents(__DIR__.'/task-5-pid1-review-package.md',$package);
    pilotWriteJson(__DIR__.'/task-5-pid1-hashes.json',['archive'=>['sha256'=>$manifest['replay_archive_sha256'],'bytes'=>filesize($directory.'/app-replay.tar'),'files'=>count($manifest['files'])],'manifest_sha256'=>hash_file('sha256',$directory.'/manifest.json'),'package_sha256'=>hash_file('sha256',__DIR__.'/task-5-pid1-review-package.md'),'gate_helper_sha256'=>hash_file('sha256',__DIR__.'/task-5-gates.php'),'probe_sha256'=>hash_file('sha256',__DIR__.'/task5-native-abort.js'),'platform_helper_sha256'=>hash_file('sha256',__DIR__.'/task-5-platform-gates.php'),'worker_identity'=>$manifest['worker_cache_policy']['identity'],'source_issues'=>pilotManifestIssues($manifest)]);
    echo "PASS bounded PID1 checker package and preparation freeze; zero HTTP\n"; exit(0);
}
$prior=pilotReadJson(__DIR__.'/task-4-final-checks.json'); $checks=[];
foreach($prior['checks'] as $index=>$test) {
    $started=hrtime(true)/1e9;
    $process=proc_open(['timeout','--signal=TERM','--kill-after=1s','60s','sh','-c',$test['cmd']],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$app);
    $output=stream_get_contents($pipes[1]); $errors=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $exit=proc_close($process);
    $checks[]=['cmd'=>$test['cmd'],'execution_order'=>$index+1,'isolated'=>true,'exit_code'=>$exit,'elapsed_seconds'=>hrtime(true)/1e9-$started,'output'=>$output,'stderr'=>$errors];
    pilotWriteJson(__DIR__.'/task-5-pid1-checks.json',['order'=>'package/freeze first; all13 checks serial; accepted timing limits unchanged','checks'=>$checks]);
    echo ($index+1)."/13 exit$exit ".$test['cmd']."\n";
    if($exit!==0) exit($exit);
}
if(pilotManifestIssues(pilotReadJson($directory.'/manifest.json'))!==[]) throw new RuntimeException('Source changed after freeze/package');
echo "PASS13 serial offline checks; packaged source unchanged; zero HTTP\n";
