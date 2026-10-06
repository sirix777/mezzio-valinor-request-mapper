#!/usr/bin/env php
<?php
declare(strict_types=1);
// Inert CLI boundary for the existing-project cleanup refusal test. No socket.
file_put_contents(getenv('PILOT_TEST_LOG'),json_encode($argv)."\n",FILE_APPEND);
if(in_array(getenv('PILOT_TEST_MODE'),['interrupt','before-inventory','cleanup'],true)) {
    $fixture=getenv('PILOT_TEST_FIXTURE');
    if (in_array('info',$argv,true)) { echo file_get_contents(__DIR__.'/../runtime/daemon.json'); exit(0); }
    if (in_array('up',$argv,true)) { file_put_contents($fixture.'/project',$argv[array_search('--project-name',$argv,true)+1]); exit(0); }
    if (in_array('compose',$argv,true) && in_array('run',$argv,true)) { file_put_contents($fixture.'/generator-present','sleep600 one-off'); exit(0); }
    if (in_array('ps',$argv,true)) {
        if(is_file($fixture.'/network-removed')) { if(is_file($fixture.'/leftover')) echo str_repeat('f',64),"\n"; exit(0); }
        if(in_array('label=com.docker.compose.service=generator',$argv,true)) { if(is_file($fixture.'/generator-present')) echo str_repeat('b',64),is_file($fixture.'/ambiguous')?"\n".str_repeat('e',64):'',"\n"; exit(0); }
        if(is_file($fixture.'/app-removed')) { if(is_file($fixture.'/generator-present')) echo str_repeat('b',64),"\n"; exit(0); }
        if(is_file($fixture.'/project')) echo str_repeat('a',64),' ',str_repeat('b',64),' ',str_repeat('c',64),"\n"; exit(0);
    }
    if (in_array('network',$argv,true) && in_array('ls',$argv,true)) { if(!is_file($fixture.'/network-removed')) echo str_repeat('d',64),"\n"; exit(0); }
    if (in_array('inspect',$argv,true)) {
        if(in_array('network',$argv,true)) {
            echo json_encode([['Id'=>is_file($fixture.'/network-id-mismatch')?str_repeat('e',64):str_repeat('d',64),'Labels'=>['com.docker.compose.project'=>is_file($fixture.'/foreign-network')?'mezzio-pilot-foreign':trim(file_get_contents($fixture.'/project')),'com.docker.compose.network'=>'pilot'],'Containers'=>is_file($fixture.'/busy-network')?[str_repeat('f',64)=>['Name'=>'foreign']]:[]]]); exit(0);
        }
        if(is_file($fixture.'/slow-inspect')) { pcntl_async_signals(true); pcntl_signal(SIGTERM,SIG_IGN); file_put_contents($fixture.'/slow-child.pid',(string)getmypid()); usleep(2000000); file_put_contents($fixture.'/late-write','forbidden after deadline'); }
        $ids=array_slice($argv,array_search('inspect',$argv,true)+1);
        if(count($ids)===1&&!is_file($fixture.'/generator-present')) { fwrite(STDERR,'No such container'); exit(1); }
        if(getenv('PILOT_TEST_MODE')==='before-inventory'&&count($ids)>1) exit(17);
        $inventory=json_decode(file_get_contents($fixture.'/inventory.json'),true);
        foreach($inventory as &$row) if(getenv('PILOT_TEST_MODE')!=='cleanup') $row['Config']['Labels']['com.docker.compose.project']=trim(file_get_contents($fixture.'/project')); unset($row);
        if(count($ids)===1) $inventory=[$inventory[1]];
        echo json_encode($inventory); exit(0);
    }
    if (in_array('/app/pilot/runtime/verify.php',$argv,true)) { echo file_get_contents($fixture.'/verification.json'); exit(0); }
    if (in_array('exec',$argv,true) && (in_array('k6',$argv,true) || in_array('pilot-owned-k6',$argv,true))) {
        file_put_contents($fixture.'/native-raw.json.gz',gzencode("{\"evidence_kind\":\"synthetic_partial_no_http\"}\n"));
        posix_kill((int)getenv('PILOT_TEST_PARENT'),SIGUSR1); usleep(5000000); exit(0);
    }
    if (in_array('exec',$argv,true) && in_array('pilot-owned-native-stop',$argv,true)) { posix_kill(-(int)getenv('PILOT_TEST_NATIVE_PID'),SIGTERM); exit(0); }
    if (in_array('exec',$argv,true) && in_array('cat',$argv,true)) {
        $source=end($argv);
        if($source==='/tmp/pilot-raw.json.gz' && is_file($fixture.'/native-raw.json.gz')) { echo file_get_contents($fixture.'/native-raw.json.gz'); exit(0); }
        fwrite(STDERR,'Synthetic missing summary'); exit(1); // Raw remains independently readable.
    }
    if(in_array('network',$argv,true)&&in_array('rm',$argv,true)) { if(end($argv)!==str_repeat('d',64)) exit(17); file_put_contents($fixture.'/network-removed','exact owned empty network'); exit(0); }
    if(in_array('rm',$argv,true)) {
        if(end($argv)!==str_repeat('b',64)||!in_array('-f',$argv,true)) exit(17);
        if(is_file($fixture.'/disappear-at-rm')) { unlink($fixture.'/generator-present'); fwrite(STDERR,'No such container'); exit(1); }
        unlink($fixture.'/generator-present'); file_put_contents($fixture.'/generator-removed','exact owned one-off');
        if(is_file($fixture.'/native-raw.json.gz')) rename($fixture.'/native-raw.json.gz',$fixture.'/raw-lost-on-removal.gz');
        exit(0);
    }
    if (in_array('down',$argv,true)) {
        if(is_file($fixture.'/slow-down')) { pcntl_async_signals(true); pcntl_signal(SIGTERM,SIG_IGN); file_put_contents($fixture.'/slow-child.pid',(string)getmypid()); sleep(10); file_put_contents($fixture.'/late-write','forbidden after deadline'); }
        file_put_contents($fixture.'/app-removed','only own app');
        if((int)getenv('PILOT_TEST_NATIVE_PID')>1) posix_kill(-(int)getenv('PILOT_TEST_NATIVE_PID'),SIGTERM);
        if(is_file($fixture.'/generator-present')) fwrite(STDERR,'Network own_pilot Resource is still in use');
        elseif(!is_file($fixture.'/network-only')) file_put_contents($fixture.'/network-removed','own empty network');
        exit(0);
    }
    exit(99);
}
if (in_array('info',$argv,true)) { echo file_get_contents(__DIR__.'/../runtime/daemon.json'); exit(0); }
if (in_array('ps',$argv,true)) { if (getenv('PILOT_TEST_MODE')!=='partial') echo str_repeat('a',64),"\n"; exit(0); }
if (in_array('up',$argv,true) && getenv('PILOT_TEST_MODE')==='partial') exit(17);
if (in_array('down',$argv,true)) exit(0);
if (in_array('network',$argv,true) && in_array('ls',$argv,true) && getenv('PILOT_TEST_MODE')==='partial') exit(0);
fwrite(STDERR,"Unexpected synthetic command\n"); exit(99);
