<?php
declare(strict_types=1);
require '/home/sirix/PhpStormProjects/mezzio-valinor-request-mapper/.worktrees/mezzio-load-app/pilot/check.php';
$ledger=__DIR__; $app=dirname((new ReflectionFunction('pilotFreeze'))->getFileName(),2);
$output=dirname($ledger,3).'/docs/benchmarks/raw/application-load-pilot';
if(file_exists($output)) throw new RuntimeException('Final output must be absent before creation');
if(is_file($ledger.'/budget-execution.json')) throw new RuntimeException('Execution ledger already exists');
$hashes=pilotReadJson($ledger.'/task-5-pid1-hashes.json');
$prepared=pilotReadJson($ledger.'/task5-pid1-dryrun/manifest.json');
if(pilotManifestIssues($prepared)!==[]) throw new RuntimeException('Accepted source freeze changed');
$build=pilotReadJson($ledger.'/budget-build.json');
if($build['used_seconds']!==357.271023 || $build['active']!==null || $build['limit_seconds']!==3600) throw new RuntimeException('Build budget changed');
$native=pilotReadJson($ledger.'/task5-gates/native.json');
$platform=pilotReadJson($ledger.'/task5-platform/results.json');
$replay=pilotReadJson($ledger.'/task-5-pid1-replay.json');
if($native['status']!=='passed'||$native['http_requests']!==0||$native['native_partial_points']<=0||$native['abort_latch_exit']!==108||$native['abort_elapsed_including_cleanup_seconds']>30
    ||$native['wrapper_source_sha256']!==hash_file('sha256',$app.'/pilot/run.php')||$native['probe_sha256']!==$hashes['probe_sha256']) throw new RuntimeException('Actual native zero-HTTP proof invalid');
if($platform['status']!=='passed'||count($platform['cells'])!==6) throw new RuntimeException('Six actual runtime proofs missing');
foreach($platform['cells'] as $cell) if($cell['status']!=='passed'||$cell['http_requests']!==0) throw new RuntimeException('Actual runtime proof failed or sent HTTP');
if($replay['status']!=='passed'||$replay['archive_sha256']!==$hashes['archive']['sha256']||$replay['regular_files']!==3496) throw new RuntimeException('Exact final-source replay missing');
$manifest=pilotFreeze($output);
if($manifest['replay_archive_sha256']!==$hashes['archive']['sha256']) throw new RuntimeException('Final archive differs from accepted clean replay');
$evidence=$output.'/preexecution'; mkdir($evidence);
$copy=static function(string $from,string $to)use(&$copy):void {
    if(is_link($from)) throw new RuntimeException('Evidence symlink refused');
    if(is_dir($from)) { if(!is_dir($to)) mkdir($to,0770,true); foreach(new DirectoryIterator($from) as $item) if(!$item->isDot()) $copy($item->getPathname(),$to.'/'.$item->getFilename()); return; }
    if(!is_file($from)||filesize($from)>67108864||!copy($from,$to)||hash_file('sha256',$from)!==hash_file('sha256',$to)) throw new RuntimeException('Evidence copy integrity failure');
};
foreach(['task5-gates','task5-platform','task5-platform-before-source-inventory-correction'] as $directory) $copy($ledger.'/'.$directory,$evidence.'/'.$directory);
foreach(['budget-build.json','task-5-gates.php','task5-native-abort.js','task-5-platform-gates.php','task-5-pid1-checks.php','task-5-pid1-checks.json','task-5-pid1-replay.json','task-5-pid1-hashes.json','task-5-pid1-delta-report.md','task-5-pid1-review-package.md','task-5-pid1-review.md','task-5-final-freeze.php'] as $file) $copy($ledger.'/'.$file,$evidence.'/'.$file);
pilotWriteJson($evidence.'/LABEL.json',['evidence_kind'=>'PRE-EXECUTION zero-HTTP native gates and explicitly labeled checker fixtures; not measured readiness or application load','application_http'=>0,'native_no_http_runs'=>1,'actual_runtime_revision_proofs'=>6,'prior_prelaunch_native_failures'=>2,'prior_runtime_inventory_failure'=>1]);
$manifest['preparation_only']=false; $manifest['replay_verified']=true; $manifest['native_load_api_verified']=true;
$manifest['start_gate_proofs']=['clean_replay'=>'preexecution/task-5-pid1-replay.json','native_api_abort_salvage'=>'preexecution/task5-gates/native.json','current_daemon_images'=>'preexecution/task5-gates/images.json','six_runtime_revision_endpoints'=>'preexecution/task5-platform/results.json','checker_delta_review'=>'preexecution/task-5-pid1-review.md','six_readiness_cells'=>'PENDING real 93-request preflight in single authorized execute; no measured window before all6 pass'];
$manifest['preexecution_counters']=['application_http'=>0,'scheduled'=>0,'preflight'=>0,'execution_started'=>false,'build_used_seconds'=>$build['used_seconds'],'build_active'=>$build['active']];
pilotWriteJson($output.'/manifest.json',$manifest);
$check=pilotCheckDirectory($output);
if($check['valid']||$check['valid_cells']!==0||$check['expected_cells']!==81||in_array('preparation only; Task5 replay/native API verification/execution pending',$check['reasons'],true)) throw new RuntimeException('Unexpected checker pre-execution state');
pilotWriteJson($evidence.'/checker-before-execution.json',$check);
$proofHashes=[];
foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($evidence,FilesystemIterator::SKIP_DOTS)) as $file) {
    if(!$file->isFile()||$file->isLink()) throw new RuntimeException('Invalid evidence file');
    $proofHashes['preexecution/'.substr($file->getPathname(),strlen($evidence)+1)]=hash_file('sha256',$file->getPathname());
}
ksort($proofHashes); $manifest['preexecution_evidence_sha256']=$proofHashes;
pilotWriteJson($output.'/manifest.json',$manifest);
if(pilotManifestIssues($manifest)!==[]||is_file($ledger.'/budget-execution.json')) throw new RuntimeException('Final source/counters drift');
foreach($proofHashes as $file=>$sha) if(hash_file('sha256',$output.'/'.$file)!==$sha) throw new RuntimeException('Final proof hash drift');
pilotWriteJson($ledger.'/task-5-final-go-no-go.json',['status'=>'LOCAL_START_GATES_PASS_AWAITING_ROOT_EXECUTE_AUTHORITY','output'=>$output,'archive_sha256'=>$manifest['replay_archive_sha256'],'archive_bytes'=>filesize($output.'/app-replay.tar'),'archive_files'=>count($manifest['files']),'manifest_sha256'=>hash_file('sha256',$output.'/manifest.json'),'proof_files'=>count($proofHashes),'source_issues'=>[],'flags'=>['preparation_only'=>false,'replay_verified'=>true,'native_load_api_verified'=>true],'counters'=>$manifest['preexecution_counters'],'budgets'=>$manifest['budgets'],'nominal_scheduled_plus_preflight'=>75693,'execute_argv'=>['php',$app.'/pilot/run.php','--execute','--output',$output],'execute_cwd'=>$app,'checker_before_execution'=>['valid'=>false,'valid_cells'=>0,'expected_cells'=>81,'reasons'=>$check['reasons']],'no_new_native_or_runtime_sequence'=>true,'execute_authorized'=>false]);
echo "PASS final immutable archive/source/proof freeze; HTTP0; execute held\n";
