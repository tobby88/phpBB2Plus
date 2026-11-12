<?php
namespace CalendarTopicFixture;
// Extends the existing disposable MariaDB fixture; never accepts a live schema.
// SQL, storage preparation, BBCode and Extreme Styles rendering are production
// code. Only authority/icon/navigation/output-error boundaries are adapters.
if(PHP_SAPI!=='cli'||!isset($schema)||preg_match('/^phpbb_calendar_birthdays_[a-f0-9]{12}$/D',$schema)!==1){throw new \RuntimeException('Own calendar schema required');}
$topic_checks=0;$topic_cases=0;
require $app.'includes/template.php';require $app.'includes/bbcode.php';require $app.'includes/functions_post.php';
function check($ok,$message){if(!$ok){throw new \RuntimeException($message);}$GLOBALS['topic_checks']++;}
function message_die($code,$message){throw new \RuntimeException($message);}
function auth($kind,$scope,$user){return $GLOBALS['topic_auth'];}
function get_icon_title($icon,$unused=0,$type=-1){return '';}
function make_cat_nav_tree($fid,$unused,$class){return 'Fixture hierarchy '.$fid;}
function authority_keys($fid){
    $ids=$fid==='f6'?array(6):($fid==='f7'?array(7):($fid==='c2'?array(6):($fid==='c3'?array(7):array(6,7))));
    return array('id'=>array_map(function($id){return 'f'.$id;},$ids),'idx'=>array_map(function($id){return $id===6?0:1;},$ids));
}
class Database extends \sql_db {
    public $readonly=false,$queries=array(),$active=array(),$fail='';
    function sql_query($sql='',$transaction=false){
        if($this->readonly&&!preg_match('/^SELECT\b/i',$sql)){throw new \RuntimeException('Collector attempted a write');}
        $this->queries[]=$sql;
        if($this->fail!==''&&strpos($sql,$this->fail)!==false){return false;}
        $result=parent::sql_query($sql,$transaction);
        if(is_object($result)&&preg_match('/^SELECT (?:t\.\*|COUNT\(\*\) AS total|forum_id FROM)/',$sql)){$this->active[spl_object_hash($result)]=true;}
        return $result;
    }
    function sql_freeresult($result=false){if(is_object($result)){unset($this->active[spl_object_hash($result)]);}return parent::sql_freeresult($result);}
}
class OverviewTemplate extends \Template {
    public $fail=false;
    function assign_var_from_handle($name,$handle){if($this->fail){throw new \RuntimeException('Synthetic overview render failure');}return parent::assign_var_from_handle($name,$handle);}
}
function run($filter='f6',$offset=0,$maximum=100,$limited=true,$first=1793664000,$last=1793750400){
    global $template,$app,$db,$topic_cases;
    $template=(new \ReflectionClass(__NAMESPACE__.'\OverviewTemplate'))->newInstanceWithoutConstructor();
    $template->vars=&$template->_tpldata['.'][0];$template->load_config($app.'templates/fisubsilversh',false);
    $template->fail=!empty($GLOBALS['topic_render_fail']);$GLOBALS['topic_initial_vars']=$template->_tpldata;$db->queries=array();$events=array();$number=-1;
    get_event_topics($events,$number,$first,$last,$limited,$offset,$maximum,$filter);$topic_cases++;
    check($db->active===array(),'Calendar collector did not release its result');
    foreach($events as$event){check(preg_match('//u',$event['event_short_title'])===1&&preg_match('//u',$event['event_message'])===1,'Actual calendar template contains broken UTF-8');check($event['event_forum_name']==='Fixture &amp; forum','Both authority branches must select the forum name');}
    return array($events,$number);
}
function fingerprint(){global$db;$state=array();foreach(array('users','forums','topics','posts','posts_text','smilies','removed_author_names')as$table){$result=$db->sql_query('SELECT * FROM fixture_'.$table.' ORDER BY 1');$state[$table]=$db->sql_fetchrowset($result);$db->sql_freeresult($result);}return hash('sha256',serialize($state));}

