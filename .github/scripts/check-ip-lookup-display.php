<?php
require __DIR__.'/ip-lookup-fixture.php';
$template_source=file_get_contents($ip_app.'templates/fisubsilversh/modcp_viewip.tpl');
ip_check(strpos($template_source,'{userrow.USERNAME}')!==false&&strpos($template_source,'title="{userrow.L_SEARCH_POSTS}"')!==false,'Actual standard IP template uses expected text and attribute contexts');
foreach(array('english','german')as$locale)
{
	ip_language($locale);
	foreach(array('modcp.php','shoutbox_max.php')as$route)
	{
		foreach(array('Simple','A" title="B',"O'Reilly",'<b>ü 😀</b>','Literal &amp; Name','C:\\temp')as$raw)
		{
			$r=ip_controller_row($route,$raw);$escaped=htmlspecialchars($raw,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
			ip_check($r['USERNAME']===$escaped,'Actual controller binds escaped literal account name to the standard template');
			ip_check($r['L_SEARCH_POSTS']===htmlspecialchars(sprintf($lang['Search_user_posts'],$raw),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'),'Complete localized title escaped once');
			ip_check(strpos($r['U_SEARCHPOSTS'],'search_author='.urlencode($raw).'&amp;')!==false,'Exact raw account identity in query, including literal entities');
			ip_check(strpos($r['U_PROFILE'],'u=7')!==false,'Profile retains numeric identity');$ip_cases++;
		}
		foreach(array(ANONYMOUS,DELETED)as$id){$r=ip_controller_row($route,'Stored non-account name',$id);ip_check($r['USERNAME']===$lang['Guest']&&strpos($r['L_SEARCH_POSTS'],'Stored non-account')===false,'Deleted/anonymous identities do not expose a stored account-table label');$ip_cases++;}
		$q=ip_controller_queries($route);ip_check(strpos($q['users'],'$ip_forum_filter')!==false,'Actual forum correlation uses fresh permission scope');
		$source=file_get_contents($ip_app.$route);$guard=$route==='modcp.php'?'$ip_forum_filter = phpbb_ip_lookup_forum_filter($db, $forum_id);':'phpbb_ip_lookup_actor($db, true);';ip_check(strpos($source,$guard)!==false,'Actual sensitive IP entry has its current authority check');
	}
	foreach(array('Moderated_forum_users_IP','Shoutbox_this_IP','Shoutbox_other_IPs')as$key){ip_check(isset($lang[$key])&&preg_match('//u',$lang[$key])===1,'Complete translated IP source labels');}
}
restore_error_handler();echo 'IP lookup display: ',$ip_cases,' cases, ',$ip_checks," assertions PASS; both actual controllers and standard template, literal names, escaped titles, exact search identities and English/German labels; no SQL.\n";
