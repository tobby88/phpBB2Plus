<?php
// Run inside the existing owned Album fixture; exercise the actual legacy
// controller prefix, stopping only at the redirect or its unchanged old reader.
apt_check(isset($created,$schema) && $created && preg_match('/^codex_album_pages_[a-f0-9]{16}$/D',$schema), 'Owned personal-route fixture required');
class AlbumPersonalRouteRedirect extends RuntimeException { var $url; function __construct($url){$this->url=$url;parent::__construct('Actual gallery redirect');} }
function redirect($url){throw new AlbumPersonalRouteRedirect($url);}
class AlbumPersonalRouteSource extends AlbumPageSource
{
	var $armed=false,$queries=array(),$failure='',$hit=false;
	function sql_query($sql='',$transaction=false)
	{
		if(!$this->armed){return parent::sql_query($sql,$transaction);}
		$this->queries[]=$sql;apt_check(strpos($sql,'SELECT ')===0 && !$transaction,'Route performs no storage mutation or transaction control');
		$target=strpos($sql,'SELECT pic_id FROM fixture_album WHERE pic_cat_id=0 AND pic_user_id=')===0;
		if($target && $this->failure==='before'){$this->hit=true;return false;}
		$result=parent::sql_query($sql,$transaction);
		if($target && $this->failure==='after'){$this->hit=true;if($result){parent::sql_freeresult($result);}return false;}
		return $result;
	}
}
$apr_source=file_get_contents($phpbb_root_path.'album_personal.php');
$apr_begin=strpos($apr_source,"if( isset(\$_POST['user_id'])");$apr_end=strpos($apr_source,'$sql = "SELECT username',$apr_begin);
apt_check($apr_begin!==false && $apr_end>$apr_begin,'Actual complete old-route request/dispatch prefix');$apr_program=substr($apr_source,$apr_begin,$apr_end-$apr_begin);
function apr_snapshot(){ $out=array();foreach(array('album','album_cat','users','user_group') as $table){$rows=array_map('serialize',apt_rows('SELECT * FROM fixture_'.$table));sort($rows,SORT_STRING);$out[$table]=$rows;}return $out; }
function apr_route($get=array(),$post=array())
{
	global $db,$userdata,$album_config,$phpEx,$lang,$apr_program;
	$_GET=$get;$_POST=$post;$before=apr_snapshot();$db->queries=array();$db->hit=false;$db->armed=true;$out=array('url'=>null,'legacy'=>false,'error'=>null);
	try{eval($apr_program);$out['legacy']=$legacy_personal_gallery;$out['user_id']=$user_id;}
	catch(AlbumPersonalRouteRedirect $redirect){$out['url']=$redirect->url;}
	catch(AlbumPageExit $error){$out['error']=$error->getMessage();}
	finally{$db->armed=false;apt_check(apr_snapshot()===$before,'Route preserves exact picture/category/account/group rows');}
	$out['queries']=$db->queries;$out['hit']=$db->hit;$GLOBALS['apr_cases']++;return $out;
}
function apr_expect_url($get,$post,$expected)
{
	$out=apr_route($get,$post);apt_check($out['url']===$expected && !$out['legacy'] && $out['error']===null,'Actual canonical route: '.json_encode($out));return $out;
}
function apr_setting($name,$value){apt_sql("UPDATE fixture_album_config SET config_value='".$GLOBALS['peer']->sql_escape($value)."' WHERE config_name='".$GLOBALS['peer']->sql_escape($name)."'");$GLOBALS['album_config'][$name]=$value;}
$apr_original=array($db,$userdata,$album_config,$album_data,$board_config,$lang,$_GET,$_POST);
$apr_cases=0;$apr_canonical_views=0;$route_db=null;
try
{
	$apt_fault=0;$apt_hook=null;$apt_factory='normal';$route_db=new AlbumPersonalRouteSource($host,'root',$password,$schema,false);$db=$route_db;
	apt_sql('DELETE FROM fixture_album');apt_sql('DELETE FROM fixture_album_cat');apt_sql('DELETE FROM fixture_user_group');
	apt_insert('album_cat',array('cat_id'=>100,'cat_title'=>'Current gallery','cat_desc'=>'','cat_order'=>100,'cat_user_id'=>8,'cat_view_level'=>ALBUM_GUEST,'cat_approval'=>ALBUM_USER));apt_picture(600,100,1,8);
	apr_setting('personal_gallery_view',(string)ALBUM_GUEST);apr_setting('personal_gallery_private','9');apt_actor();
	apr_expect_url(array('user_id'=>'8'),array(),'album.php?user_id=8');
	// The target really renders the modern picture omitted by the old zero query.
	foreach(array('english','german') as $locale)
	{
		$lang=array();require $phpbb_root_path.'language/lang_'.$locale.'/lang_main.php';require $phpbb_root_path.'language/lang_'.$locale.'/lang_main_album.php';$board_config=array('board_timezone'=>0,'default_dateformat'=>'Y-m-d H:i','default_lang'=>$locale,'hide_removed_author_names'=>0);
		$c=apt_controller('album_personal','pic',0,'',100,8,'100');apt_check($c['total']===1 && count($c['template']->rows['index_pics_block.picrow.piccol'])===1 && strpos($c['template']->rows['index_pics_block.picrow.piccol'][0]['U_PIC'],'pic_id=600')!==false,'Actual canonical target renders modern personal picture in both locales');$apr_canonical_views++;
	}
	apr_expect_url(array(),array(),'album.php?user_id=7');
	foreach(array('0','-1','-99') as $id){apr_expect_url(array('user_id'=>$id),array(),'album.php?user_id=7');}
	apr_expect_url(array('user_id'=>'9'),array('user_id'=>'8'),'album.php?user_id=8');
	apr_expect_url(array('user_id'=>'8'),array('user_id'=>array('9')),'album.php?user_id=8');
	apr_expect_url(array('user_id'=>array('8')),array(),'album.php?user_id=7');
	foreach(array('pic_time','pic_title','username','pic_view_count','rating','comments','new_comment') as $sort){foreach(array('ASC','DESC') as $order){apr_expect_url(array('user_id'=>'8','start'=>'3','sort_method'=>$sort,'sort_order'=>$order),array(),'album.php?user_id=8&start=3&sort_method='.$sort.'&sort_order='.$order);}}
	apr_expect_url(array('user_id'=>'8','start'=>'999999999999'),array(),'album.php?user_id=8&start=1000000');
	apr_expect_url(array('user_id'=>'8','start'=>'-8'),array(),'album.php?user_id=8');
	apr_expect_url(array('user_id'=>'8','start'=>array('1')),array('start'=>'2'),'album.php?user_id=8');
	apr_expect_url(array('user_id'=>'8'),array('start'=>'4','sort_method'=>'rating','sort_order'=>'DESC'),'album.php?user_id=8&start=4&sort_method=rating&sort_order=DESC');
	apr_expect_url(array('user_id'=>'8','start'=>'3;DROP TABLE','sort_method'=>'pic_time DESC;DROP','sort_order'=>'desc','mode'=>'list','sid'=>'foreign','redirect'=>'https://example.invalid/'),array(),'album.php?user_id=8&start=3');
	apt_actor(-1);apr_expect_url(array(),array(),'login.php?redirect=album_personal.php');
	apr_expect_url(array('user_id'=>'8'),array(),'album.php?user_id=8');
	apt_picture(601,0,1,9);apr_expect_url(array('user_id'=>'8'),array(),'album.php?user_id=8');
	$out=apr_route(array('user_id'=>'9'));apt_check($out['legacy'] && $out['url']===null && $out['error']===null && $out['user_id']===9,'Another account retains its separate legacy reader');
	apt_picture(602,0,1,8);$out=apr_route(array('user_id'=>'8'));apt_check($out['legacy'] && $out['url']===null && $out['error']===null,'Permitted legacy zero rows are not hidden by modern dispatch');
	apt_actor();apt_picture(603,0,1,ANONYMOUS);$out=apr_route(array('user_id'=>(string)ANONYMOUS));apt_check($out['legacy'] && $out['url']===null && $out['error']===null && $out['user_id']===ANONYMOUS,'Permitted historical anonymous pictures retain their original guest collection identity');
	apt_actor(-1);$out=apr_expect_url(array('user_id'=>(string)ANONYMOUS),array(),'login.php?redirect=album_personal.php');apt_check(!$out['queries'],'Anonymous collection still requires login before a presence probe');
	apr_setting('personal_gallery_view',(string)ALBUM_USER);$out=apr_expect_url(array('user_id'=>'8'),array(),'album.php?user_id=8');apt_check(!array_filter($out['queries'],function($sql){return strpos($sql,'SELECT pic_id FROM fixture_album ')===0;}),'Denied legacy view never probes private zero-picture presence');
	$c=apt_controller('album_personal','pic',0,'',100,8,'100');apt_check($c['total']===1 && count($c['template']->rows['index_pics_block.picrow.piccol'])===1 && strpos($c['template']->rows['index_pics_block.picrow.piccol'][0]['U_PIC'],'pic_id=600')!==false,'Canonical category rights permit the public modern picture without exposing private legacy-zero data');$apr_canonical_views++;
	apt_actor();apr_setting('personal_gallery_view',(string)ALBUM_PRIVATE);apr_expect_url(array('user_id'=>'8'),array(),'album.php?user_id=8');
	apt_insert('user_group',array('group_id'=>9,'user_id'=>7,'user_pending'=>1));apr_expect_url(array('user_id'=>'8'),array(),'album.php?user_id=8');apt_sql('UPDATE fixture_user_group SET user_pending=0');
	$out=apr_route(array('user_id'=>'8'));apt_check($out['legacy'] && !$out['error'],'Only current nonpending membership permits the old private gallery');apt_sql('DELETE FROM fixture_user_group');
	apr_setting('personal_gallery_view',(string)ALBUM_GUEST);
	foreach(array('before','after') as $phase){$route_db->failure=$phase;$out=apr_route(array('user_id'=>'8'));apt_check($out['hit'] && $out['url']===null && !$out['legacy'] && $out['error']==='Could not query personal gallery routing','Storage failure cannot silently discard a possible legacy read path');}$route_db->failure='';
	apt_sql('DELETE FROM fixture_album WHERE pic_cat_id=0');
	apt_check($db->sql_query('START TRANSACTION')!==false && $db->sql_query("UPDATE fixture_users SET username='Pending caller' WHERE user_id=8")!==false,'Owned pending caller transaction');
	apr_expect_url(array('user_id'=>'8'),array(),'album.php?user_id=8');apt_check(apt_rows('SELECT username FROM fixture_users WHERE user_id=8')[0]['username']==='Other','Route leaves caller work uncommitted');apt_check($db->sql_query('ROLLBACK')!==false && apt_rows('SELECT username FROM fixture_users WHERE user_id=8')[0]['username']==='Other','Caller rollback remains available');
	echo 'Album personal route native: '.$apr_cases.' cases, '.$apr_canonical_views." target views PASS; actual request/redirect prefix, bounded sort/page options, modern pictures, permitted legacy-zero data, private/pending groups, storage failures and no row writes.\n";
}
finally
{
	if($route_db){$route_db->armed=false;$route_db->sql_query('ROLLBACK');$route_db->sql_close();}
	$db=$apr_original[0];$userdata=$apr_original[1];$album_config=$apr_original[2];$album_data=$apr_original[3];$board_config=$apr_original[4];$lang=$apr_original[5];$_GET=$apr_original[6];$_POST=$apr_original[7];
}
