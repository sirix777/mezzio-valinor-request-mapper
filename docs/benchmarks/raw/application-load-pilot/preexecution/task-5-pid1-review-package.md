# Task5 retained worker PID1 checker delta — zero HTTP


## A/pilot/check.php
SHA256 22c66e854a9344ebb89f5489ead801fdf6309561c4d637ebdcbf94d4e6beeda7

```diff
--- accepted-stream/pilot/check.php
+++ task5-pid1/pilot/check.php
@@ -35,7 +35,9 @@
         foreach(['php','extensions','policy','cpu_max','memory_max'] as $key) if(($proof[$key]??null)!==($platform[$key]??null) || !isset($platform[$key])) throw new RuntimeException('Retained runtime platform/cgroup/cache mismatch: '.$key);
         if(($proof['runtime']??null)!==$runtime || ($proof['revision']??null)!==$cell['revision'] || ($proof['source']??null)!=='/app/pilot/library/'.$cell['revision'].'/src/ConfigProvider.php') throw new RuntimeException('Retained runtime/revision/source mismatch');
         $workers=$proof['inventory']['workers']??[];
-        if(count($workers)!==1 || !is_int($workers[0]['pid']??null) || $workers[0]['pid']<=1 || !is_int($workers[0]['start_ticks']??null) || $workers[0]['start_ticks']<=0 || ($workers[0]['rss_kib']??0)<=0) throw new RuntimeException('Retained one-worker identity missing');
+        if(count($workers)!==1 || !is_int($workers[0]['pid']??null) || $workers[0]['pid']<=0
+            || ($workers[0]['pid']===1 && ($runtime!=='swoole' || ($workers[0]['command']??null)!=='pilot-swoole-worker-0'))
+            || !is_int($workers[0]['start_ticks']??null) || $workers[0]['start_ticks']<=0 || ($workers[0]['rss_kib']??0)<=0) throw new RuntimeException('Retained one-worker identity missing');
         $current=[$workers[0]['pid'],$workers[0]['start_ticks']]; $identity??=$current;
         if($current!==$identity) throw new RuntimeException('Retained worker before/after identity mismatch');
     }
```

## A/pilot/tests/checker.php
SHA256 dc59c7b01cff865792e70dc47b86f32b8c3291b0526005ec86fcc7b2c1448d58

