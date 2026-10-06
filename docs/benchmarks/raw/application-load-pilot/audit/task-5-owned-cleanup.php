<?php
declare(strict_types=1);
require '/home/sirix/PhpStormProjects/mezzio-valinor-request-mapper/.worktrees/mezzio-load-app/pilot/freeze.php';
$targets=[
    'mezzio-pilot-ecab352dececf49e'=>['8e6f3205cc2ba3079429d297c99473c6b61468baaa8d81516ccad9269a923692','3ea3b78fb7c40cd87995d157ad2cb030561e04f068f9ae1a3955c19822f8bd57'],
    'mezzio-pilot-36441bf8c9dd49a2'=>['ad77d4913d74843b0ab027b21985e48923b1e788be0a0977572e134cc3cab55f','368587bd048178c1131a1a8a0f3c254ba088085fbd28a35190f46ddc28493db0'],
    'mezzio-pilot-0677c5b588de2469'=>['ae630f1ec3ae22b51d899e2f0e2f81648bd0b351b26fc08f6f180f7937bfa05f','6606d7f5b9074cfbaf96744eb2680944ce49b578e71abceae42e2651f14aedd9'],
    'mezzio-pilot-aad338813ae6612f'=>['e4158fdaf2e5a7854dc4f3569f38db231399d4ae61ee7ba205d4ae6743ad5d5a','4e5fe3416e69309a3618b026d25d0693279c4f672c5ce6d283039cd3de0fa43e'],
    'mezzio-pilot-7bfb6f110293c4d8'=>['7e898c79e764112a627d9bc0ecfa3d5bfc8ade1aabd83a051abac593efa87aa6','103b800ef8a61ace4fc6f82e8d55662a4ccf84a1b457ddb023a31ec85f09186a'],
    'mezzio-pilot-5823fbcb886b0d04'=>['2b1c18b610cd25641ac8d906e772d32deae45ef5e040ee1a512e0be459157364','24c8700cde6705382422d78e526dc001e7207f2a6b805054d97ae7831b24991c'],
    'mezzio-pilot-71c15ee19a31c60a'=>['1650832cf2aa09d2969f9cbe98769cb6e8ab798ca46db1fa32735110f5cfe9e4','535a7e4fffa3ec7502715457ea78574e62feed8a06f26d87f977f78452bb0ee1'],
];
$recordPath=__DIR__.'/task-5-owned-cleanup.json'; if(file_exists($recordPath)) throw new RuntimeException('One bounded cleanup only; prior record retained');
$budget=pilotReadJson(__DIR__.'/budget-execution.json'); $deadline=$budget['started_monotonic']+4500;
$record=['authority'=>'ROOT exact seven owned generator/network IDs cleanup ruling; no HTTP or retry','started_utc'=>gmdate('c'),'original_execution_started_monotonic'=>$budget['started_monotonic'],'original_hard_deadline_monotonic'=>$deadline,'events'=>[],'removed'=>[]];
pilotWriteJson($recordPath,$record);
$command=static function(array $args)use(&$record,$recordPath,$deadline):string {
    $remaining=$deadline-hrtime(true)/1e9; if($remaining<=0) throw new RuntimeException('Original execution cleanup deadline expired');
    $argv=[...pilotDockerCommand(),...$args]; $start=hrtime(true)/1e9;
    $output=pilotCommand($argv,min(5,$remaining));
    $record['events'][]=['argv'=>$argv,'started_utc'=>gmdate('c'),'started_monotonic'=>$start,'finished_monotonic'=>hrtime(true)/1e9,'exit'=>0,'stdout'=>$output];
    pilotWriteJson($recordPath,$record); return $output;
};
try {
    // Verify ALL seven exact resources before the first destructive command.
    foreach($targets as $project=>[$id,$networkId]) {
        $container=json_decode($command(['inspect',$id]),true,512,JSON_THROW_ON_ERROR)[0];
        $network=json_decode($command(['network','inspect',$networkId]),true,512,JSON_THROW_ON_ERROR)[0];
        $labels=$container['Config']['Labels'];
        if($container['Id']!==$id||($labels['com.docker.compose.project']??null)!==$project||($labels['com.docker.compose.service']??null)!=='generator'||($labels['com.docker.compose.oneoff']??null)!=='True'||$container['Image']!=='sha256:bfa9adb4f73593aaa9647cd339ecd5e6103827bce63267618a4ec51d5db56f72') throw new RuntimeException('Exact residual generator ownership changed');
        if($network['Id']!==$networkId||($network['Labels']['com.docker.compose.project']??null)!==$project||($network['Labels']['com.docker.compose.network']??null)!=='pilot'||!in_array(array_keys($network['Containers']??[]),[[],[$id]],true)) throw new RuntimeException('Exact residual network ownership/member changed');
        $record['verified'][$project]=['container'=>$container,'network'=>$network]; pilotWriteJson($recordPath,$record);
    }
    foreach($targets as $project=>[$id,$networkId]) {
        $command(['rm','-f',$id]); $record['removed'][]=['kind'=>'owned_generator','project'=>$project,'Id'=>$id]; pilotWriteJson($recordPath,$record);
        $network=json_decode($command(['network','inspect',$networkId]),true,512,JSON_THROW_ON_ERROR)[0];
        if($network['Id']!==$networkId||($network['Labels']['com.docker.compose.project']??null)!==$project||($network['Containers']??[])!==[]) throw new RuntimeException('Network not exact owned empty resource after generator removal');
        $command(['network','rm',$networkId]); $record['removed'][]=['kind'=>'owned_empty_network','project'=>$project,'Id'=>$networkId]; pilotWriteJson($recordPath,$record);
        if(trim($command(['ps','-aq','--no-trunc','--filter','label=com.docker.compose.project='.$project]))!==''||trim($command(['network','ls','--no-trunc','--filter','label=com.docker.compose.project='.$project,'--format','{{.ID}}']))!=='') throw new RuntimeException('Exact owned project still has residual resources');
    }
    $record['status']='all7_owned_generators_and7_empty_private_networks_removed_verified';
} catch(Throwable $error) { $record['status']='partial_cleanup_stop_no_retry'; $record['reason']=$error->getMessage(); throw $error; }
finally { $record['finished_utc']=gmdate('c'); $record['total_elapsed_from_original_execution_reservation']=hrtime(true)/1e9-$budget['started_monotonic']; pilotWriteJson($recordPath,$record); }
echo json_encode(['status'=>$record['status'],'removed'=>count($record['removed']),'finished_utc'=>$record['finished_utc'],'total_elapsed_from_original_execution_reservation'=>$record['total_elapsed_from_original_execution_reservation']],JSON_PRETTY_PRINT),"\n";
