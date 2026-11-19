<?php
// Extend the existing disposable Album page fixture, retaining all its original
// cases. Exercise the actual directory controller and the same database adapter.
apt_check(isset($created, $schema) && $created && preg_match('/^codex_album_pages_[a-f0-9]{16}$/D', $schema), 'Owned page fixture required');
function agi_request($sort = 'joindate', $order = 'ASC', $start = 0, $size = 2)
{
	global $db, $apt_trace, $apt_hit, $apt_owners, $apt_factory;
	$apt_trace = array(); $apt_hit = false; $first = count($apt_owners);
	$before = array($GLOBALS['db'], $GLOBALS['userdata'], $GLOBALS['album_config'], $GLOBALS['album_data']);
	$out = $error = null;
	try { $out = album_gallery_owner_page($db, $sort, $order, $start, $size); }
	catch (PhpbbForumReadFailure $failure) { $error = $failure->getMessage(); }
	finally
	{
		apt_check(array($GLOBALS['db'], $GLOBALS['userdata'], $GLOBALS['album_config'], $GLOBALS['album_data']) === $before, 'Directory restores caller globals');
		if ($apt_factory !== 'busy') { for ($i = $first; $i < count($apt_owners); $i++) { if (in_array('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY', $apt_trace, true)) { apt_check($apt_owners[$i]->closed && !$apt_owners[$i]->inner->db_connect_id, 'Directory closes owned reader'); } } }
	}
	return array('out'=>$out, 'error'=>$error, 'trace'=>$apt_trace, 'hit'=>$apt_hit);
}
function agi_expect($result, $ids, $total, $start = 0)
{
	apt_check($result['error'] === null && array_map('strval', array_column($result['out']['rows'], 'user_id')) === $ids && $result['out']['total'] === $total && $result['out']['start'] === $start, 'Directory visible owners/count/page: ' . json_encode($result));
	$GLOBALS['agi_cases']++; return $result['out'];
}
$agi_program = file_get_contents($phpbb_root_path . 'album_personal_index.php');
$agi_begin = strpos($agi_program, '$start = max('); apt_check($agi_begin !== false, 'Actual complete directory controller boundary');
$agi_program = substr($agi_program, $agi_begin);
$agi_program = preg_replace("~^\\h*include\\(\\\$phpbb_root_path\\s*\\.\\s*'includes/page_(?:header|tail)\\.'\\s*\\.\\s*\\\$phpEx\\);\\h*$~m", '', $agi_program, -1, $agi_removed);
apt_check($agi_removed === 2, 'Only outer header and tail bootstrap replaced'); $agi_program = preg_replace('/\?>\s*$/', '', $agi_program);
function agi_controller($get, $post = array())
{
	global $agi_program, $db, $userdata, $album_config, $album_data, $board_config, $lang, $theme, $phpEx, $phpbb_root_path, $album_root_path, $template;
	$_GET = $get; $_POST = $post; $template = new AlbumPageTemplate(); $theme = array('td_class1'=>'row1', 'td_class2'=>'row2');
	eval($agi_program); $GLOBALS['agi_views']++;
	return array('template'=>$template, 'total'=>$total_galleries, 'start'=>$start, 'mode'=>$mode, 'order'=>$sort_order);
}
$agi_cases = $agi_faults = $agi_views = 0;
$apt_fault = 0; $apt_hook = null; $apt_factory = 'normal';
apt_sql("SET SESSION sql_mode='STRICT_ALL_TABLES,ONLY_FULL_GROUP_BY'");
apt_sql('DELETE FROM fixture_album'); apt_sql('DELETE FROM fixture_album_cat'); apt_sql('DELETE FROM fixture_user_group');
foreach (array(10=>'Änne &amp; <Archiv>',11=>'Pending only',12=>'Empty gallery') as $id=>$name) { apt_insert('users', array('user_id'=>$id,'username'=>$name,'user_password'=>'','user_email'=>'','user_active'=>1)); }
foreach (array(7=>300,8=>200,9=>100,10=>200,11=>400,12=>500) as $id=>$joined) { apt_sql('UPDATE fixture_users SET user_regdate=' . $joined . ' WHERE user_id=' . $id); }
foreach (array(100=>array(8,ALBUM_GUEST,ALBUM_ADMIN),101=>array(8,ALBUM_GUEST,ALBUM_MOD),102=>array(8,ALBUM_PRIVATE,ALBUM_USER),200=>array(9,ALBUM_PRIVATE,ALBUM_USER),300=>array(10,ALBUM_GUEST,ALBUM_USER),400=>array(11,ALBUM_GUEST,ALBUM_ADMIN),500=>array(12,ALBUM_GUEST,ALBUM_USER),600=>array(99,ALBUM_GUEST,ALBUM_USER),700=>array(0,ALBUM_GUEST,ALBUM_USER),800=>array(7,ALBUM_PRIVATE,ALBUM_ADMIN)) as $id=>$c)
{
	apt_insert('album_cat',array('cat_id'=>$id,'cat_title'=>'Directory synthetic '.$id,'cat_desc'=>'','cat_order'=>$id,'cat_user_id'=>$c[0],'cat_view_level'=>$c[1],'cat_view_groups'=>'9','cat_moderator_groups'=>$id===101?'10':'','cat_upload_level'=>ALBUM_PRIVATE,'cat_approval'=>$c[2]));
}
foreach (array(501=>array(100,1,500),502=>array(100,0,999),503=>array(101,1,400),504=>array(101,0,800),505=>array(102,1,700),506=>array(200,1,600),507=>array(300,0,200),508=>array(400,0,900),509=>array(600,1,1000),510=>array(700,1,1100),511=>array(800,0,1200),512=>array(800,1,300)) as $id=>$p) { apt_picture($id,$p[0],$p[1],7,$p[2]); }
// An imported high ID does not make an old upload the most recent gallery.
apt_picture('4294967295',300,1,7,1);
apt_actor(-1);
$out = agi_expect(agi_request(), array('8','10'), 2); apt_check(array_map('intval',array_column($out['rows'],'pics')) === array(2,2), 'Guest counts approved and disabled-review pictures only'); $agi_cases++;
agi_expect(agi_request('last_pic','DESC'),array('8','10'),2);
agi_expect(agi_request('last_pic','ASC'),array('10','8'),2);
agi_expect(agi_request('pics','ASC'),array('8','10'),2);
agi_expect(agi_request('joindate','DESC'),array('10','8'),2);
agi_expect(agi_request('joindate','ASC',1000000,1),array('10'),2,1);
agi_expect(agi_request('joindate','ASC',1,2),array('8','10'),2);
agi_expect(agi_request('joined','DESC'),array('10','8'),2);
agi_expect(agi_request('pics;DELETE','invalid'),array('8','10'),2);
foreach(array('STRICT_ALL_TABLES,ONLY_FULL_GROUP_BY','STRICT_ALL_TABLES,NO_BACKSLASH_ESCAPES','STRICT_ALL_TABLES,ANSI_QUOTES,ONLY_FULL_GROUP_BY') as $mode)
{
	$apt_hook = function($phase,$sql,$owner) use($mode) { if($phase==='before' && $sql==='SET SESSION lock_wait_timeout=3') { apt_check($owner->inner->sql_query("SET SESSION sql_mode='".$mode."'")!==false,'Directory strict native mode'); } };
	agi_expect(agi_request(),array('8','10'),2); $apt_hook=null;
}
apt_actor(); agi_expect(agi_request('joindate','ASC',0,100),array('8','10','7'),3);
$userdata['user_level']=ADMIN; agi_expect(agi_request('joindate','ASC',0,100),array('8','10','7'),3);
apt_insert('user_group',array('group_id'=>10,'user_id'=>7,'user_pending'=>1)); $out=agi_expect(agi_request('joindate','ASC',0,100),array('8','10','7'),3); apt_check((int)$out['rows'][0]['pics']===2,'Pending membership grants no pending pictures'); $agi_cases++;
apt_sql('UPDATE fixture_user_group SET user_pending=0'); $out=agi_expect(agi_request('joindate','ASC',0,100),array('8','10','7'),3); apt_check((int)$out['rows'][0]['pics']===3,'Current moderator sees only MOD-reviewed pending picture'); $agi_cases++;
apt_insert('user_group',array('group_id'=>9,'user_id'=>7,'user_pending'=>0)); $out=agi_expect(agi_request('joindate','ASC',0,100),array('9','8','10','7'),4); apt_check((int)$out['rows'][1]['pics']===4,'Current private group adds the private category once'); $agi_cases++;
apt_sql('DELETE FROM fixture_user_group'); apt_sql('UPDATE fixture_users SET user_level=1 WHERE user_id=7');
$out=agi_expect(agi_request('joindate','ASC',0,100),array('9','8','10','7','11'),5); apt_check(array_map('intval',array_column($out['rows'],'pics'))===array(1,5,2,2,1),'Actual administrator sees pending and private pictures, excluding empty/missing owners and public gallery'); $agi_cases++;
apt_sql('UPDATE fixture_users SET user_level=0 WHERE user_id=7'); apt_actor();
foreach(array('english','german') as $locale)
{
	$lang=array(); require $phpbb_root_path.'language/lang_'.$locale.'/lang_main.php'; require $phpbb_root_path.'language/lang_'.$locale.'/lang_main_album.php';
	$board_config=array('topics_per_page'=>1,'board_timezone'=>0,'default_dateformat'=>'Y-m-d H:i','hide_removed_author_names'=>0,'default_lang'=>$locale); apt_actor(-1);
	$c=agi_controller(array('start'=>'1000000')); $rows=$c['template']->rows['memberrow']; apt_check($c['total']===2 && $c['start']===1 && count($rows)===1 && $rows[0]['USERNAME']==='Änne &amp; &lt;Archiv&gt;' && $rows[0]['U_VIEWGALLERY']==='album.php?user_id=10' && $rows[0]['PICS']===2 && $c['template']->vars['PAGE_NUMBER']===sprintf($lang['Page_of'],2,2), 'Actual localized controller escaped names and last real page');
	$c=agi_controller(array('mode'=>'joined','order'=>'DESC')); apt_check($c['mode']==='joindate' && $c['order']==='DESC' && strpos($c['template']->vars['S_MODE_SELECT'],'value="joindate" selected="selected"')!==false && $c['template']->rows['memberrow'][0]['U_VIEWGALLERY']==='album.php?user_id=10','Legacy joined alias canonicalizes actual selection and respects descending order');
	$c=agi_controller(array('mode'=>'pics'),array('mode'=>'last_pic','order'=>'ASC')); apt_check($c['mode']==='last_pic' && $c['template']->rows['memberrow'][0]['U_VIEWGALLERY']==='album.php?user_id=10','Actual posted sort wins and uses upload time');
	$c=agi_controller(array('mode'=>array('pics'),'start'=>array('1'),'order'=>array('DESC'))); apt_check($c['mode']==='joindate' && $c['start']===0 && $c['order']==='DESC' && $c['template']->rows['memberrow'][0]['U_VIEWGALLERY']==='album.php?user_id=10','Nested input retains existing scalar fallback and bounded paging');
}
// Independent count/page snapshot survives an actual committed peer removal.
apt_actor(-1); $changed=false; $apt_hook=function($phase,$sql)use(&$changed){if(!$changed && $phase==='after' && strpos($sql,'SELECT COUNT(*) total FROM (SELECT ct.cat_user_id')===0){$changed=true;apt_sql('DELETE FROM fixture_album WHERE pic_cat_id=300');}};
agi_expect(agi_request('joindate','ASC',1,1),array('10'),2,1); $apt_hook=null; apt_check($changed,'Real owner removal between count and page'); agi_expect(agi_request('joindate','ASC',1,1),array('8'),1);
apt_check($db->sql_query('START TRANSACTION')!==false && $db->sql_query("UPDATE fixture_users SET username='Pending caller' WHERE user_id=8")!==false,'Caller pending directory work');
$out=agi_expect(agi_request(),array('8'),1); apt_check($out['rows'][0]['username']==='Other','Directory does not read uncommitted caller work'); apt_check($db->sql_query('ROLLBACK')!==false && apt_rows('SELECT username FROM fixture_users WHERE user_id=8')[0]['username']==='Other','Caller rollback preserved'); $agi_cases++;
apt_actor(); foreach(array('UPDATE fixture_sessions SET session_logged_in=0','UPDATE fixture_sessions SET session_time=1','UPDATE fixture_users SET user_active=0 WHERE user_id=7','UPDATE fixture_users SET user_blocktime=2147483647 WHERE user_id=7') as $sql)
{
	apt_sql($sql); apt_check(agi_request()['error']!==null,'Revoked directory identity refuses'); apt_sql('UPDATE fixture_sessions SET session_logged_in=1,session_time='.time()); apt_sql('UPDATE fixture_users SET user_active=1,user_blocktime=0 WHERE user_id=7'); $agi_cases++;
}
apt_insert('banlist',array('ban_userid'=>7,'ban_ip'=>'')); apt_check(agi_request()['error']!==null,'Banned directory identity refuses'); apt_sql('DELETE FROM fixture_banlist'); $agi_cases++;
foreach(array('missing','same','busy') as $factory)
{
	$apt_factory=$factory; if($factory==='busy'){apt_sql('START TRANSACTION');apt_sql("UPDATE fixture_users SET username='Borrowed' WHERE user_id=8");}
	apt_check(agi_request()['error']!==null,'Unsafe directory factory refuses');
	if($factory==='busy'){apt_check($peer->db_connect_id && apt_rows('SELECT username FROM fixture_users WHERE user_id=8')[0]['username']==='Borrowed','Borrowed transaction preserved');apt_sql('ROLLBACK');} $agi_cases++;
}
$apt_factory='normal'; $base=agi_request(); apt_check($base['error']===null,'Actual directory fault baseline');
foreach(array('before','after') as $phase){for($target=1;$target<=count($base['trace']);$target++){if($base['trace'][$target-1]==='ROLLBACK'){continue;}$apt_fault=$target;$apt_phase=$phase;$result=agi_request();apt_check($result['hit'] && $result['error']!==null && $result['out']===null,'Directory SQL failure returns no partial owners');$agi_faults++;}} $apt_fault=0; $apt_phase='';
foreach(array(array(-1,2),array(1000001,2),array(0,0),array(0,101),array('1',2)) as $bad){$result=agi_request('joindate','ASC',$bad[0],$bad[1]);apt_check($result['error']!==null && !$result['trace'],'Invalid directory paging refused before storage');$agi_cases++;}
apt_actor(-1); apt_sql('DELETE FROM fixture_album'); agi_expect(agi_request(),array(),0);
$c=agi_controller(array('start'=>'1000000')); apt_check($c['total']===0 && $c['start']===0 && empty($c['template']->rows['memberrow']) && $c['template']->vars['PAGINATION']==='' && $c['template']->vars['PAGE_NUMBER']===sprintf($lang['Page_of'],1,1),'Actual empty directory has one empty page');
apt_sql('DELETE FROM fixture_album_cat'); agi_expect(agi_request(),array(),0);
echo 'Album gallery owners native: '.$agi_cases.' cases, '.$agi_faults.' SQL failures, '.$agi_views." controller views PASS; visible per-category counts, strict grouping, actual upload dates, current identity/groups, coherent pages and caller isolation.\n";
