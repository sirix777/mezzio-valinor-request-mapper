<?php
declare(strict_types=1);
require '/home/sirix/PhpStormProjects/mezzio-valinor-request-mapper/.worktrees/mezzio-load-app/pilot/freeze.php';
$project=$argv[1]??'mezzio-pilot-71c15ee19a31c60a';
if(!in_array($project,['mezzio-pilot-71c15ee19a31c60a','mezzio-pilot-ecab352dececf49e','mezzio-pilot-36441bf8c9dd49a2','mezzio-pilot-0677c5b588de2469','mezzio-pilot-aad338813ae6612f','mezzio-pilot-7bfb6f110293c4d8','mezzio-pilot-5823fbcb886b0d04'],true)) throw new RuntimeException('Unapproved residual project');
$proofPath=__DIR__.'/task-5-residual-inspection'.($project==='mezzio-pilot-71c15ee19a31c60a'?'':'-'.$project).'.json';
if(file_exists($proofPath)) throw new RuntimeException('Preserved prior residual proof must not be overwritten');
$output=dirname(__DIR__,3).'/docs/benchmarks/raw/application-load-pilot';
$path=$output.'/runs/fpm-w1-10-baseline';
if($project!=='mezzio-pilot-71c15ee19a31c60a') foreach(['fpm','roadrunner','swoole'] as $runtime) foreach(['baseline','candidate'] as $revision) {
    $candidate=$output.'/preflight/'.$runtime.'-'.$revision;
    if(pilotReadJson($candidate.'/child.json')['project']===$project) $path=$candidate;
}
$budget=pilotReadJson(__DIR__.'/budget-execution.json');
$proof=['project'=>$project,'purpose'=>'READ-ONLY residual ownership inspection before any cleanup ruling','captured_utc'=>gmdate('c'),'elapsed_from_original_execution_reservation'=>hrtime(true)/1e9-$budget['started_monotonic'],'original_hard_deadline_monotonic'=>$budget['started_monotonic']+4500,'preserved_hashes'=>[],'commands'=>[]];
foreach(['cleanup.log','salvage.log','sampler.log','child.json','artifacts.json','samples.jsonl','samples.jsonl.summary.json','k6-raw.json.gz','k6-summary.json','commands.log'] as $file) if(is_file($path.'/'.$file)) $proof['preserved_hashes'][$file]=hash_file('sha256',$path.'/'.$file);
pilotWriteJson($proofPath,$proof);
$read=static function(array $args)use(&$proof,$proofPath):string {
    $argv=[...pilotDockerCommand(),...$args]; $started=hrtime(true)/1e9;
    $result=pilotCommand($argv,5);
    $proof['commands'][]=['argv'=>$argv,'started_monotonic'=>$started,'finished_monotonic'=>hrtime(true)/1e9,'stdout'=>$result];
    pilotWriteJson($proofPath,$proof); return $result;
};
$ids=trim($read(['ps','-aq','--no-trunc','--filter','label=com.docker.compose.project='.$project]));
$containers=$ids===''?[]:json_decode($read(['inspect',...explode("\n",$ids)]),true,512,JSON_THROW_ON_ERROR);
foreach($containers as $container) if(($container['Config']['Labels']['com.docker.compose.project']??null)!==$project||!preg_match('/^[a-f0-9]{64}$/D',$container['Id'])) throw new RuntimeException('Residual container ownership mismatch');
$networkIds=trim($read(['network','ls','--no-trunc','--filter','label=com.docker.compose.project='.$project,'--format','{{.ID}}']));
$networks=$networkIds===''?[]:json_decode($read(['network','inspect',...explode("\n",$networkIds)]),true,512,JSON_THROW_ON_ERROR);
foreach($networks as $network) {
    if(($network['Labels']['com.docker.compose.project']??null)!==$project||!preg_match('/^[a-f0-9]{64}$/D',$network['Id'])) throw new RuntimeException('Residual network ownership mismatch');
    foreach(array_keys($network['Containers']??[]) as $member) if(!in_array($member,array_column($containers,'Id'),true)) throw new RuntimeException('Network has unowned or unlisted member');
}
$proof['containers']=$containers; $proof['networks']=$networks; $proof['status']='owned_targets_identified_no_cleanup_authorized_or_attempted';
$proof['finished_utc']=gmdate('c'); $proof['elapsed_from_original_execution_reservation']=hrtime(true)/1e9-$budget['started_monotonic'];
pilotWriteJson($proofPath,$proof);
echo json_encode(['status'=>$proof['status'],'project'=>$project,'containers'=>array_map(static fn(array $c):array=>['Id'=>$c['Id'],'Name'=>$c['Name'],'Labels'=>$c['Config']['Labels'],'State'=>$c['State'],'Runtime'=>$c['HostConfig']['Runtime'],'Image'=>$c['Image']],$containers),'networks'=>array_map(static fn(array $n):array=>['Id'=>$n['Id'],'Name'=>$n['Name'],'Labels'=>$n['Labels'],'Containers'=>$n['Containers']],$networks),'elapsed_from_original_execution_reservation'=>$proof['elapsed_from_original_execution_reservation']],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),"\n";
