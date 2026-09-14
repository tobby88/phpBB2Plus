<?php
require __DIR__.'/check-group-storage.php';
foreach(array('ANONYMOUS'=>-1,'POST_USERS_URL'=>'u') as $k=>$v){if(!defined($k)){define($k,$v);}}
$controller=file_get_contents($forum_root.'groupcp.php');$functions=file_get_contents($forum_root.'includes/functions.php');
foreach(array('phpbb_profile_text','phpbb_stored_text','phpbb_profile_http_url','phpbb_profile_email_uri','phpbb_social_profile_links','create_absence_mode','allow_send_to_absent') as $fn){if(!function_exists($fn)){eval(group_test_function($functions,$fn));}}
eval(group_test_function($controller,'generate_user_info'));
function create_date($format,$time,$zone){return 'fixture-date';}
require $forum_root.'includes/template.php';
class GroupDisplayDatabase extends GroupControllerReadDatabase
{
 function sql_fetchrowset($r){return $r->fetchAll(PDO::FETCH_ASSOC);}
}
// Execute the actual moderator lookup, missing-row handling, member-list SQL,
// template assignments and shipped Extreme Styles template, without common.php.
$read_start=strpos($controller,'$sql = "SELECT username, user_absence');
$read_end=strpos($controller,'$sql = "SELECT u.username, u.user_id',$read_start);
$render_start=strpos($controller,'$username = phpbb_stored_text($group_moderator');
$render_end=strpos($controller,"\n\t//\n\t// Dump out the remaining users",$render_start);
mutation_check($read_start!==false&&$read_end>$read_start&&$render_start!==false&&$render_end>$render_start,'Exact controller display branches');
$read=substr($controller,$read_start,$read_end-$read_start);$render=substr($controller,$render_start,$render_end-$render_start);
preg_match_all('/\$lang\[\'([^\']+)\'\]/',$render,$labels);foreach($labels[1] as $key){$lang[$key]=$key;}
$lang['Search_user_posts']='Search posts by %s';$lang['Group_no_moderator']='No group moderator assigned';
$lang['Read_profile']='Profile';$lang['Send_private_message']='PM';$lang['Send_email']='Email';
$lang['User_absent']='Absent';
$images=array('icon_profile'=>'profile.png','icon_pm'=>'pm.png','icon_email'=>'email.png','icon_search'=>'search.png','On_holidays'=>'holiday.png');
$theme=array('td_color1'=>'ffffff','td_class1'=>'row1','template_name'=>'fisubsilversh');
$board_config=array('default_dateformat'=>'Y','board_timezone'=>0,'board_email_form'=>0,'xs_use_cache'=>0,'xs_auto_compile'=>0,'xs_auto_recompile'=>0,'default_lang'=>'english','absent_button'=>1,'mod_able_sent_absent'=>0);
$payload='Grüße &amp; </option><img src=x onerror="alert(1)">';
set_error_handler(function($s,$m){if(error_reporting()&$s){throw new RuntimeException($m);}});
try{
 foreach(array('missing','existing','absent') as $case){
  group_fixture(11);$userdata['user_lang']='english';$userdata['template_name']='fisubsilversh';$group_id=3;$group_info=array('group_moderator'=>$case==='missing'?999:8,'group_type'=>0,'group_name'=>$payload,'group_description'=>$payload);
  $p=$mutation_server->pdo;$existing=array();foreach($p->query('PRAGMA table_info(fixture_users)')->fetchAll(PDO::FETCH_ASSOC) as $c){$existing[$c['name']]=true;}
  preg_match('/SELECT (.*?)\s+FROM/s',$read,$m);foreach(explode(',',$m[1]) as $name){$name=trim($name);if(!isset($existing[$name])){$p->exec('ALTER TABLE fixture_users ADD '.$name." TEXT DEFAULT ''");}}
  $p->exec("UPDATE fixture_users SET username='".str_replace("'","''",$payload)."',user_email='' WHERE user_id=8");
  if($case==='absent'){$p->exec('UPDATE fixture_users SET user_absence=1,user_absence_mode=1 WHERE user_id=8');}
  $db=new GroupDisplayDatabase();eval($read);
  mutation_check(count($group_members)===($case==='missing'?2:1),'Actual member query remains valid and never excludes arbitrary member');
  $template=(new ReflectionClass('Template'))->newInstanceWithoutConstructor();$template->vars=&$template->_tpldata['.'][0];$template->load_config($forum_root.'templates/fisubsilversh',false);
  $s_hidden_fields='';$is_moderator=false;$group_details='details';$select_sort_mode=$select_sort_order='';eval($render);
  $template->set_filenames(array('group'=>'groupcp_info_body.tpl'));ob_start();$template->pparse('group');$html=ob_get_clean();
  mutation_check(strpos($html,'<img src=x')===false&&strpos($html,'&lt;img src=x')!==false,'Group metadata is text, not injected markup');
  mutation_check($template->vars['GROUP_NAME']===phpbb_stored_text($payload)&&$template->vars['GROUP_DESC']===phpbb_stored_text($payload),'Both original metadata assignments escape stored text');
  if($case==='missing'){
   mutation_check($template->vars['U_MOD_VIEWPROFILE']===''&&$template->vars['MOD_PM_IMG']===''&&$template->vars['MOD_SEARCH_IMG']==='','No nonexistent-account links');
   mutation_check(strpos($html,'<span class="name">No group moderator assigned</span>')!==false&&strpos($html,'<a href="" class="name">')===false,'Missing moderator block actually renders without a link');
  }else{
   mutation_check(strpos($html,'profile.php?mode=viewprofile&amp;u=8')!==false,'Existing moderator profile still linked');
   mutation_check(strpos($template->vars['MOD_SEARCH_IMG'],'onerror=&quot;')!==false&&strpos($template->vars['MOD_SEARCH_IMG'],'onerror="')===false,'Username cannot break search-label attribute');
   if($case==='absent'){mutation_check(strpos($template->vars['MOD_USERNAME'],'<br />')!==false&&strpos($template->vars['MOD_USERNAME'],'&lt;img src=x')!==false,'Trusted absence decoration remains markup after escaping username');}
  }
 }
 // Evaluate the three real option-building expressions, including legacy entities.
 preg_match_all('/\$s_(?:pending_groups_opt|member_groups_opt|group_list_opt)\s*\.=[^;]+;/',$controller,$matches);
 mutation_check(count($matches[0])===3,'All group selectors covered');$row=array('group_id'=>3,'group_name'=>$payload);
 $s_pending_groups_opt=$s_member_groups_opt=$s_group_list_opt='';foreach($matches[0] as $statement){eval($statement);}
 foreach(array($s_pending_groups_opt,$s_member_groups_opt,$s_group_list_opt) as $option){mutation_check($option==='<option value="3">'.phpbb_stored_text($payload).'</option>','Group name cannot break option element');}
 echo "Actual group display SQL, missing leader, stored-text escaping and Extreme Styles render passed.\n";
}finally{restore_error_handler();}