```diff
--- accepted-stream/pilot/tests/checker.php
+++ task5-pid1/pilot/tests/checker.php
@@ -41,7 +41,7 @@
 };
 $writeProof($inventory,$inventory,$verification);
 assert(pilotCheckDirectory($dir)['cells'][0]['status']==='valid','Complete synthetic provenance rejected');
-foreach(['labels','project','service','image','image-reference','cpu','memory','platform','extensions','runtime','revision','source','cgroup','cache','worker','worker-identity','after-image','after-state'] as $mutation) {
+foreach(['labels','project','service','image','image-reference','cpu','memory','platform','extensions','runtime','revision','source','cgroup','cache','worker','worker-identity','pid1-fpm','after-image','after-state'] as $mutation) {
     $before=$inventory; $after=$inventory; $proof=$verification;
     switch($mutation) {
         case 'labels': unset($before[0]['Config']['Labels']); break;
@@ -60,6 +60,7 @@
         case 'cache': $proof['policy']['opcache.validate_timestamps']='1'; break;
         case 'worker': $proof['inventory']['workers']=[]; break;
         case 'worker-identity': $proof['inventory']['workers'][0]['pid']=13; break;
+        case 'pid1-fpm': $proof['inventory']['workers'][0]['pid']=1; $proof['inventory']['workers'][0]['command']='pilot-swoole-worker-0'; break;
         case 'after-image': $after[0]['Image']='sha256:'.str_repeat('f',64); break;
         case 'after-state': $after[0]['State']['Running']=false; break;
     }
@@ -89,4 +90,49 @@
 pilotWriteJson($ready.'/runtime-verification.json',$verification); $proof=$verification; $proof['inventory']['workers'][0]['pid']=13;
 pilotWriteJson($ready.'/runtime-verification-after.json',$proof);
 assert(in_array('readiness fpm/baseline unavailable/invalid',pilotCheckDirectory($dir)['reasons'],true),'Readiness ignored changed worker endpoint');
+// Portable PID1 contract fixture also runs in a clean replay without the ledger.
+$pid1=$dir.'/synthetic-swoole-pid1'; mkdir($pid1);
+$pid1Containers=[$inventory[0],$inventory[1]];
+$pid1Containers[0]['Config']['Labels']['com.docker.compose.service']='app-swoole';
+$pid1Proof=array_intersect_key($manifest['platform']['swoole'],array_flip(['php','extensions','policy','cpu_max','memory_max']))
+    +['runtime'=>'swoole','revision'=>'baseline','source'=>'/app/pilot/library/baseline/src/ConfigProvider.php','inventory'=>['workers'=>[['pid'=>1,'start_ticks'=>123,'rss_kib'=>1000,'command'=>'pilot-swoole-worker-0']]]];
+foreach(['before','after'] as $phase) pilotWriteJson($pid1.'/inventory-'.$phase.'.json',$pid1Containers);
+foreach(['runtime-verification.json','runtime-verification-after.json'] as $file) pilotWriteJson($pid1.'/'.$file,$pid1Proof);
+pilotRetainedProvenance($pid1,['runtime'=>'swoole','revision'=>'baseline'],$manifest,['project'=>$project]);
+$badCommand=$pid1Proof; $badCommand['inventory']['workers'][0]['command']='unrelated-pid1';
+pilotWriteJson($pid1.'/runtime-verification-after.json',$badCommand);
+try { pilotRetainedProvenance($pid1,['runtime'=>'swoole','revision'=>'baseline'],$manifest,['project'=>$project]); throw new LogicException('Accepted unrelated Swoole PID1 command'); }
+catch(RuntimeException $error) {}
+// Actual saved endpoint proofs, with an explicitly synthetic generator/container
+// wrapper for this checker-only fixture; never represented as measured readiness.
+$saved=pilotLedgerDirectory().'/task5-platform/results.json';
+if(is_file($saved)) {
+    $savedProofs=pilotReadJson($saved); assert($savedProofs['status']==='passed'&&count($savedProofs['cells'])===6);
+    foreach($savedProofs['cells'] as $actual) {
+        $model=$dir.'/saved-'.$actual['runtime'].'-'.$actual['revision']; mkdir($model);
+        pilotWriteJson($model.'/LABEL.json',['evidence_kind'=>'SYNTHETIC CONTAINER WRAPPER with ACTUAL SAVED endpoint proofs; zero HTTP, not measurement readiness']);
+        $containers=$actual['inventory_before']; $generator=$inventory[1];
+        $generator['Config']['Labels']['com.docker.compose.project']=$actual['project']; $containers[]=$generator;
+        pilotWriteJson($model.'/inventory-before.json',$containers); pilotWriteJson($model.'/inventory-after.json',$containers);
+        pilotWriteJson($model.'/runtime-verification.json',$actual['verification_before']);
+        pilotWriteJson($model.'/runtime-verification-after.json',$actual['verification_after']);
+        $savedCell=['runtime'=>$actual['runtime'],'revision'=>$actual['revision']];
+        pilotRetainedProvenance($model,$savedCell,$manifest,['project'=>$actual['project']]);
+        if($actual['runtime']==='swoole') foreach(['zero','negative','not-integer','start','container','revision','role','worker-command'] as $mutation) {
+            $before=$containers; $proof=$actual['verification_after'];
+            if($mutation==='zero') $proof['inventory']['workers'][0]['pid']=0;
+            if($mutation==='negative') $proof['inventory']['workers'][0]['pid']=-1;
+            if($mutation==='not-integer') $proof['inventory']['workers'][0]['pid']='1';
+            if($mutation==='start') ++$proof['inventory']['workers'][0]['start_ticks'];
+            if($mutation==='container') $before[0]['Id']=str_repeat('f',64);
+            if($mutation==='revision') $proof['revision']=$actual['revision']==='baseline'?'candidate':'baseline';
+            if($mutation==='role') $before[0]['Config']['Labels']['com.docker.compose.service']='app-rr';
+            if($mutation==='worker-command') $proof['inventory']['workers'][0]['command']='unrelated-pid1';
+            pilotWriteJson($model.'/inventory-before.json',$before); pilotWriteJson($model.'/runtime-verification-after.json',$proof);
+            try { pilotRetainedProvenance($model,$savedCell,$manifest,['project'=>$actual['project']]); throw new LogicException('Accepted saved PID1 mutation: '.$mutation); }
+            catch(RuntimeException $error) {}
+        }
+    }
+    echo "PASS all6 ACTUAL saved endpoint identities (synthetic container wrapper); Swoole PID1 mutations rejected\n";
+}
 echo "PASS independent checker: forged completion cannot replace raw/sampler/readiness/hash/archive evidence; zero HTTP\n";
```

