<?php
require_once __DIR__ . '/album-native-seed.php';
if (PHP_SAPI !== 'cli' || getenv('PHPBB_ALBUM_SEARCH_NATIVE') !== '1') { echo "Album search tests require explicit disposable storage.\n"; return; }
define('IN_PHPBB', true);
$table_prefix = 'fixture_'; $phpEx = 'php';
$phpbb_root_path = dirname(dirname(__DIR__)) . '/phpBB2/';
$album_root_path = $phpbb_root_path . 'album_mod/';
require $phpbb_root_path . 'includes/php_compat.php';
require $phpbb_root_path . 'includes/constants.php';
require_once $phpbb_root_path . 'includes/functions_author_visibility.php';
require $phpbb_root_path . 'db/mysqli.php';
require_once $album_root_path . 'album_constants.php';
require $album_root_path . 'album_functions.php';
require $album_root_path . 'album_hierarchy_functions.php';
function as_check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
$as_legacy_requests=isset($argv[1])&&$argv[1]==='legacy';
as_check(!isset($argv[1])||$as_legacy_requests,'Known native request mode');
$as_request_bootstrap='';
if($as_legacy_requests)
{
	$bootstrap=file_get_contents($phpbb_root_path.'common.php');$a=strpos($bootstrap,'$_GET = phpbb_addslashes_recursive($_GET);');$last='$HTTP_COOKIE_VARS = $_COOKIE;';$b=strpos($bootstrap,$last,$a);
	as_check($a!==false&&$b>$a,'Actual complete common request quoting and legacy aliases');$as_request_bootstrap=substr($bootstrap,$a,$b+strlen($last)-$a);
}
class AlbumSearchExit extends RuntimeException {}
function message_die($level, $message) { throw new AlbumSearchExit($message); }
function append_sid($url) { return $url; }
// Load the actual text/date/ID helpers without bootstrapping a live session or
// the terminal HTML error renderer. SQL, ACLs and the complete controller body
// remain the production implementation; only its final template sink is fake.
$functions = file_get_contents($phpbb_root_path . 'includes/functions.php');
foreach (array('phpbb_stored_text', 'phpbb_sql_id_list', 'phpbb_date_number', 'phpbb_date_integer_sum', 'phpbb_date_timestamp', 'phpbb_date_text', 'create_date', 'checkFlag') as $name)
{
	as_check(preg_match('/^function ' . $name . '\([\s\S]*?^\}/m', $functions, $match) === 1, 'Actual core helper ' . $name); eval($match[0]);
}
$common = file_get_contents($album_root_path . 'album_common.php');
foreach (array('album_html_text', 'album_sql_id_list') as $name)
{
	as_check(preg_match('/^function ' . $name . '\([\s\S]*?^\}/m', $common, $match) === 1, 'Actual Album helper ' . $name); eval($match[0]);
}
$program = file_get_contents($phpbb_root_path . 'album_search.php');
$start = strpos($program, "\t\$search_value ="); $end = strpos($program, "\t\$template->pparse('body');", $start);
as_check($start !== false && $end > $start, 'Actual complete search/controller boundary');
$as_program = substr($program, $start, $end - $start);
class AlbumSearchTemplate
{
	var $vars = array(); var $rows = array();
	function assign_vars($vars) { $this->vars = array_merge($this->vars, $vars); }
	function assign_block_vars($name, $vars) { if ($name === 'switch_search_results.search_results') { $this->rows[] = $vars; } }
}
class AlbumSearchDatabase extends sql_db
{
	var $armed = false; var $queries = array(); var $searches = array(); var $search_results = array(); var $freed_searches = 0;
	var $fault = 0; var $phase = ''; var $hit = false; var $on_query = null; var $on_row = null; var $candidate_rows = 0;
	function sql_query($sql = '', $transaction = false)
	{
		if (!$this->armed) { return parent::sql_query($sql, $transaction); }
		as_check(strpos($sql, 'SELECT ') === 0 && !$transaction, 'Actual search is SELECT-only');
		$this->queries[] = $sql; $target = count($this->queries) === $this->fault;
		if (strpos($sql, 'SELECT p.') === 0) { $this->searches[] = $sql; }
		if ($this->on_query) { call_user_func($this->on_query, $sql, count($this->searches)); }
		if ($target && $this->phase === 'before') { $this->hit = true; return false; }
		$result = parent::sql_query($sql, $transaction);
		if ($target && $this->phase === 'after') { $this->hit = true; if ($result) { parent::sql_freeresult($result); } return false; }
		if ($result && strpos($sql, 'SELECT p.') === 0) { $this->search_results[] = $result; }
		return $result;
	}
	function sql_fetchrow($result = 0)
	{
		$row = parent::sql_fetchrow($result);
		if ($this->armed && $row && array_key_exists('pic_id', $row))
		{
			$this->candidate_rows++;
			as_check(!array_key_exists('pic_desc', $row) && !array_key_exists('cat_desc', $row), 'Large descriptions are searched without copying their bytes into candidate rows');
			if ($this->on_row) { call_user_func($this->on_row, $row, $this->candidate_rows); }
		}
		return $row;
	}
	function sql_freeresult($result = 0)
	{
		if ($this->armed && in_array($result, $this->search_results, true)) { $this->freed_searches++; }
		return parent::sql_freeresult($result);
	}
}
function as_sql($sql)
{
	$result = $GLOBALS['control']->sql_query($sql); as_check($result !== false, 'Owned fixture SQL failed: ' . $sql); return $result;
}
function as_insert($table, $row)
{
	$values = array(); foreach ($row as $value) { $values[] = $value === null ? 'NULL' : "CONVERT(X'" . bin2hex((string) $value) . "' USING utf8mb4)"; }
	as_sql('INSERT INTO fixture_' . $table . ' (' . implode(',', array_keys($row)) . ') VALUES (' . implode(',', $values) . ')');
}
function as_picture($id, $category, $owner = 7, $title = 'needle', $time = 100, $author = 'Viewer', $description = '')
{
	as_insert('album', array('pic_id'=>$id, 'pic_cat_id'=>$category, 'pic_user_id'=>$owner, 'pic_filename'=>'synthetic.jpg', 'pic_thumbnail'=>'', 'pic_title'=>$title, 'pic_desc'=>$description, 'pic_username'=>$author, 'pic_time'=>$time, 'pic_approval'=>1));
}
function as_many($first, $last, $category, $time = 200, $owner = 7)
{
	$values = array(); for ($id = $first; $id <= $last; $id++) { $values[] = '(' . $id . ',' . $category . ',' . $owner . ",'synthetic.jpg','','needle','','Viewer'," . $time . ',1)'; }
	as_sql('INSERT INTO fixture_album(pic_id,pic_cat_id,pic_user_id,pic_filename,pic_thumbnail,pic_title,pic_desc,pic_username,pic_time,pic_approval) VALUES' . implode(',', $values));
}
function as_viewer($id = 7, $level = USER)
{
	$GLOBALS['userdata'] = array('user_id'=>$id, 'username'=>'Viewer', 'user_level'=>$level, 'session_logged_in'=>$id > 0, 'session_id'=>str_repeat('a',32));
}
function as_call($term = 'needle', $mode = 'name', $fault = 0, $phase = '', $on_query = null, $on_row = null)
{
	global $db, $template, $userdata, $album_config, $album_data, $board_config, $lang, $as_program, $as_request_bootstrap, $HTTP_GET_VARS, $HTTP_POST_VARS, $HTTP_COOKIE_VARS;
	$_POST = array('search'=>$term, 'mode'=>$mode); $_GET = array(); $template = new AlbumSearchTemplate(); $album_data = array(); $error = null;
	if($as_request_bootstrap!==''){$_COOKIE=array();$_REQUEST=$_POST;eval($as_request_bootstrap);as_check($HTTP_POST_VARS===$_POST&&$HTTP_GET_VARS===$_GET&&$HTTP_COOKIE_VARS===$_COOKIE,'Actual legacy request aliases stay synchronized');}
	$db->queries = $db->searches = $db->search_results = array(); $db->freed_searches = $db->candidate_rows = 0;
	$db->fault = $fault; $db->phase = $phase; $db->hit = false; $db->on_query = $on_query; $db->on_row = $on_row; $db->armed = true;
	try { eval($as_program); } catch (AlbumSearchExit $exception) { $error = $exception->getMessage(); } finally { $db->armed = false; }
	as_check($db->freed_searches === count($db->search_results), 'Every actual candidate result is freed, including terminal failures');
	$ids = array(); foreach ($template->rows as $row) { as_check(preg_match('/pic_id=([0-9]+)$/D', $row['U_PIC'], $m) === 1, 'Actual result picture URL'); $ids[] = $m[1]; }
	return array('error'=>$error, 'ids'=>$ids, 'rows'=>$template->rows, 'vars'=>$template->vars, 'searches'=>$db->searches, 'queries'=>$db->queries, 'hit'=>$db->hit, 'candidates'=>$db->candidate_rows);
}
function as_expect($out, $ids, $message)
{
	global $cases, $lang;
	as_check($out['ids'] === $ids && $out['error'] === ($ids ? null : $lang['No_search_match']), $message . ': ' . json_encode(array($out['ids'], $out['error'])));
	if ($ids) { as_check((int)$out['vars']['L_NRESULTS'] === count($ids), 'Actual visible count matches the rows'); }
	$cases++;
}
$port = getenv('PHPBB_ALBUM_SEARCH_PORT') ?: '3306';
as_check(preg_match('/^[0-9]{1,5}$/D', $port) && (int)$port > 0 && (int)$port <= 65535, 'Owned loopback port');
$host = '127.0.0.1:' . $port; $password = getenv('PHPBB_ALBUM_SEARCH_PASSWORD') ?: '';
$schema = 'codex_album_search_' . bin2hex(phpbb_random_bytes(8)); $created = false; $db = null;
$control = new sql_db($host, 'root', $password, '', false);
$reporting = error_reporting(E_ALL); set_error_handler(function($level, $message) { if (error_reporting() & $level) { throw new RuntimeException($message); } });
try
{
	as_sql('CREATE DATABASE ' . $schema . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'); $created = true; as_sql('USE ' . $schema);
	$db = new AlbumSearchDatabase($host, 'root', $password, $schema, false);
	if (getenv('PHPBB_ALBUM_SEARCH_MYSQL') === '8.4')
	{
		$r = as_sql('SELECT VERSION() version'); $v = $control->sql_fetchrow($r); $control->sql_freeresult($r);
		as_check(strpos($v['version'], '8.4.') === 0 && stripos($v['version'], 'MariaDB') === false, 'Actual MySQL8.4');
	}
	$ddl = file_get_contents($phpbb_root_path . 'install/schemas/mysql_schema.sql');
	foreach (array('album', 'album_cat', 'users', 'user_group', 'album_config', 'removed_author_names') as $table)
	{
		as_check(preg_match('/^CREATE TABLE phpbb_' . $table . '\s*\([\s\S]*?;/m', $ddl, $m) === 1, 'Canonical table ' . $table); as_sql(str_replace('phpbb_' . $table, 'fixture_' . $table, $m[0]));
	}
	as_sql(album_native_config_seed_sql());
	$album_config = array(); $r = as_sql('SELECT * FROM fixture_album_config'); while ($setting = $control->sql_fetchrow($r)) { $album_config[$setting['config_name']] = $setting['config_value']; } $control->sql_freeresult($r); $defaults = $album_config;
	foreach (array(-1=>'Anonymous', 7=>'Viewer', 8=>'Other owner') as $id=>$name) { as_insert('users', array('user_id'=>$id, 'username'=>$name, 'user_password'=>'', 'user_email'=>'', 'user_level'=>USER, 'user_active'=>1)); }
	foreach (array(1=>ALBUM_GUEST, 2=>ALBUM_ADMIN, 3=>ALBUM_PRIVATE, 4=>ALBUM_MOD, 5=>ALBUM_USER, 6=>ALBUM_PRIVATE) as $id=>$level)
	{
		as_insert('album_cat', array('cat_id'=>$id, 'cat_title'=>'Category <' . $id . '>', 'cat_desc'=>str_repeat('large', 3000), 'cat_order'=>$id, 'cat_view_level'=>$level, 'cat_upload_level'=>$id===6?ALBUM_PRIVATE:ALBUM_USER, 'cat_view_groups'=>'9', 'cat_moderator_groups'=>'10', 'cat_user_id'=>$id===6?7:0, 'cat_parent'=>0));
	}
	$cases = $faults = 0;
	as_sql('UPDATE fixture_album_cat SET cat_approval=' . ALBUM_ADMIN . ' WHERE cat_id IN (1,2)');
	foreach (array('english', 'german') as $locale)
	{
		$lang = array(); require $phpbb_root_path . 'language/lang_' . $locale . '/lang_main.php'; require $phpbb_root_path . 'language/lang_' . $locale . '/lang_main_album.php';
		$board_config = array('hide_removed_author_names'=>0, 'default_lang'=>$locale, 'default_dateformat'=>'Y-m-d H:i', 'board_timezone'=>0); $album_config = $defaults; $album_config['personal_gallery']=ALBUM_USER; $album_config['personal_allow_gallery_mod']=1; as_viewer();
		as_sql('DELETE FROM fixture_album'); as_picture(1,1,7,'needle public-only'); as_many(2,501,2);
		as_expect(as_call('public-only'), array('1'), 'Original accessible control');
		$out = as_call(); as_expect($out,array('1'),'500 newer hidden images cannot displace an older public match'); as_check(count($out['searches'])===2 && $out['candidates']===501, 'One denied category needs exactly two bounded queries');
		as_many(502,1001,3,300); $out = as_call(); as_expect($out,array('1'),'Multiple denied contexts cannot displace the public match'); as_check(count($out['searches'])===3,'One additional query per denied context, not per hidden picture');
		as_sql('DELETE FROM fixture_album'); as_many(1,505,1,100); as_picture('4294967295',1,7,'needle',100);
		$expected = array('4294967295'); for ($id=505;$id>=7;$id--) { $expected[]=(string)$id; }
		$out=as_call(); as_expect($out,$expected,'Exactly500 visible results with deterministic ties and unsigned32bit picture IDs'); as_check(count($out['searches'])===1,'Ordinary visible results use one search query');
		as_sql('DELETE FROM fixture_album'); foreach(array(1=>1,2=>2,3=>3,4=>4,5=>5,6=>6)as$id=>$cat){as_picture($id,$cat);}
		as_viewer(-1);as_expect(as_call(),array('1'),'Guest sees public pictures only');
		as_viewer();as_expect(as_call(),array('6','5','1'),'Registered viewer retains own physical gallery override');
		as_insert('user_group',array('group_id'=>9,'user_id'=>7,'user_pending'=>1));as_expect(as_call(),array('6','5','1'),'Pending membership grants no private access');
		as_sql('UPDATE fixture_user_group SET user_pending=0');as_expect(as_call(),array('6','5','3','1'),'Actual private group membership');
		as_sql('DELETE FROM fixture_user_group');as_insert('user_group',array('group_id'=>10,'user_id'=>7,'user_pending'=>0));as_expect(as_call(),array('6','5','4','3','1'),'Actual category moderator override excludes administrator-only pictures');
		as_viewer(7,ADMIN);as_expect(as_call(),array('6','5','4','3','2','1'),'Administrator retains existing access');as_viewer();as_sql('DELETE FROM fixture_user_group');
		// A positive decision is never reused; a later refusal discards the
		// earlier row from that context before the final coherent result batch.
		as_sql('DELETE FROM fixture_album');as_picture(1,1);as_picture(2,3,7,'needle',200);as_picture(3,3,7,'needle',300);as_insert('user_group',array('group_id'=>9,'user_id'=>7,'user_pending'=>0));
		$out=as_call('needle','name',0,'',null,function($row,$number){if($number===2){as_sql('DELETE FROM fixture_user_group');}});as_expect($out,array('1'),'Revoked membership removes earlier accepted rows from the same category');as_check(count($out['searches'])===2,'Revoked context is excluded on restart');
		as_sql('DELETE FROM fixture_album');as_picture(1,1);as_picture(2,2,7,'needle',200);
		$out=as_call('needle','name',0,'',function($sql,$number){if(strpos($sql,'SELECT p.')===0&&$number===2){as_picture(3,1,7,'needle',500);}});as_expect($out,array('3','1'),'Restart includes newly arrived public picture in correct order');
		as_sql('DELETE FROM fixture_album');as_picture(1,1);as_many(2,501,0,200,8);as_picture(502,0,7,'needle',300);as_picture(503,999);as_picture(504,0,123456);as_picture(505,0,0);
		$album_config['personal_gallery_view']=ALBUM_PRIVATE;$album_config['personal_gallery']=ALBUM_USER;$album_config['personal_allow_gallery_mod']=1;
		$out=as_call();as_expect($out,array('502','1'),'Legacy owner gallery remains visible despite500 newer denied personal pictures; orphans safely excluded');as_check(count($out['searches'])===2,'Only denied personal owner is excluded');
		as_sql('DELETE FROM fixture_album');as_picture(1,0,-1);as_viewer(-1);$album_config['personal_gallery_view']=ALBUM_GUEST;as_expect(as_call(),array('1'),'Existing Anonymous sentinel gallery remains readable without owner privileges');
		as_viewer();$album_config=$defaults;as_sql('DELETE FROM fixture_album');as_picture(1,1,7,'needle');as_picture(2,2,7,'needle');as_sql('UPDATE fixture_album SET pic_approval=0 WHERE pic_id=1');as_expect(as_call(),array(),'Unapproved and all-hidden rows produce localized no-match result');
		as_viewer(7,ADMIN);as_expect(as_call(),array('2'),'Approval remains mandatory even for administrator searches');as_viewer();
		as_sql('UPDATE fixture_album_cat SET cat_approval=' . ALBUM_USER . ' WHERE cat_id=1');
		foreach(array(array(-1,USER),array(7,USER),array(7,ADMIN))as$viewer){as_viewer($viewer[0],$viewer[1]);as_expect(as_call(),$viewer[1]===ADMIN?array('2','1'):array('1'),'Disabling category approval exposes old zero-flag pictures to every authorized viewer');}
		as_sql('UPDATE fixture_album_cat SET cat_approval=' . ALBUM_ADMIN . ' WHERE cat_id=1');as_viewer();
		as_sql('DELETE FROM fixture_album');as_picture(1,1,-1,'needle',100,'Former Name');as_picture(2,1,-1,'needle',100,'Real Guest');
		if($locale==='english'){as_insert('removed_author_names',array('author_user_id'=>0,'author_name_hash'=>hash('sha256','Former Name'),'author_name'=>'Former Name','recorded_at'=>1));}
		$board_config['hide_removed_author_names']=1;as_viewer(-1);as_expect(as_call('Former Name','user'),array(),'Guest cannot search removed author metadata');as_expect(as_call('Real Guest','user'),array('2'),'Genuine guest author remains searchable');
		$out=as_call();as_expect($out,array('2','1'),'Title search still returns former-author picture');as_check($out['rows'][1]['L_USERNAME']===$lang['Former_member'],'Former author byline remains anonymized');
		as_viewer();as_expect(as_call('Former Name','user'),array('1'),'Logged-in viewer retains original author search');$board_config['hide_removed_author_names']=0;
		as_sql('DELETE FROM fixture_album');$literal="Grüße ' & <x> 😀";as_picture(1,1,7,$literal,100,'Viewer',str_repeat('large \\ ',3000).'description needle');
		foreach(array('','NO_BACKSLASH_ESCAPES','ANSI_QUOTES')as$sql_mode){as_check($db->sql_query("SET SESSION sql_mode='".$sql_mode."'")!==false,'Owned SQL mode');as_expect(as_call($literal),array('1'),'Quoted/UTF8 search under'.$sql_mode);as_expect(as_call('description needle','desc'),array('1'),'Large descriptions still searched in SQL');}
		$out=as_call('%');as_expect($out,array('1'),'Existing SQL LIKE wildcard behavior preserved');as_check($out['rows'][0]['L_PIC']===htmlspecialchars($literal,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'),'Actual result text escaped once');
		as_expect(as_call('0'),array(),'Scalar zero term is not treated as empty');
		foreach(array(array('needle','bad'),array(str_repeat('x',101),'name'),array("x\0y",'name'))as$request){$out=as_call($request[0],$request[1]);as_check($out['error']===$lang['Album_Search_Invalid']&&!$out['queries']&&!$out['rows'],'Invalid request fails before SQL');$cases++;}
	}
	// Exercise every real SQL call in a small mixed public/private search.
	as_sql('DELETE FROM fixture_album');as_sql('DELETE FROM fixture_user_group');as_picture(1,1);as_picture(2,3);$baseline=as_call();as_expect($baseline,array('1'),'Fault scenario positive control');
	foreach(array('before','after')as$phase){for($target=1;$target<=count($baseline['queries']);$target++){$out=as_call('needle','name',$target,$phase);as_check($out['hit']&&$out['error']!==null&&!isset($out['vars']['L_NRESULTS'])&&!$out['rows'],'SQL failure cannot render a partial successful search');$faults++;}}
	$r=as_sql('SELECT COUNT(*) total FROM fixture_album');$row=$control->sql_fetchrow($r);$control->sql_freeresult($r);as_check($row['total']==='2','Actual read-only search leaves the fixture pictures intact');
	echo ($as_legacy_requests?'Album search escaped request native: ':'Album search native: ').$cases.' actual controller/ACL cases, '.$faults." SQL failures PASS; visible500 limit, stable unsigned IDs, group and owner rights, coherent retries, EN/DE and former-author privacy.\n";
	// Retain every original case above. Registered author names must agree with
	// the current account, while real guests and retired accounts keep their
	// existing stored-name and privacy rules.
	$original_cases=$cases;
	as_insert('removed_author_names',array('author_user_id'=>8,'author_name_hash'=>hash('sha256','OldHandle'),'author_name'=>'OldHandle','recorded_at'=>1));
	foreach(array('english','german')as$locale)
	{
		$lang=array();require $phpbb_root_path.'language/lang_'.$locale.'/lang_main.php';require $phpbb_root_path.'language/lang_'.$locale.'/lang_main_album.php';
		$board_config=array('hide_removed_author_names'=>1,'default_lang'=>$locale,'default_dateformat'=>'Y-m-d H:i','board_timezone'=>0);$album_config=$defaults;
		foreach(array('','NO_BACKSLASH_ESCAPES','ANSI_QUOTES')as$sql_mode)
		{
			as_check($db->sql_query("SET SESSION sql_mode='".$sql_mode."'")!==false,'Current author fixture SQL mode');
			as_sql('DELETE FROM fixture_album');as_sql('DELETE FROM fixture_user_group');as_sql('DELETE FROM fixture_users WHERE user_id=8');
			$current_name="Neuer Grüße O'Neil";
			as_insert('users',array('user_id'=>8,'username'=>$current_name,'user_password'=>'','user_email'=>'','user_level'=>USER,'user_active'=>1));
			as_picture(700,1,8,'by-title-700',100,'OldHandle');as_picture(701,1,-1,'Guest picture',100,'Named Guest');as_picture(702,1,0,'Zero guest picture',100,'Zero Guest');as_picture(703,1,9,'Missing account picture',100,'Unclassified missing');
			as_viewer();$out=as_call($current_name,'user');as_expect($out,array('700'),'Current registered username finds the existing upload');
			as_check($out['rows'][0]['L_USERNAME']===htmlspecialchars($current_name,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')&&strpos($out['rows'][0]['U_PROFILE'],'u=8')!==false,'Current registered byline is escaped once and links to its actual account');
			as_expect(as_call('OldHandle','user'),array(),'Previous upload name no longer identifies an existing renamed account');
			as_viewer(-1);as_expect(as_call($current_name,'user'),array('700'),'Future retirement marker does not mask a currently existing account');
			as_expect(as_call('Named Guest','user'),array('701'),'True anonymous guest keeps the submitted name');
			as_expect(as_call('Anonymous','user'),array(),'Anonymous sentinel username is not substituted for guest names');
			as_expect(as_call('Zero Guest','user'),array('702'),'Legacy zero guest identity keeps the submitted name');
			as_sql('DELETE FROM fixture_users WHERE user_id=8');
			as_expect(as_call($current_name,'user'),array(),'Missing account cannot supply its former current username');
			as_expect(as_call('OldHandle','user'),array(),'Retired account name search remains hidden from guests');
			$out=as_call('by-title-700','name');as_expect($out,array('700'),'Title search still returns the visible retired-author upload');as_check($out['rows'][0]['L_USERNAME']===$lang['Former_member'],'Retired upload byline retains localized guest privacy');
			as_viewer();$out=as_call('OldHandle','user');as_expect($out,array('700'),'Signed-in readers retain the stored retired-author name');as_check($out['rows'][0]['L_USERNAME']==='OldHandle','Retired name is not replaced for signed-in readers');
			as_viewer(-1);as_expect(as_call('Unclassified missing','user'),array('703'),'Missing unclassified account retains historical stored author data');
		}
	}
	echo ($as_legacy_requests?'Album current author search escaped native: ':'Album current author search native: ').($cases-$original_cases)." cases PASS; actual registered renames/bylines, EN/DE, three SQL modes, true guests and missing/former-account privacy.\n";
}
finally
{
	if($db){$db->armed=false;$db->sql_close();}
	if($created){as_check(preg_match('/^codex_album_search_[a-f0-9]{16}$/D',$schema)===1,'Exact own cleanup namespace');as_check($control->sql_query('DROP DATABASE '.$schema)!==false,'Remove only owned search database');}
	$control->sql_close();restore_error_handler();error_reporting($reporting);
}
