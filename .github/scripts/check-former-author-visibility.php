<?php
define('IN_PHPBB',true);define('POSTS_TABLE','fixture_posts');define('USERS_TABLE','fixture_users');define('SHOUTBOX_TABLE','fixture_shout');define('REMOVED_AUTHOR_NAMES_TABLE','fixture_removed_author_names');
$root=dirname(dirname(__DIR__)).'/phpBB2/';require_once$root.'includes/php_compat.php';require_once$root.'includes/functions.php';require_once$root.'includes/functions_post_author.php';require_once$root.'includes/functions_post_edit.php';
class FormerAuthorFixture
{
	var $pdo;var $queries=array();
	function __construct($pdo){$this->pdo=$pdo;}
	function sql_query($sql){$this->queries[]=$sql;if(strpos($sql,'SELECT ENGINE, ROW_FORMAT, TABLE_COLLATION FROM information_schema.TABLES')===0){return$this->pdo->query("SELECT 'InnoDB' ENGINE,'Dynamic' ROW_FORMAT,'utf8mb4_unicode_ci' TABLE_COLLATION");}return$this->pdo->query($sql);}
	function sql_fetchrowset($r){return$r->fetchAll(PDO::FETCH_ASSOC);}
	function sql_freeresult($r){$r->closeCursor();}
	function sql_escape($value){return str_replace("'","''",$value);}
}
function former_check($ok,$message){if(!$ok){throw new RuntimeException($message);}$GLOBALS['cases']++;}
$p=new PDO('sqlite::memory:');$p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$p->exec('CREATE TABLE fixture_users(user_id INTEGER PRIMARY KEY,username TEXT)');
$p->exec('CREATE TABLE fixture_removed_author_names(author_user_id INTEGER,author_name_hash TEXT,author_name TEXT COLLATE NOCASE,recorded_at INTEGER,PRIMARY KEY(author_user_id,author_name_hash))');
$p->exec('CREATE TABLE fixture_posts(post_id INTEGER,poster_id INTEGER,post_username TEXT,post_author_removed INTEGER DEFAULT 0,post_edit_user_id INTEGER,post_edit_username TEXT)');
$p->exec('CREATE TABLE fixture_shout(shout_user_id INTEGER,shout_username TEXT,shout_author_removed INTEGER DEFAULT 0)');
$p->exec("INSERT INTO fixture_users VALUES(7,'Retiring name'),(8,'Current editor')");
$p->exec("INSERT INTO fixture_posts VALUES(1,7,'',0,8,'Current editor'),(2,-1,'Retiring name',0,7,'Earlier editor'),(3,-1,'Genuine guest',0,NULL,NULL)");
$p->exec("INSERT INTO fixture_shout VALUES(7,'Retiring name',0),(-1,'Genuine guest',0)");
$phpbb_root_path=$root;require_once$root.'includes/bbcode.php';
class FormerAuthorTemplate{function make_filename($file){return$GLOBALS['root'].'templates/fisubsilversh/bbcode.tpl';}}
$template=new FormerAuthorTemplate();
$cases=0;$keep=array();$db=new FormerAuthorFixture($p);$keep[]=$db;
phpbb_capture_removed_author($db,7,'Retiring name','1=1');
former_check((int)$p->query('SELECT COUNT(*) FROM fixture_removed_author_names')->fetchColumn()===2,'Capture current and earlier editor aliases');
former_check((int)$p->query('SELECT post_author_removed FROM fixture_posts WHERE post_id=1')->fetchColumn()===1&&(int)$p->query('SELECT post_author_removed FROM fixture_posts WHERE post_id=2')->fetchColumn()===0,'Only exact account-owned posts flagged; same-spelling genuine guest retained');
former_check((int)$p->query('SELECT shout_author_removed FROM fixture_shout WHERE shout_user_id=7')->fetchColumn()===1&&(int)$p->query('SELECT shout_author_removed FROM fixture_shout WHERE shout_user_id=-1')->fetchColumn()===0,'Shout classification distinguishes account content and guests');
foreach(array('english','german')as$locale){
 $lang=array();require$root.'language/lang_'.$locale.'/lang_main.php';$board_config=array('hide_removed_author_names'=>'1','default_lang'=>$locale,'default_dateformat'=>'Y-m-d H:i','board_timezone'=>0);$userdata=array('session_logged_in'=>false);
 $db=new FormerAuthorFixture($p);$keep[]=$db;
 former_check(phpbb_author_name(7,'Retiring name')==='Retiring name','Prepared classification never hides a still-existing account');
 $before_delete_tag=phpbb_author_search_cache_tag();
}
$p->exec('DELETE FROM fixture_users WHERE user_id=7');
$p->exec("UPDATE fixture_posts SET poster_id=-1,post_username='Retiring name' WHERE post_id=1");
foreach(array('english','german')as$locale){foreach(array(false,true)as$logged){
 $lang=array();require$root.'language/lang_'.$locale.'/lang_main.php';$board_config=array('hide_removed_author_names'=>'1','default_lang'=>$locale,'default_dateformat'=>'Y-m-d H:i','board_timezone'=>0);$userdata=array('session_logged_in'=>$logged);$db=new FormerAuthorFixture($p);$keep[]=$db;
 $expected=$logged?'Retiring name':$lang['Former_member'];
 if(!$logged){former_check(!phpbb_author_search_cache_allowed(array('author_visibility'=>$before_delete_tag)),'Actual disappearance invalidates an author search cached during pre-delete capture');}
 former_check(phpbb_author_name(7,'Retiring name')===$expected,'Retired identity is distinct for guests; member sees old name');
 former_check(phpbb_post_author_name(array('poster_id'=>-1,'post_username'=>'Retiring name','post_author_removed'=>1))===$expected,'Marked historical post retains member byline');
 former_check(phpbb_post_author_name(array('poster_id'=>-1,'post_username'=>'Retiring name','post_author_removed'=>0))==='Retiring name','Genuine same-spelling guest remains visible');
 former_check(phpbb_author_name(-1,'Earlier editor')===($logged?'Earlier editor':$lang['Former_member']),'Earlier editor/quote alias follows removed identity');
 former_check(phpbb_author_filter_quote_headers('[quote="Earlier editor"]Keep body text[/quote]')==='[quote="'.($logged?'Earlier editor':$lang['Former_member']).'"]Keep body text[/quote]','Anonymous quote composer cannot expose retained speaker metadata; body and member source remain unchanged');
 $html=bbencode_second_pass('[quote:abc="Earlier editor"]Keep Grüße body[/quote:abc]','abc');
 former_check(strpos($html,$logged?'Earlier editor':$lang['Former_member'])!==false&&strpos($html,'Keep Grüße body')!==false&&($logged||strpos($html,'Earlier editor')===false),'Actual BBCode renderer masks only former speaker header, preserving Unicode body and member view');
 $guest_html=bbencode_second_pass('[quote:abc="Genuine guest"]Normal body[/quote:abc]','abc');former_check(strpos($guest_html,'Genuine guest')!==false,'Actual BBCode renderer retains real guest quotation headers');
 former_check(phpbb_author_name(-1,'Genuine guest',false)==='Genuine guest','Unclassified guest remains unchanged');
 former_check(phpbb_author_name(8,'Current <editor> 😀')==='Current &lt;editor&gt; 😀','Current account escaping retained');
 $post=array('post_edit_count'=>2,'post_edit_time'=>1700000000,'post_edit_user_id'=>7,'post_edit_username'=>'Earlier editor');$caption=phpbb_post_edit_caption($post,'Original author');
 former_check(strpos($caption,$logged?'Earlier editor':$lang['Former_member'])!==false,'Recorded retired editor caption follows viewer policy');
 $post['post_edit_user_id']=$post['post_edit_username']=null;$post['post_author_removed']=1;
 former_check(strpos(phpbb_post_edit_caption($post,'Retiring name'),$expected)!==false,'Historical caption follows removed original author without guessing new metadata');
 $sql=phpbb_post_author_search_sql($db,'post_username','Retir*');$matches=$p->query('SELECT post_id FROM fixture_posts WHERE '.$sql.' ORDER BY post_id')->fetchAll(PDO::FETCH_COLUMN);
 former_check(array_map('intval',$matches)===($logged?array(1,2):array(2)),'Author wildcard never conflates genuine guest with removed account');
 former_check(phpbb_removed_author_keyword_search('Retiring name')===!$logged&&phpbb_removed_author_keyword_search('Earli*')===!$logged,'Known former names and keyword patterns cannot reveal indexed quote associations to guests');
 former_check(phpbb_removed_author_keyword_search('Genuine guest')===false,'Ordinary keyword searches remain available');
 former_check(phpbb_author_search_cache_allowed(array())===$logged&&phpbb_author_search_cache_allowed(array('author_visibility'=>'member-or-legacy'))===$logged&&phpbb_author_search_cache_allowed(array('author_visibility'=>phpbb_author_search_cache_tag())),'Guest rejects pre-policy/member saved results');
 if($logged){former_check(!$db->queries || count($db->queries)===0,'Member rendering/search policy adds no registry queries');}
}}
echo 'Former author visibility: ',$cases," capture/guest/member/Unicode/alias/editor/search/wildcard/cache cases PASS; display-only names retained.\n";
