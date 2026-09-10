<?php
$version_source=file_get_contents(dirname(dirname(__DIR__)).'/update/update_from_153a.php');$version_helpers='';
function version_check($ok,$message){if(!$ok){throw new RuntimeException($message);}}
foreach(array('update_quote_identifier','update_version_identity_sql') as $name){version_check(preg_match('/^function '.$name.'\(.*?^\}/ms',$version_source,$m)===1,'Actual version helper found');$version_helpers.=$m[0]."\n";eval($m[0]);}
$version_dsn=getenv('PHPBB_CONFIG_TEST_DSN');$version_native=$version_dsn!==false&&$version_dsn!=='';
if($version_native){version_check(preg_match('/^mysql:host=127\.0\.0\.1;port=33119;dbname=codex_config_[a-f0-9]{16};charset=utf8mb4$/D',$version_dsn)===1,'Only owned local schema');}
$version_db=$version_native?new PDO($version_dsn,'root',''):new PDO('sqlite::memory:');$version_db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
foreach($version_native?array('MyISAM','InnoDB'):array('SQLite') as $version_engine){
 $version_db->exec('DROP TABLE IF EXISTS fixture_config');$version_db->exec('CREATE TABLE fixture_config (config_name VARCHAR(191) PRIMARY KEY,config_value VARCHAR(255))'.($version_native?' ENGINE='.$version_engine:''));
 foreach(array(null,'','.0.0','.0.21','.0.22','.0.23','.0.24','custom') as $current){
  $version_db->exec('DELETE FROM fixture_config');$version_db->exec("INSERT INTO fixture_config VALUES ('custom','keep')");if($current!==null){$version_db->exec("INSERT INTO fixture_config VALUES ('version',".$version_db->quote($current).")");}
  $ops=update_version_identity_sql('fixture_config',$current);$supported=in_array($current,array(null,'','.0.0','.0.21','.0.22'),true);version_check(count($ops)===($supported?1:0),'Only supported unknown/old markers queued');
  foreach($ops as $sql){$version_db->exec($sql);}$actual=$version_db->query("SELECT config_value FROM fixture_config WHERE config_name='version'")->fetchColumn();version_check($actual===($supported?'.0.23':$current),'Canonical identity repaired without overwriting custom versions');
  version_check(update_version_identity_sql('fixture_config',$actual)===array(),'Repeated identity repair is a no-op');version_check($version_db->query("SELECT config_value FROM fixture_config WHERE config_name='custom'")->fetchColumn()==='keep','Other settings untouched');
  if($supported){$version_db->exec("UPDATE fixture_config SET config_value='concurrent' WHERE config_name='version'");foreach($ops as $sql){$version_db->exec($sql);}version_check($version_db->query("SELECT config_value FROM fixture_config WHERE config_name='version'")->fetchColumn()==='concurrent','Current version wins over stale finalization plan');}
 }
 echo $version_engine." version identity plans and retries passed.\n";
}
// Execute the ACTUAL updater tail in a child: exit() on schema/engine failure
// must occur before identity publication. All child state is in-memory SQLite.
$version_start=strpos($version_source,'foreach ($operations as $sql)');version_check($version_start!==false,'Actual migration execution tail found');$version_tail=substr($version_source,$version_start);
$version_prelude= <<<'PHP'
<?php
namespace VersionPipelineFixture;
function update_query_or_fail($connection,$sql){if($sql==='FAIL_FIXTURE'){exit(3);}$connection->exec($sql);if($GLOBALS['fixture_case']==='identity-ack'&&strpos($sql,'INSERT INTO `fixture_config`')===0){exit(3);}return true;}
function update_config_engine($connection,$database,$table){return $GLOBALS['fixture_case']==='engine-failure'?'MyISAM':'InnoDB';}
function mysqli_close($connection){}
$connection=new \PDO('sqlite::memory:');$connection->setAttribute(\PDO::ATTR_ERRMODE,\PDO::ERRMODE_EXCEPTION);
$connection->exec('CREATE TABLE fixture_config (config_name TEXT PRIMARY KEY,config_value TEXT)');$connection->exec('CREATE TABLE fixture_steps (step TEXT)');
register_shutdown_function(function() use($connection){echo 'FIXTURE_STATE='.json_encode(array('version'=>$connection->query("SELECT config_value FROM fixture_config WHERE config_name='version'")->fetchColumn(),'steps'=>(int)$connection->query('SELECT COUNT(*) FROM fixture_steps')->fetchColumn()))."\n";});
$dbname='fixture';$table_prefix='fixture_';$apply=$GLOBALS['fixture_case']!=='dry';
$operations=array("INSERT INTO fixture_steps VALUES ('first')",$GLOBALS['fixture_case']==='schema-failure'?'FAIL_FIXTURE':"INSERT INTO fixture_steps VALUES ('second')");
PHP;
$version_command=escapeshellarg(PHP_BINARY).' -d display_errors=0';
if(DIRECTORY_SEPARATOR==='\\'){$version_command.=' -d '.escapeshellarg('extension_dir='.ini_get('extension_dir')).' -d extension=php_pdo_sqlite.dll';}
foreach(array('success','dry','schema-failure','engine-failure','identity-ack') as $version_case){
 $input=str_replace("namespace VersionPipelineFixture;","namespace VersionPipelineFixture;\n\$GLOBALS['fixture_case']='".$version_case."';",$version_prelude)."\n".$version_helpers."\n\$version_operations=update_version_identity_sql('fixture_config',null);\n".$version_tail;
 $pipes=array();$process=proc_open($version_command,array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes,null,null,array('bypass_shell'=>true));version_check(is_resource($process),'Child fixture process available');fwrite($pipes[0],$input);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);fclose($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[2]);$code=proc_close($process);
 version_check(preg_match('/FIXTURE_STATE=(\{[^\r\n]+\})/',$out,$m)===1,'Actual execution tail reported final state: '.$err);$state=json_decode($m[1],true);
 $published=in_array($version_case,array('success','identity-ack'),true);version_check($state['version']===($published?'.0.23':false),'No identity published before completed schema work and engine verification: '.$version_case);
 version_check($state['steps']===($version_case==='dry'?0:($version_case==='schema-failure'?1:2)),'Actual preceding migration steps preserved without fictitious rollback');
 version_check($code===(in_array($version_case,array('success','dry'),true)?0:3),'Expected updater exit status');version_check((strpos($out,'Database update complete.')!==false)===($version_case==='success'),'Success only after acknowledged finalization');
}
echo "Actual updater execution ordering, failure boundaries and dry run passed.\n";
