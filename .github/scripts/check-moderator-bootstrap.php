<?php
$root=dirname(dirname(__DIR__));
foreach(array('IN_PHPBB'=>true,'GENERAL_MESSAGE'=>200,'GENERAL_ERROR'=>202,'FORUMS_TABLE'=>'fixture_forums','TOPICS_TABLE'=>'fixture_topics','POST_FORUM_URL'=>'f','POST_CAT_URL'=>'c','AUTH_ALL'=>0) as $key=>$value) { define($key,$value); }
class ModeratorBootstrapExit extends RuntimeException {}
function bootstrap_check($ok,$message) { if(!$ok) { throw new RuntimeException($message); } }
function message_die($type,$message,$title='') { throw new ModeratorBootstrapExit($message); }
class ModeratorBootstrapDatabase
{
	var $pdo; var $open=0;
	function __construct()
	{
		$this->pdo=new PDO('sqlite::memory:'); $this->pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
		$this->pdo->exec('CREATE TABLE fixture_forums (forum_id INTEGER,forum_name TEXT,forum_topics INTEGER)');
		$this->pdo->exec("INSERT INTO fixture_forums VALUES (2,'translated_forum',1)");
		$this->pdo->exec('CREATE TABLE fixture_topics (topic_id INTEGER,forum_id INTEGER)');
		$this->pdo->exec('INSERT INTO fixture_topics VALUES (42,2)');
	}
	function sql_query($sql) { $this->open++; return $this->pdo->query($sql); }
	function sql_fetchrow($result) { return $result->fetch(PDO::FETCH_ASSOC); }
	function sql_freeresult($result) { $result->closeCursor(); $this->open--; }
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
	$db=new ModeratorBootstrapDatabase(); $tree=array(); $bootstrap_mod=$allowed;
	$lang=array('Session_invalid'=>'invalid session','Not_Moderator'=>'not moderator','Not_Authorised'=>'denied');
	$user_ip='127.0.0.1'; $mode='delete'; $_POST=array(); $_SERVER['REQUEST_METHOD']='GET';
	eval($controller);
	bootstrap_check($db->open===0,'Initial metadata result released');
	return array((int)$forum_id,$forum_name);
}
set_error_handler(function($severity,$message){ if(error_reporting() & $severity) { throw new RuntimeException($message); } });
try
{
	bootstrap_check(run_moderator_bootstrap(0,2)===array(2,'Übersetztes Forum'),'Forum route translates only after preferences/authorization');
	bootstrap_check(run_moderator_bootstrap(42,0)===array(2,'Übersetztes Forum'),'Topic route resolves same canonical forum');
	foreach(array(array(0,2,'fixture',false,'not moderator'),array(42,0,'fixture',false,'not moderator'),array(0,2,'',true,'invalid session'),array(0,999,'fixture',true,'Forum_not_exist')) as $case)
	{
		$caught=false; try { run_moderator_bootstrap($case[0],$case[1],$case[2],$case[3]); } catch(ModeratorBootstrapExit $error) { $caught=$error->getMessage()===$case[4]; }
		bootstrap_check($caught,'Controlled missing/unauthorized/invalid-session result, without premature translation');
	}
	echo "Moderator bootstrap order, canonical forum lookup, translations and guest/session denials passed.\n";
}
finally { restore_error_handler(); }