## A/pilot/freeze.php
SHA256 2d1ff27f1709fbc84f76756b9b1efd7eb17daa8a571667f207fa05d203a14a7b

```diff
--- accepted-stream/pilot/freeze.php
+++ task5-pid1/pilot/freeze.php
@@ -41,7 +41,7 @@
         'daemon_observation'=>$observation,
         'machine'=>['kernel'=>php_uname(),'host_boot_id'=>trim(file_get_contents('/proc/sys/kernel/random/boot_id')),'daemon_resources'=>['cpus'=>$observation['NCPU'],'memory_bytes'=>$observation['MemTotal'],'architecture'=>$versions['docker_daemon']['architecture']],'shared_host'=>true],
         'budgets'=>['build_seconds'=>3600,'execution_seconds'=>4500,'scheduled_requests'=>80000,'preflight_requests'=>300,'frozen_preflight_requests'=>93],
-        'worker_cache_policy'=>['request_workers'=>1,'restart_each_window'=>true,'warmup_seconds'=>10,'measured_seconds'=>30,'opcache'=>'enabled, timestamps disabled; fresh worker/cache every window','jit'=>'disabled'],
+        'worker_cache_policy'=>['request_workers'=>1,'restart_each_window'=>true,'warmup_seconds'=>10,'measured_seconds'=>30,'opcache'=>'enabled, timestamps disabled; fresh worker/cache every window','jit'=>'disabled','identity'=>'positive integer PID/start ticks; PID1 only Swoole command pilot-swoole-worker-0; retained container/platform/revision and before/after start identity must match'],
         'generator'=>['rates'=>[10,20,40],'vus'=>8,'arrival_seconds'=>40,'timeout_seconds'=>2,'drain_seconds'=>2,'sampler_seconds'=>44,'mixed_order'=>['w1','w1','w2','w2','w3'],'seed'=>'iterationInTest modulo5, no randomization','redirects'=>0,'local_instances'=>1,'connection_policy'=>'k6 defaults, connection reuse enabled','usage_reporting'=>false],
         'instrumentation'=>['native_latency'=>'http_req_duration timestamp minus duration reconstructs sending start; sending/waiting/receiving only','phase'=>'native sending start in [scenario origin+10s,origin+40s); completion retained through42s','clock_correlation'=>'native Unix-ms scenario origin and host sample epoch; per-source UTC/uptime retained; measured+drain coverage mandatory',
             'throughput'=>'measured_starts_rps is native starts in [10,40) /30s, not completed throughput; unfinished means result missing; unavailable transport timing and full result outcomes retained separately',
```

Preparation archive SHA256 21bd7204cbcf7da98fe530b4407c073b786d03cda123de69d7c90c95e94ff232
Manifest SHA256 3593650fa0795d8a24909ec55e45242eefba04158f9c22bed94280eb4e65a838
Native/replay flags remain false; retained actual native/runtime proofs unchanged. No execution ledger or HTTP.