foreach(array('TOPICS_TABLE'=>'fixture_topics','POSTS_TABLE'=>'fixture_posts','POSTS_TEXT_TABLE'=>'fixture_posts_text','FORUMS_TABLE'=>'fixture_forums','SMILIES_TABLE'=>'fixture_smilies','REMOVED_AUTHOR_NAMES_TABLE'=>'fixture_removed_author_names','POST_TOPIC_URL'=>'t','POST_FORUM_URL'=>'f','POST_CAT_URL'=>'c','POST_CALENDAR'=>10,'AUTH_ALL'=>0,'AUTH_LIST_ALL'=>0,'TOPIC_MOVED'=>2)as$key=>$value){if(!defined($key)){define($key,$value);}}
$source=file_get_contents($app.'includes/functions_calendar.php');
foreach(array('calendar_normalize_forum_filter','calendar_html_excerpt','calendar_topic_event','get_event_topics','calendar_date_clock','get_calendar_title_date','date_dsp')as$function){eval('namespace CalendarTopicFixture;'.\calendar_fixture_function($source,$function));}
$db->sql_close();$db=new Database('127.0.0.1:'.$port,'root',$password,$schema,false);check((bool)$db->db_connect_id,'Own topic fixture connection unavailable');
$ddl=file_get_contents($app.'install/schemas/mysql_schema.sql');
if(!$db->sql_query('DROP TABLE fixture_users')){throw new \RuntimeException('Own birthday fixture replacement failed');}
foreach(array('users','forums','topics','posts','posts_text','smilies','removed_author_names')as$table){
    if(preg_match('/CREATE TABLE phpbb_'.$table.' \(.*?\) ENGINE=InnoDB[^;]*;/s',$ddl,$match)!==1||!$db->sql_query(str_replace('phpbb_'.$table,'fixture_'.$table,$match[0]))){throw new \RuntimeException('Canonical calendar schema setup failed');}
}
$global_orig_word=$global_replacement_word=array();$phpbb_root_path=$app;$phpEx='php';
$theme=array('template_name'=>'fisubsilversh','td_class1'=>'row1','td_class2'=>'row2');
$board_config=array('default_lang'=>'english','board_timezone'=>0,'default_dateformat'=>'Y-m-d H:i','calendar_title_length'=>30,'calendar_text_length'=>200,'topics_per_page'=>2,'allow_html'=>0,'allow_bbcode'=>1,'allow_smilies'=>1,'smilies_path'=>'images/smiles','xs_use_cache'=>0,'xs_auto_compile'=>0,'xs_auto_recompile'=>0,'version'=>'.0.23','hide_removed_author_names'=>1);
$lang=array();$lang_extend_admin=false;require$app.'language/lang_english/lang_main.php';require$app.'language/lang_english/lang_extend_topic_calendar.php';
$images=array('icon_calendar'=>'calendar.gif','icon_tiny_topic'=>'topic.gif');$userdata=array('user_id'=>10,'user_level'=>0,'session_logged_in'=>true,'user_dateformat'=>'Y-m-d H:i');
$topic_auth=array(6=>array('auth_read'=>true),7=>array('auth_read'=>false));
$tree=array('type'=>array('f','f'),'id'=>array(6,7),'auth'=>array('f6'=>array('auth_read'=>true),'f7'=>array('auth_read'=>false)));
foreach(array("INSERT INTO fixture_users(user_id,username,user_password) VALUES(-1,'Anonymous','fixture'),(10,'Änne &amp; author','fixture')","INSERT INTO fixture_forums(forum_id,cat_id,forum_name) VALUES(6,2,'Fixture &amp; forum'),(7,3,'Denied forum')","INSERT INTO fixture_smilies(code,smile_url,emoticon) VALUES(':)','smile.png','Smile')")as$sql){check((bool)$db->sql_query($sql),'Own calendar seed failed');}
$title=str_repeat('A',29).'😀'.str_repeat('B',10);$uid='c0dec0ffee';
$raw=array(
    str_repeat('A',199).'😀 complete tail',
    '[b]Ä😀 [i]nested Ö[/i][/b] & literal &amp; <script>not HTML</script> ' . str_repeat('tail ',60),
    '[quote="Änne"]Quoted 😀 [b]bold[/b][/quote] '.str_repeat('tail ',60),
    '[list][*]Ä[list][*]😀[*]Ö[/list][*]tail[/list] '.str_repeat('tail ',60),
    '[code]Ä😀 <b>literal</b>[/code] '.str_repeat('tail ',60),
    '[table][cell]Ä😀[/cell][/table] '.str_repeat('tail ',60),
    'A :) B',
    'A :) B',
    'Actual guest text',
    'Former member text',
    'Denied forum secret',
    'Outside date range',
    'Moved topic'
);
foreach($raw as$index=>$text){
    $id=$index+1;$forum=$id===11?7:6;$poster=in_array($id,array(9,10),true)?-1:10;$name=$id===9?'Real guest':($id===10?'Removed alias':'');$removed=$id===10?1:0;$smile=$id===8?0:1;$when=$id===12?1793750400:1793667600+$id;$status=$id===13?TOPIC_MOVED:0;
    $stored=\prepare_message($text,0,1,$smile,$uid);
    foreach(array("INSERT INTO fixture_posts(post_id,topic_id,forum_id,poster_id,post_username,post_author_removed,post_time,poster_ip,enable_smilies) VALUES($id,$id,$forum,$poster,'".$db->sql_escape($name)."',$removed,1793664000,'7f000001',$smile)","INSERT INTO fixture_topics(topic_id,forum_id,topic_title,topic_poster,topic_time,topic_first_post_id,topic_last_post_id,topic_calendar_time,topic_calendar_duration,topic_status) VALUES($id,$forum,'".$db->sql_escape($title)."',$poster,1793664000,$id,$id,$when,3600,$status)","INSERT INTO fixture_posts_text(post_id,post_text,bbcode_uid) VALUES($id,'".$db->sql_escape($stored)."','$uid')")as$sql){check((bool)$db->sql_query($sql),'Own stored-post seed failed');}
}
$before=fingerprint();$db->readonly=true;
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new \RuntimeException($message);}});
try{
    foreach(array('standard','hierarchy')as$mode){
        if($mode==='hierarchy'){eval('namespace { function get_auth_keys($fid,$a,$b,$c,$permission){return \\CalendarTopicFixture\\authority_keys($fid);} }');}
        list($all,$count)=run();check($count===10&&count($all)===10,'Actual SQL range/authority/moved selection differs');
        check(array_column($all,'event_id')===array('t1','t2','t3','t4','t5','t6','t7','t8','t9','t10'),'Actual chronological order differs');
        check($all[0]['event_short_title']===str_repeat('A',29).'😀...','Actual title must clip characters instead of bytes');
        check(strpos($all[0]['event_message'],str_repeat('A',199).'😀...')!==false,'Actual template must retain complete boundary character');
        check(strpos($all[1]['event_message'],'<span style="font-weight:bold">')!==false&&strpos($all[1]['event_message'],'&lt;script&gt;')!==false&&strpos($all[1]['event_message'],'<script>')===false,'BBCode must render while raw post HTML stays escaped');
        check(strpos($all[1]['event_message'],'literal &amp;amp;')!==false,'Literal entity source must not be decoded twice');
        foreach(array(2,3,4,5)as$index){check(strpos($all[$index]['event_message'],':'.$uid)===false,'Complete quote/list/code/table source must render before clipping');}
        check(strpos($all[6]['event_message'],'images/smiles/smile.png')!==false&&strpos($all[7]['event_message'],'images/smiles/smile.png')===false,'Per-post smiley preference must be respected');
        foreach(array(0,1,8,10,1000000)as$offset){list($rows,$number)=run('f6',$offset,2);check($rows===array_slice($all,$offset,2)&&$number===10,'Actual count/LIMIT page differs');check(count(array_filter($db->queries,function($sql){return strpos($sql,'SELECT COUNT(*) AS total')===0;}))===1,'Limited collection must count scalars, not fetch all post texts');}
        list($rows,$number)=run('c2');check($rows===$all&&$number===10,'Category filter differs');
        list($rows,$number)=run('Root');check($rows===$all&&$number===10,'Root authority differs');
        foreach(array('f7','c3','f0','f6 OR 1=1',array('f6'))as$filter){list($rows,$number)=run($filter);check($rows===array()&&$number===0,'Denied or invalid filter broadened visibility');}
        list($rows,$number)=run('f6',0,0);check($rows===array()&&$number===10,'Zero limit must preserve count without reading text');
        list($rows,$number)=run('f6',0,100,false);check($rows===$all&&$number===10,'Unpaged month collector differs');
        list($rows,$number)=run('f6',0,100,true,array(),1793750400);check($rows===array()&&$number===0&&$db->queries===array(),'Invalid date must not read storage');
        $userdata['session_logged_in']=false;list($rows,$number)=run();check($rows[8]['event_author']==='Real guest'&&$rows[9]['event_author']===$lang['Former_member']&&$rows[9]['event_last_author']===$lang['Former_member'],'Guest/former-member privacy changed');$userdata['session_logged_in']=true;
        $board_config['allow_bbcode']=0;list($rows,$number)=run();check(strpos($rows[1]['event_message'],'[b]')!==false&&strpos($rows[1]['event_message'],':'.$uid)===false,'Disabled BBCode must not leak internal UIDs');$board_config['allow_bbcode']=1;
        foreach(array('SELECT COUNT(*) AS total','SELECT t.*')as$failure){$db->fail=$failure;$failed=false;try{run();}catch(\RuntimeException$error){$failed=$error->getMessage()==='Could not obtain topics information';}$db->fail='';check($failed&&$db->active===array(),'SQL failure must propagate and release earlier results');}
        $topic_render_fail=true;$failed=false;try{run();}catch(\RuntimeException$error){$failed=$error->getMessage()==='Synthetic overview render failure';}$topic_render_fail=false;check($failed&&$db->active===array()&&$template->_tpldata===$topic_initial_vars,'Renderer failure must release actual topic query and restore template variables');
        $topic_auth[6]['auth_read']=false;$tree['auth']['f6']['auth_read']=false;
        list($rows,$number)=run('Root');check($rows===array()&&$number===0&&$db->queries===array(),'No readable forum must not query post storage');
        $topic_auth[6]['auth_read']=true;$tree['auth']['f6']['auth_read']=true;
        echo 'Native calendar topics ', $mode, ": actual SQL/storage/BBCode/Extreme Styles PASS.\n";
    }
    $months=array();foreach(array('english','german','english')as$locale){$board_config['default_lang']=$locale;$lang=array();require$app.'language/lang_'.$locale.'/lang_main.php';$months[]=date_dsp('F',gmmktime(12,0,0,3,1,2026));}check($months===array('March','März','March'),'Calendar language cache leaked across locale changes');
    check(fingerprint()===$before,'Calendar rendering changed stored fixture data');
}finally{restore_error_handler();}
echo 'Native calendar topics: ',$topic_cases,' collector cases, ',$topic_checks," assertions PASS; canonical MariaDB/InnoDB, counts, authority, UTF-8, formatting, privacy and failures.\n";
