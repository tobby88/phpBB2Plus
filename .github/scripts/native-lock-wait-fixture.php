<?php
// Test-only: prove the actual requesting/blocking connections in InnoDB.
// An immediate empty mysqli_poll alone can race with worker scheduling.
function native_fixture_wait_for_lock($observer,$requester,$blocker,$check)
{
    if(PHP_SAPI!=='cli'||!is_callable($check)||!preg_match('/^[1-9][0-9]*$/D',(string)$requester)||!preg_match('/^[1-9][0-9]*$/D',(string)$blocker)){
        throw new RuntimeException('Explicit local fixture lock identities required');
    }
    $end=microtime(true)+5.0;
    $sql='SELECT DISTINCT r.trx_mysql_thread_id requester,b.trx_mysql_thread_id blocker FROM information_schema.INNODB_LOCK_WAITS w '.
        'INNER JOIN information_schema.INNODB_TRX r ON r.trx_id=w.requesting_trx_id '.
        'INNER JOIN information_schema.INNODB_TRX b ON b.trx_id=w.blocking_trx_id '.
        'WHERE r.trx_mysql_thread_id='.(int)$requester.' AND b.trx_mysql_thread_id='.(int)$blocker;
    do{
        $result=$observer->sql_query($sql);call_user_func($check,$result!==false,'Observe real native lock wait');
        try{$rows=$observer->sql_fetchrowset($result);}finally{$observer->sql_freeresult($result);}
        if($rows){call_user_func($check,count($rows)===1&&(int)$rows[0]['requester']===(int)$requester&&(int)$rows[0]['blocker']===(int)$blocker,'Exact native requester waits for the owned writer');return;}
        // InnoDB's information_schema snapshot is cached across these
        // tables. Leave its refresh interval between observations; a tight
        // polling loop can retain a requester without its newer blocker.
        usleep(150000);
    }while(microtime(true)<$end);
    $result=$observer->sql_query('SELECT ID,COMMAND,STATE FROM information_schema.PROCESSLIST WHERE ID IN ('.(int)$requester.','.(int)$blocker.') ORDER BY ID');$state=array();
    if($result!==false){try{$state=$observer->sql_fetchrowset($result);}finally{$observer->sql_freeresult($result);}}
    $result=$observer->sql_query('SELECT trx_id,trx_state,trx_mysql_thread_id,trx_requested_lock_id FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id IN ('.(int)$requester.','.(int)$blocker.')');$transactions=array();
    if($result!==false){try{$transactions=$observer->sql_fetchrowset($result);}finally{$observer->sql_freeresult($result);}}
    $result=$observer->sql_query('SELECT requesting_trx_id,blocking_trx_id FROM information_schema.INNODB_LOCK_WAITS');$waits=array();
    if($result!==false){try{$waits=$observer->sql_fetchrowset($result);}finally{$observer->sql_freeresult($result);}}
    throw new RuntimeException('Expected independent native lock wait was not observed: '.json_encode(array('processes'=>$state,'transactions'=>$transactions,'waits'=>$waits)));
}
