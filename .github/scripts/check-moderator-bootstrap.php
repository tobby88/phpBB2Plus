<?php
if(PHP_SAPI!=='cli'||getenv('PHPBB_MODERATOR_BOOTSTRAP_NATIVE')!=='1'){echo "Native moderator bootstrap requires an explicitly enabled disposable database; skipped.\n";return;}
$root=dirname(dirname(__DIR__));
foreach(array('IN_PHPBB'=>true,'GENERAL_MESSAGE'=>200,'GENERAL_ERROR'=>202,'FORUMS_TABLE'=>'fixture_forums','TOPICS_TABLE'=>'fixture_topics','POST_FORUM_URL'=>'f','POST_CAT_URL'=>'c','AUTH_ALL'=>0) as $key=>$value) { define($key,$value); }
require $root.'/phpBB2/includes/php_compat.php';require $root.'/phpBB2/db/mysqli.php';
class ModeratorBootstrapExit extends RuntimeException {}
function bootstrap_check($ok,$message) { if(!$ok) { throw new RuntimeException($message); } }
function message_die($type,$message,$title='') { throw new ModeratorBootstrapExit($message); }
class ModeratorBootstrapDatabase
{
	var $connection; var $open=0;
	function __construct($connection){$this->connection=$connection;}
	function sql_query($sql) { $result=$this->connection->sql_query($sql);bootstrap_check((bool)$result,'Actual native metadata query succeeds');$this->open++;return$result; }
	function sql_fetchrow($result) { return $this->connection->sql_fetchrow($result); }
	function sql_freeresult($result) { $this->connection->sql_freeresult($result); $this->open--; }
}
function session_pagestart($ip,$forum) { bootstrap_check((int)$forum===2,'Session uses canonical forum'); return array('session_id'=>'fixture'); }
function init_userprefs($userdata)
{
	global $tree,$lang;
	$tree=array('keys'=>array('f2'=>0),'type'=>array('f'),'data'=>array(array('forum_name'=>'translated_forum')),'auth'=>array('f2'=>array('auth_view'=>true)));
	$lang['translated_forum']='Übersetztes Forum';
}
function auth($type,$forum,$user)
{
	bootstrap_check(isset($GLOBALS['tree']['keys']['f2']),'Preferences initialized before authorization');
	return array('auth_mod'=>$GLOBALS['bootstrap_mod']);
}
$source=file_get_contents($root.'/phpBB2/modcp.php');
$start=strpos($source,'if ( !empty($topic_id) )'); $end=strpos($source,'switch( $mode )',$start);
bootstrap_check($start!==false && $end>$start,'Find actual initial moderator controller'); $controller=substr($source,$start,$end-$start);
$hierarchy=file_get_contents($root.'/phpBB2/includes/functions_categories_hierarchy.php');
$start=strpos($hierarchy,'function get_object_lang('); $end=strpos($hierarchy,'function cache_words(',$start);
bootstrap_check($start!==false && $end>$start,'Find actual hierarchy translation'); eval(substr($hierarchy,$start,$end-$start));
function run_moderator_bootstrap($topic_id,$forum_id,$sid='fixture',$allowed=true)
{
	global $db,$tree,$lang,$controller,$bootstrap_mod;
	$db=new ModeratorBootstrapDatabase($GLOBALS['bootstrap_connection']); $tree=array(); $bootstrap_mod=$allowed;
	$lang=array('Session_invalid'=>'invalid session','Not_Moderator'=>'not moderator','Not_Authorised'=>'denied');
	$user_ip='127.0.0.1'; $mode='delete'; $_POST=array(); $_SERVER['REQUEST_METHOD']='GET';
	eval($controller);
	bootstrap_check($db->open===0,'Initial metadata result released');
	if($topic_id){bootstrap_check($topic_row['topic_title']==="Actual Größe &amp; title",'Actual canonical title is available for blank reply subjects');}
	return array((int)$forum_id,$forum_name);
}
$port=getenv('PHPBB_MODERATOR_BOOTSTRAP_PORT')?:'3306';$password=getenv('PHPBB_MODERATOR_BOOTSTRAP_PASSWORD')?:'';bootstrap_check(preg_match('/^[0-9]{1,5}$/D',$port)&&(int)$port>0&&(int)$port<=65535,'Owned loopback port');$host='127.0.0.1:'.$port;
$schema='codex_mod_bootstrap_'.bin2hex(phpbb_random_bytes(8));$control=new sql_db($host,'root',$password,'',false);bootstrap_check($control->db_connect_id,'Native control connection');$created=false;$bootstrap_connection=null;
set_error_handler(function($severity,$message){ if(error_reporting() & $severity) { throw new RuntimeException($message); } });
try
{
	bootstrap_check($control->sql_query('CREATE DATABASE '.$schema.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'),'Own disposable bootstrap schema');$created=true;$bootstrap_connection=new sql_db($host,'root',$password,$schema,false);bootstrap_check($bootstrap_connection->db_connect_id,'Own native schema connection');
	$canonical=file_get_contents($root.'/phpBB2/install/schemas/mysql_schema.sql');
	foreach(array('forums'=>array('forum_id'=>2,'forum_name'=>'translated_forum','forum_topics'=>1),'topics'=>array('topic_id'=>42,'forum_id'=>2,'topic_title'=>"Actual Größe &amp; title"))as$suffix=>$values){
		bootstrap_check(preg_match('/^CREATE TABLE phpbb_'.$suffix.' \(\r?\n.*?^\) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;/ms',$canonical,$definition)===1,'Actual canonical participant definition');$table='fixture_'.$suffix;bootstrap_check($bootstrap_connection->sql_query(preg_replace('/^CREATE TABLE phpbb_'.$suffix.' /','CREATE TABLE '.$table.' ',$definition[0])),'Create exact canonical participant');
		$result=$bootstrap_connection->sql_query('SHOW COLUMNS FROM '.$table);bootstrap_check((bool)$result,'Read actual seed metadata');while($column=$bootstrap_connection->sql_fetchrow($result)){if(!array_key_exists($column['Field'],$values)&&$column['Null']==='NO'&&$column['Default']===null&&strpos($column['Extra'],'auto_increment')===false){$values[$column['Field']]=preg_match('/^(tinyint|smallint|mediumint|int|bigint|float|double|decimal)/',$column['Type'])?0:'';}}$bootstrap_connection->sql_freeresult($result);$encoded=array();foreach($values as$value){$encoded[]="'".$bootstrap_connection->sql_escape((string)$value)."'";}bootstrap_check($bootstrap_connection->sql_query('INSERT INTO '.$table.' ('.implode(',',array_keys($values)).') VALUES ('.implode(',',$encoded).')'),'Own canonical seed rows');
	}
	bootstrap_check(run_moderator_bootstrap(0,2)===array(2,'Übersetztes Forum'),'Forum route translates only after preferences/authorization');
	bootstrap_check(run_moderator_bootstrap(42,0)===array(2,'Übersetztes Forum'),'Topic route resolves same canonical forum');
	foreach(array(array(0,2,'fixture',false,'not moderator'),array(42,0,'fixture',false,'not moderator'),array(0,2,'',true,'invalid session'),array(0,999,'fixture',true,'Forum_not_exist')) as $case)
	{
		$caught=false; try { run_moderator_bootstrap($case[0],$case[1],$case[2],$case[3]); } catch(ModeratorBootstrapExit $error) { $caught=$error->getMessage()===$case[4]; }
		bootstrap_check($caught,'Controlled missing/unauthorized/invalid-session result, without premature translation');
	}
	echo "Moderator bootstrap order, canonical forum lookup, translations and guest/session denials passed.\n";
	echo "Moderator bootstrap native: 6 cases PASS; actual mysqli/canonical tables, topic title, initialized preferences, translations and controlled role/session/missing-forum results; synthetic own schema.\n";
}
finally { if($bootstrap_connection){$bootstrap_connection->sql_close();}if($created){bootstrap_check(preg_match('/^codex_mod_bootstrap_[a-f0-9]{16}$/D',$schema),'Owned schema cleanup');bootstrap_check($control->sql_query('DROP DATABASE '.$schema),'Drop only own disposable schema');}$control->sql_close();restore_error_handler(); }
