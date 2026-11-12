<?php
// Test setup only: publish the original rows with one durable commit instead
// of one commit per DELETE/INSERT. Never borrow an already active transaction.
function kp_seed_batch($callback)
{
 if(!empty($GLOBALS['kp_seed_active'])||!is_callable($callback)){throw new RuntimeException('Independent fixture seed callback required');}
 $state=kp_rows('SELECT DATABASE() fixture_schema,@@SESSION.autocommit fixture_autocommit');
 kp_check(count($state)===1&&$state[0]['fixture_schema']===$GLOBALS['fixture']&&preg_match('/^codex_[a-z_]+_[a-f0-9]{16}$/D',$GLOBALS['fixture'])===1&&(string)$state[0]['fixture_autocommit']==='1','Only own idle disposable fixture may be seeded');
 kp_check($GLOBALS['peer']->sql_query('SET TRANSACTION READ WRITE')!==false,'Fixture setup must not commit pending peer work');
 $active=false;
 try{
  $active=true;kp_sql('START TRANSACTION');$tables=array_values(array_unique($GLOBALS['kp_tables']));
  foreach($tables as$table){kp_check(preg_match('/^fixture_[a-z0-9_]+$/D',$table)===1,'Only fixture participant identifiers');}
  // LIMIT 0 still opens/pins each named table. Chunk below MySQL's join limit
  // without a separate client/server round trip for every empty participant.
  foreach(array_chunk($tables,50)as$chunk){$r=kp_sql('SELECT 1 FROM '.implode(',',$chunk).' LIMIT 0');$GLOBALS['peer']->sql_freeresult($r);}
  $rows=kp_rows("SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('".implode("','",$tables)."')");
  kp_check(count($rows)===count($tables),'Every fixture participant exists');foreach($rows as$row){kp_check($row['ENGINE']==='InnoDB','Fixture batch needs transactional participants');}
  $GLOBALS['kp_seed_active']=true;call_user_func($callback);$GLOBALS['kp_seed_active']=false;
  kp_sql('COMMIT');$active=false;
 }finally{
  $GLOBALS['kp_seed_active']=false;if($active){$GLOBALS['peer']->sql_query('ROLLBACK');}
 }
}
