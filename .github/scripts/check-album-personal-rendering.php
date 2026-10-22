<?php
// Execute the complete personal-gallery controller with synthetic read-only
// dependencies. No forum bootstrapping, network, accounts or database writes.
define('IN_PHPBB', true);
$table_prefix = 'fixture_';
$source = dirname(dirname(__DIR__)) . '/phpBB2/';
require $source . 'includes/functions.php';
require $source . 'album_mod/album_constants.php';
$common = file_get_contents($source . 'album_mod/album_common.php');
if (preg_match('/^function album_html_text\(.*?^\}/ms', $common, $helper) !== 1)
{ throw new RuntimeException('Actual Album stored-text boundary changed'); }
eval($helper[0]);
function apr_check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
function album_find_user_name($id) { apr_check($id === 7, 'Exact synthetic owner'); return $GLOBALS['apr_name']; }
function album_get_personal_root_id($id) { return 10; }
function album_permissions($owner, $category, $mask, $row) { return array('view'=>1,'upload'=>1); }
function album_check_permission($data, $mask) { return true; }
function album_has_sub_cats($id) { return false; }
function album_has_parent_cats($id) { return $id !== 10; }
function album_build_auth_list($owner, $id, $data) { return 'trusted authorization text'; }
function album_make_nav_tree($id, $url, $style, $owner) { return '<a href="parent.php">Parent</a>'; }
function append_sid($url) { return $url; }
function album_append_uid($url) { return $url; }
function album_build_picture_table($owner, $ids, $category, $auth, $start, $sort, $order, $total) {}
function album_build_recent_pics($ids) {}
function album_get_sub_cat_ids($id, &$ids, $mask, $recursive) { $ids = array(20); }
function album_build_jumpbox($id, $owner) { return '<select><option>Trusted</option></select>'; }
class AlbumPersonalRenderDatabase
{
 function sql_query($sql) {
  apr_check(strpos($sql, 'SELECT COUNT(p.pic_id) AS count') === 0, 'Only picture-count SELECTs in this rendering fixture');
  return true;
 }
 function sql_fetchrow($result) { return array('count'=>$GLOBALS['apr_count']); }
 function sql_freeresult($result) {}
}
class AlbumPersonalRenderTemplate
{
 var $vars = array();
 function set_filenames($files) { apr_check($files === array('body'=>'album_cat_body.tpl'), 'Actual category template'); }
 function assign_block_vars($name, $vars) {}
 function assign_vars($vars) { $this->vars = array_merge($this->vars, $vars); }
}
function apr_render($name, $mode, $category, $moderators, $count, $fixture)
{
 $GLOBALS['apr_name']=$name; $GLOBALS['apr_count']=$count;
 $phpbb_root_path=$fixture.'/'; $phpEx='php'; $album_user_id=7; $cat_id=$category;
 $album_view_mode=$mode; $moderators_list=$moderators; $db=new AlbumPersonalRenderDatabase();
 $thiscat=array('cat_id'=>$category,'cat_user_id'=>7,'cat_title'=>'<b>Grüße &amp; category</b>');
 $album_data=array('keys'=>array($category=>0),'data'=>array($thiscat));
 $userdata=array('session_logged_in'=>true,'user_id'=>7);
 $album_config=array('personal_show_recent_instead_of_nopics'=>0,'personal_show_recent_in_subcats'=>0,'show_all_in_personal_gallery'=>1,'cols_per_page'=>4,'fullpic_popup'=>0);
 $images=array('all_pic_view_mode'=>'all.png','normal_pic_view_mode'=>'normal.png','upload_pic'=>'upload.png');
 $lang=$GLOBALS['apr_lang']; $template=new AlbumPersonalRenderTemplate();
 $allowed_cat='10,20'; $sort_method='pic_time'; $sort_order='ASC'; $start=0;
 $sort_rating_option=$sort_comments_option=$sort_new_comment_option='';
 include $GLOBALS['source'].'album_mod/album_personal.php';
 apr_check($username===$name, 'Output formatting must not change raw owner identity');
 return $template->vars;
}
$controller = file_get_contents($source.'album_mod/album_personal.php');
preg_match_all('/\$lang\[\x27([^\x27]+)\x27\]/', $controller, $keys);
$apr_lang=array(); foreach ($keys[1] as $key) { $apr_lang[$key]=$key; }
$apr_lang['Personal_Gallery_Of_User']='Gallery of %s';
$apr_lang['Personal_gallery_not_created']='No gallery for %s';
$apr_lang['Recent_Personal_Pics']='Recent pictures of %s';
$fixture=sys_get_temp_dir().'/phpbb-album-render-'.uniqid('', true);
apr_check(mkdir($fixture,0700)&&mkdir($fixture.'/includes',0700), 'Own temporary page-header fixture');
apr_check(file_put_contents($fixture.'/includes/page_header.php', '<?php // isolated page header')!==false, 'Synthetic header');
$before=getenv('PHPBB_ALBUM_RENDER_BEFORE')==='1'; $cases=0; $differences=0;
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
try {
 foreach(array('Ordinary','Grüße 😀','0','A &amp; B',"O'Connor",'<b>Owner</b>','&lt;i&gt;Owner&lt;/i&gt;',"invalid\xfftext") as $name) {
  foreach(array('',ALBUM_VIEW_ALL) as $mode) {
   foreach(array(10,20) as $category) {
    foreach(array('', '<a href="group.php">Group</a>', '0') as $moderators) {
     foreach(array(0,1) as $count) {
      $vars=apr_render($name,$mode,$category,$moderators,$count,$fixture);
      $owner=album_html_text($name);$title='Gallery of '.$owner;
      $expected=array('CAT_TITLE'=>($category===10||$mode===ALBUM_VIEW_ALL)?$title:album_html_text('<b>Grüße &amp; category</b>'),
       'L_CATEGORY'=>$title,'L_RECENT_PUBLIC_PICS'=>'Recent pictures of '.$owner,
       'MODERATORS'=>($moderators!==''?$moderators.', ':'').$owner);
      if($count===0&&$category===10){$expected['L_NO_PICS']='No gallery for '.$owner;}
      foreach($expected as $key=>$value){
       if($vars[$key]!==$value){$differences++; if(!$before){throw new RuntimeException('Actual personal template value: '.$key);}}
      }
      apr_check(strpos($vars['NAV_CAT_DESC'],'<a href="parent.php">Parent</a>')!==false, 'Trusted navigation markup retained');
      if($count===0&&strpos($vars['NAV_CAT_DESC'],$title)===false){$differences++; if(!$before){throw new RuntimeException('Escaped owner in actual breadcrumb');}}
      if($count===0&&strpos($vars['NAV_CAT_DESC'],'album.php?cat_id='.$category)===false){$differences++; if(!$before){throw new RuntimeException('Working category query in actual breadcrumb');}}
      $cases++;
     }
    }
   }
  }
 }
 apr_check($before ? $differences>0 : $differences===0, 'Before probe must reproduce differences, fixed path must have none');
 echo 'Personal Album rendering: '.$cases.' complete-controller Unicode/entity/name-zero/moderator/category/navigation cases; differences='.$differences.($before?' BEFORE reproduced':' PASS')."; no database or network.\n";
} finally {
 restore_error_handler();
 unlink($fixture.'/includes/page_header.php'); rmdir($fixture.'/includes'); rmdir($fixture);
}
