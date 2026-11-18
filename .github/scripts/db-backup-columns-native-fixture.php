<?php
// Valid modern additional tables, captured through the actual ACP controller.
$bc_tables=array();$bc_queries=array();$bc_expected=array();$bc_definitions=array();$bc_fault=false;$bc_computed_only=0;
function bc_definition($database,$table){return phpbb_acl_rows($database,'SHOW CREATE TABLE `'.$table.'`')[0]['Create Table'];}
function bc_rows($database,$table){return phpbb_acl_rows($database,$GLOBALS['bc_queries'][$table]);}
function bc_add($table,$definition,$query){sd_sql('CREATE TABLE `'.$table.'`('.$definition.') ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');$GLOBALS['bc_tables'][]=$table;$GLOBALS['bc_queries'][$table]=$query;}
$bc_mixed='`1` VARCHAR(64) NOT NULL,id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,seed INT NOT NULL,hidden INT INVISIBLE NOT NULL DEFAULT 7,opaque BLOB INVISIBLE,`quo``te` VARCHAR(64) NOT NULL,defaulted INT DEFAULT (ABS(-7)),changed DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,virtual_value INT AS(seed*2) VIRTUAL,stored_value INT AS(seed+hidden) STORED,hidden_generated INT AS(hidden*2) VIRTUAL INVISIBLE';
$bc_select='SELECT `1`,id,seed,hidden,HEX(opaque) AS opaque,HEX(`quo``te`) AS quoted_value,defaulted,changed,virtual_value,stored_value,hidden_generated FROM `fixture_columns` ORDER BY id';
bc_add('fixture_columns',$bc_mixed,$bc_select);
sd_sql("INSERT INTO fixture_columns(`1`,id,seed,hidden,opaque,`quo``te`,defaulted,changed) VALUES(CONVERT(0x4772c3bcc39f6520f09f9880 USING utf8mb4),0,21,91,0xff8000275c0a001a,CONVERT(0x4f275265696c6c79205c USING utf8mb4),13,'2008-01-02 03:04:05'),('0',12,-2,0,NULL,'',0,'2010-02-03 04:05:06')");
sd_sql('ALTER TABLE fixture_columns AUTO_INCREMENT=900');
bc_add('fixture_columns_hidden',"id INT PRIMARY KEY,secret VARCHAR(64) INVISIBLE DEFAULT 'fallback'",'SELECT id,secret FROM fixture_columns_hidden ORDER BY id');
sd_sql("INSERT INTO fixture_columns_hidden(id,secret) VALUES(1,'actual stored value'),(2,CONVERT(0x4772c3bcc39f6520f09f9880 USING utf8mb4))");
bc_add('fixture_columns_numeric','`1` INT,id INT PRIMARY KEY,`0` VARCHAR(64)','SELECT `1`,id,`0` FROM fixture_columns_numeric ORDER BY id');
sd_sql("INSERT INTO fixture_columns_numeric(`1`,id,`0`) VALUES(91,1,'0'),(0,2,'preserved')");
bc_add('fixture_columns_empty',$bc_mixed,str_replace('`fixture_columns`','`fixture_columns_empty`',$bc_select));
$version=phpbb_acl_rows($peer,'SELECT VERSION() version')[0]['version'];
foreach(array('VIRTUAL','STORED')as$kind){
 $table='fixture_columns_only_'.strtolower($kind);$ok=$peer->sql_query('CREATE TABLE '.$table.'(g INT AS(42) '.$kind.') ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
 if(!$ok){$error=$peer->sql_error();sd_check(strpos($version,'MariaDB')===false&&(int)$error['code']===3106&&strpos(strtolower($error['message']),'generated columns')!==false,'Only actual MySQL restriction on all-computed tables may be recognized');continue;}
 $bc_tables[]=$table;$bc_queries[$table]='SELECT g FROM '.$table.' ORDER BY g';$bc_computed_only++;
 for($i=0;$i<3;$i++){sd_sql('INSERT INTO '.$table.' () VALUES()');}
}
if($mode==='columns-empty'){foreach($bc_tables as$table){sd_sql('DELETE FROM `'.$table.'`');}}
foreach($bc_tables as$table){$bc_expected[$table]=bc_rows($peer,$table);$bc_definitions[$table]=bc_definition($peer,$table);}
if($mode==='columns-metadata-failure'){$sd_failure='SHOW FULL COLUMNS FROM `fixture_columns`';$sd_hook=function($sql){if($sql==='SHOW FULL COLUMNS FROM `fixture_columns`'){$GLOBALS['bc_fault']=true;}};}
if($mode==='columns-read-failure'||$mode==='columns-ddl'){
 if($mode==='columns-ddl'){sd_sql('SET SESSION lock_wait_timeout=1');}
 $sd_hook=function($sql)use($mode){
  if(strpos($sql,'SELECT ')!==0||strpos($sql,' FROM `fixture_columns`')===false||strpos($sql,' LIMIT 0')!==false){return;}$GLOBALS['sd_hook']=null;
  if($mode==='columns-read-failure'){$GLOBALS['sd_failure']=$sql;$GLOBALS['bc_fault']=true;return;}
  $ok=$GLOBALS['peer']->sql_query('ALTER TABLE fixture_columns ADD unexpected INT');$error=$GLOBALS['peer']->sql_error();$GLOBALS['bc_fault']=$ok===false&&(int)$error['code']===1205;
 };
}
function bc_finish($restore,$dump,$mode,$denied){
 global$peer,$bc_tables,$bc_queries,$bc_expected,$bc_definitions,$bc_fault,$bc_computed_only;
 foreach($bc_tables as$table){
  sd_check(bc_rows($peer,$table)===$bc_expected[$table]&&bc_definition($peer,$table)===$bc_definitions[$table],'Column backup never changes source values or definitions');
  if(!$denied){sd_check(bc_rows($restore,$table)===($mode==='columns-structure'?array():$bc_expected[$table]),'Native import preserves every stored/hidden/default value and recomputed expression');sd_check(bc_definition($restore,$table)===$bc_definitions[$table],'Native import preserves exact column/default/visibility/generated definitions');}
 }
 if($denied){sd_check($bc_fault,'Actual projected read or metadata failure was reached');}
 elseif($mode!=='columns-structure'&&$mode!=='columns-empty'){
  sd_check(strpos($dump,'`hidden`, `opaque`')!==false&&strpos($dump,'`defaulted`')!==false,'Dump includes real ordinary invisible and expression-default values');
  foreach(preg_split('/\r?\n/',$dump)as$line){if(strpos($line,'INSERT INTO `fixture_columns` ')===0){sd_check(strpos($line,'`virtual_value`')===false&&strpos($line,'`stored_value`')===false&&strpos($line,'`hidden_generated`')===false,'Computed columns are never explicit INSERT targets');}}
 }
 sd_check($mode!=='columns-ddl'||$bc_fault,'Actual concurrent column DDL remains blocked throughout capture');
 echo 'Native backup columns ',substr($mode,8),': actual ACP/metadata/visible-hidden/generated/default/bytes/schema/failure roundtrip PASS; ',json_encode(array('tables'=>count($bc_tables),'computed_only_tables'=>$bc_computed_only,'denied'=>$denied,'production_access'=>false)),"\n";
}
