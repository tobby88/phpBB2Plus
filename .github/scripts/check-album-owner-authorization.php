<?php
define('IN_PHPBB',true); define('ADMIN',1); define('GENERAL_ERROR',202);
define('GENERAL_MESSAGE',200);
define('USERS_TABLE','fixture_users'); define('USER_GROUP_TABLE','fixture_members');
$table_prefix='fixture_'; $root=dirname(dirname(__DIR__));
require $root.'/phpBB2/album_mod/album_constants.php';
require $root.'/phpBB2/album_mod/album_functions.php';
require $root.'/phpBB2/album_mod/album_hierarchy_auth.php';
require $root.'/phpBB2/album_mod/album_hierarchy_sql.php';
function checkFlag($value,$flag) { return ($value & $flag)===$flag; }
function album_is_debug_enabled() { return false; }
function album_sql_id_list($ids) { return implode(',',array_map('intval',explode(',',$ids))); }
class AlbumOwnerFailure extends RuntimeException {}
function message_die($type,$message) { throw new AlbumOwnerFailure($message); }
function owner_check($ok,$message) { if(!$ok){throw new RuntimeException($message);} }
class AlbumOwnerResult { var $rows; var $offset=0; function __construct($rows){$this->rows=$rows;} }
class AlbumOwnerDatabase
{
	var $queries=array(); var $pdo; var $failure=false;
	function __construct()
	{
		$this->pdo=new PDO('sqlite::memory:'); $this->pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
		$this->pdo->exec('CREATE TABLE fixture_users(user_id INTEGER, username TEXT)');
		$this->pdo->exec("INSERT INTO fixture_users VALUES(7,'Grüße'),(8,'Other')");
		$this->pdo->exec('CREATE TABLE fixture_members(user_id INTEGER,group_id INTEGER,user_pending INTEGER)');
		$this->pdo->exec('INSERT INTO fixture_members VALUES(8,10,0),(9,10,1)');
	}
	function sql_query($sql){$this->queries[]=$sql;return $this->failure?false:new AlbumOwnerResult($this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC));}
	function sql_fetchrow($r){return isset($r->rows[$r->offset])?$r->rows[$r->offset++]:false;}
	function sql_numrows($r){return count($r->rows);}
	function sql_freeresult($r){}
}
function owner_category($owner)
{
	$cat=array('cat_id'=>10,'cat_user_id'=>$owner,'cat_title'=>'Private','cat_moderator_groups'=>'');
	foreach(array('view','upload','rate','comment','edit','delete') as $key){$cat['cat_'.$key.'_level']=ALBUM_PRIVATE;$cat['cat_'.$key.'_groups']='';}
	return $cat;
}
$db=new AlbumOwnerDatabase(); $lang=array('Guest'=>'Gast','No_such_user'=>'No such user','Category_not_exist'=>'Missing category');
$album_config=array('personal_gallery'=>ALBUM_USER,'personal_allow_gallery_mod'=>1,'personal_allow_sub_categories'=>1,'personal_sub_category_limit'=>5,'rate'=>1,'comment'=>1,'personal_gallery_view'=>ALBUM_USER);
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
try
{
	foreach(array(-1,0,7) as $viewer)
	{
		$userdata=array('user_id'=>$viewer,'session_logged_in'=>false,'user_level'=>0);
		$cat=owner_category($viewer);
		$auth=album_permissions($viewer,10,ALBUM_AUTH_ALL,$cat);
		owner_check(array_sum($auth)===0,'Logged-out identities must never become gallery owners');
	}
	foreach(array(-1,0) as $viewer)
	{
		$userdata=array('user_id'=>$viewer,'session_logged_in'=>true,'user_level'=>ADMIN);
		owner_check(array_sum(album_permissions($viewer,10,ALBUM_AUTH_ALL,owner_category($viewer)))===0,'Sentinel identity cannot be an owner/admin even with stale flags');
		owner_check(array_sum(personal_gallery_access(1,1))===0,'Sentinel identity cannot use registered personal gallery privileges');
	}
	$userdata=array('user_id'=>7,'session_logged_in'=>true,'user_level'=>0);
	$auth=album_permissions(7,10,ALBUM_AUTH_ALL,owner_category(7));
	owner_check($auth['upload']===1 && $auth['moderator']===1 && $auth['manage']===1 && $auth['view']===1,'Authenticated positive owner retains gallery management');
	$userdata=array('user_id'=>8,'session_logged_in'=>true,'user_level'=>0);
	owner_check(array_sum(album_permissions(7,10,ALBUM_AUTH_ALL,owner_category(7)))===0,'Unrelated member has no private gallery rights');
	$cat=owner_category(7); $cat['cat_moderator_groups']='10'; $cat['cat_delete_level']=ALBUM_ADMIN;
	$auth=album_permissions(7,10,ALBUM_AUTH_ALL,$cat);
	owner_check($auth['view']===1 && $auth['moderator']===1 && $auth['delete']===0 && $auth['manage']===0,'Confirmed group moderator retains scoped access without nonexistent moderator-level field');
	$userdata['user_id']=9;
	owner_check(array_sum(album_permissions(7,10,ALBUM_AUTH_ALL,$cat))===0,'Pending group membership cannot grant moderation');
	$userdata=array('user_id'=>8,'session_logged_in'=>false,'user_level'=>ADMIN);
	owner_check(array_sum(album_permissions(7,10,ALBUM_AUTH_ALL,$cat))===0,'Stale logged-out admin level cannot bypass category checks');
	$userdata['session_logged_in']=true;
	owner_check(array_sum(album_permissions(7,10,ALBUM_AUTH_ALL,$cat))===8,'Authenticated administrator retains full personal gallery access');
	$userdata=array('user_id'=>-1,'session_logged_in'=>false,'user_level'=>0);
	$cat=owner_category(0); $cat['cat_view_level']=ALBUM_GUEST; $cat['cat_rate_level']=ALBUM_GUEST; $cat['cat_comment_level']=ALBUM_GUEST;
	$auth=album_permissions(0,10,ALBUM_AUTH_ALL,$cat);
	owner_check($auth['view']===1 && $auth['rate']===1 && $auth['comment']===1 && $auth['moderator']===0 && $auth['manage']===0,'Explicit guest view/rating/comment configuration is preserved');
	owner_check(album_get_user_name(7)==='Grüße' && album_get_user_name('8')==='Other','Valid gallery owners resolve their stored display names');
	owner_check(album_get_user_name(1234)==='Gast','Removed owner has a warning-free localized fallback');
	$before=count($db->queries);
	foreach(array(-1,'-1',null,array(7),'7 OR 1=1',true) as $id){owner_check(album_get_user_name($id)==='Gast','Malformed or guest owner fallback');}
	owner_check(album_get_user_name(0)==='' && count($db->queries)===$before,'Public/invalid owner lookup does not query unrelated accounts');
	$db->failure=true; $caught=false;
	try{album_get_user_name(7);}catch(AlbumOwnerFailure $e){$caught=true;}
	owner_check($caught,'Owner database failure remains explicit rather than pretending deletion');
	$db->failure=false;
	$personal=file_get_contents($root.'/phpBB2/album_personal.php');
	$start=strpos($personal,'$row = $db->sql_fetchrow($result);'); $end=strpos($personal,'$username_html =',$start);
	owner_check($start!==false && $end!==false,'Personal owner-check extraction markers');
	$result=new AlbumOwnerResult(array()); $caught=false;
	try{eval(substr($personal,$start,$end-$start));}catch(AlbumOwnerFailure $e){$caught=$e->getMessage()==='No such user';}
	owner_check($caught,'Missing legacy personal gallery owner is rejected without undefined-row warnings');
	// Resolve actual category ACLs without a prebuilt hierarchy cache.
	$cat=owner_category(0); $cat['cat_view_level']=ALBUM_GUEST; $cat['cat_comment_level']=ALBUM_USER;
	$columns=array(); $values=array();
	foreach($cat as $key=>$value){$columns[]=$key.' '.(is_int($value)?'INTEGER':'TEXT');$values[]=is_int($value)?$value:$db->pdo->quote($value);}
	$db->pdo->exec('CREATE TABLE fixture_album_cat ('.implode(',',$columns).')');
	$db->pdo->exec('INSERT INTO fixture_album_cat VALUES('.implode(',',$values).')');
	$album_data=array(); $userdata=array('user_id'=>-1,'session_logged_in'=>false,'user_level'=>0);
	$auth=album_get_auth_data(10);
	owner_check($auth['view']===1 && $auth['comment']===0,'Uncached category lookup must request all permission flags');
	foreach(array(null,false,'bad',array('keys'=>array()),array('auth'=>null),array('auth'=>'bad'),array('auth'=>array()),array('auth'=>array(10=>null))) as $cache)
	{
		$album_data=$cache; $before=count($db->queries); $auth=album_get_auth_data('10');
		owner_check($auth['view']===1 && $auth['comment']===0 && count($db->queries)===$before+1,'Incomplete cache safely falls back to actual category permissions');
	}
	$userdata=array('user_id'=>7,'session_logged_in'=>true,'user_level'=>0); $album_data=array();
	$auth=album_get_auth_data(10); owner_check($auth['view']===1 && $auth['comment']===1,'Uncached member lookup retains registered permissions');
	$album_data=array('auth'=>array(10=>$auth)); $before=count($db->queries);
	owner_check(album_get_auth_data(10)===$auth && count($db->queries)===$before,'Usable request-local cache is preserved');
	$album_data=array('auth'=>array(10=>array()));
	owner_check(album_get_auth_data(10)===array(),'Explicit empty permission entry remains denied');
	$album_data=array();
	foreach(array(ALBUM_ROOT_CATEGORY,0,null,true,array(10),'10 OR 1=1',-2) as $id){owner_check(album_get_auth_data($id)===false,'Invalid or uncached virtual category cannot grant access');}
	owner_check(count($db->queries)===$before,'Invalid and virtual IDs do not query unrelated categories');
	$album_data=array('auth'=>array(ALBUM_ROOT_CATEGORY=>$auth));
	owner_check(album_get_auth_data(ALBUM_ROOT_CATEGORY)===$auth,'Known virtual-root cache entry remains usable');
	$album_data=array(); $caught=false;
	try{album_get_auth_data(999);}catch(AlbumOwnerFailure $e){$caught=$e->getMessage()==='Missing category';}
	owner_check($caught,'Missing actual category retains explicit rejection');
	$db->failure=true; $caught=false;
	try{album_get_auth_data(10);}catch(AlbumOwnerFailure $e){$caught=true;}
	$db->failure=false; owner_check($caught,'Uncached database failure is not hidden');
	foreach(array(false,null,'bad',array()) as $data){owner_check(!album_check_permission($data,ALBUM_AUTH_VIEW),'Absent permission data fails closed');}
	foreach(array(array(),2,'1junk',null) as $grant){owner_check(!album_check_permission(array('view'=>$grant),ALBUM_AUTH_VIEW),'Malformed grant cannot authorize');}
	foreach(array(1,'1',true) as $grant){owner_check(album_check_permission(array('view'=>$grant),ALBUM_AUTH_VIEW),'Supported scalar grant representation retained');}
	$partial=array('view'=>1);
	owner_check(!album_check_permission($partial,ALBUM_AUTH_VIEW|ALBUM_AUTH_COMMENT) && album_check_permission($partial,ALBUM_AUTH_VIEW|ALBUM_AUTH_COMMENT,true),'AND/OR semantics preserved with absent fields denied');
	foreach(array(256,-1,array(1),'1') as $mask){owner_check(!album_check_permission($partial,$mask,true),'Unknown/malformed requested bits fail closed');}
	owner_check(album_check_permission($partial,0),'Empty check retains its existing no-requirements meaning');
	owner_check(album_build_auth_list(0,0,false)==='','Unavailable auth list is empty rather than a PHP8 TypeError');
	$category_source=file_get_contents($root.'/phpBB2/album_cat.php');
	$start=strpos($category_source,"if( isset(\$_POST['user_id']) )"); $end=strpos($category_source,'// END check request',$start);
	owner_check($start!==false && $end!==false,'Category request extraction markers');
	$request_block=substr($category_source,$start,$end-$start);
	foreach(array('get','post') as $method)
	{
		foreach(array('user_id','cat_id') as $field)
		{
			$_POST=array(); $_GET=array('cat_id'=>10);
			if($method==='post'){$_POST[$field]=array(10);}else{$_GET[$field]=array(10);}
			$caught=false; try{eval($request_block);}catch(AlbumOwnerFailure $e){$caught=$e->getMessage()==='Missing category';}
			owner_check($caught,'Array category/owner parameter is explicitly rejected');
		}
		$_POST=array(); $_GET=array('cat_id'=>10);
		if($method==='post'){$_POST['mode']=array('list');}else{$_GET['mode']=array('list');}
		eval($request_block); owner_check($album_view_mode==='','Array view mode safely falls back to normal mode');
	}
	$_POST=array(); $_GET=array('cat_id'=>'10','user_id'=>'7','mode'=>'LIST'); eval($request_block);
	owner_check($cat_id===10 && $album_user_id===7 && $album_view_mode===ALBUM_VIEW_LIST,'Normal scalar category requests retain behavior');
	$start=strpos($category_source,"if (!isset(\$album_data['keys'])"); $end=strpos($category_source,'$thiscat = $album_data',$start);
	owner_check($start!==false && $end!==false,'Category cache guard extraction markers');
	foreach(array(null,array(),array('keys'=>null),array('keys'=>array())) as $cache)
	{
		$album_data=$cache; $caught=false;
		try{eval(substr($category_source,$start,$end-$start));}catch(AlbumOwnerFailure $e){$caught=$e->getMessage()==='Missing category';}
		owner_check($caught,'Missing category tree returns a controlled error without array_key_exists TypeError');
	}
	echo "Album owner authorization and missing-owner checks passed.\n";
}
finally {restore_error_handler();}
