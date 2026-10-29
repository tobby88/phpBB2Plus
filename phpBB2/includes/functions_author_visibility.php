<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }

function phpbb_author_privacy_guest()
{
	global $board_config,$userdata;
	return isset($board_config['hide_removed_author_names']) && in_array($board_config['hide_removed_author_names'],array(true,1,'1'),true)
		&& (!isset($userdata['session_logged_in']) || !in_array($userdata['session_logged_in'],array(true,1,'1'),true));
}
function phpbb_removed_author_names_table()
{
	if (defined('REMOVED_AUTHOR_NAMES_TABLE')) { $table = REMOVED_AUTHOR_NAMES_TABLE; }
	elseif (defined('POSTS_TABLE') && preg_match('/^(.*)posts$/D',POSTS_TABLE,$match)) { $table = $match[1].'removed_author_names'; }
	else { throw new RuntimeException('Removed-author namespace unavailable'); }
	if (!is_string($table) || preg_match('/^[A-Za-z0-9_]+$/D',$table)!==1) { throw new RuntimeException('Invalid removed-author namespace'); }
	return $table;
}
function phpbb_author_visibility_rows($database,$sql)
{
	$result=$database->sql_query($sql);
	if (!$result) {
		global $lang;
		if (function_exists('message_die') && defined('GENERAL_ERROR')) { message_die(GENERAL_ERROR,isset($lang['Author_privacy_unavailable'])?$lang['Author_privacy_unavailable']:'Author information unavailable'); }
		throw new RuntimeException('Author privacy storage unavailable');
	}
	try { $rows=$database->sql_fetchrowset($result); } finally { $database->sql_freeresult($result); }
	if (!is_array($rows)) { throw new RuntimeException('Author privacy rows unavailable'); }
	return $rows;
}
function phpbb_removed_author_known($user_id,$name)
{
	global $db;
	if (!is_object($db) || !defined('USERS_TABLE')) { throw new RuntimeException('Author privacy database unavailable'); }
	$table=phpbb_removed_author_names_table(); $id=(int)$user_id;
	$raw=html_entity_decode((string)$name,ENT_QUOTES,'UTF-8');
	if (strlen($raw)>1020 || preg_match('//u',$raw)!==1) { return false; }
	$key=spl_object_hash($db).'|'.$table.'|'.$id.'|'.$raw; static $cache=array();
	if (!array_key_exists($key,$cache))
	{
		$name_sql="n.author_name='".$db->sql_escape($raw)."'";
		$where=$id>0 ? '(n.author_user_id='.$id.' OR (n.author_user_id=0 AND '.$name_sql.' AND NOT EXISTS (SELECT 1 FROM '.USERS_TABLE.' current_author WHERE current_author.user_id='.$id.')))' : $name_sql;
		$rows=phpbb_author_visibility_rows($db,'SELECT n.author_user_id FROM '.$table.' n WHERE '.$where.' AND (n.author_user_id=0 OR NOT EXISTS (SELECT 1 FROM '.USERS_TABLE.' u WHERE u.user_id=n.author_user_id)) LIMIT 1');
		$cache[$key]=(bool)$rows;
	}
	return $cache[$key];
}
function phpbb_former_author_label()
{
	global $lang;
	return isset($lang['Former_member']) ? $lang['Former_member'] : 'Former member';
}
// An explicit false marker preserves genuine guest bylines, including a name
// reused after a former account. NULL is for old name-only/quote snapshots.
function phpbb_author_name($user_id,$name,$removed=null)
{
	if (phpbb_author_privacy_guest() && (($removed===true && (int)$user_id<=0) || (($removed===null || (int)$user_id>0) && phpbb_removed_author_known($user_id,$name)))) { $name=phpbb_former_author_label(); }
	return htmlspecialchars((string)$name,ENT_QUOTES | ENT_SUBSTITUTE,'UTF-8',false);
}
function phpbb_post_author_name($row,$name_key='post_username',$id_key='poster_id',$removed_key='post_author_removed')
{
	global $lang;
	$name=isset($row[$name_key]) && $row[$name_key]!=='' ? $row[$name_key] : $lang['Guest'];
	$removed=array_key_exists($removed_key,$row) ? (string)$row[$removed_key]==='1' : null;
	return phpbb_author_name(isset($row[$id_key])?$row[$id_key]:-1,$name,$removed);
}
function phpbb_removed_author_search_sql($column)
{
	if (!phpbb_author_privacy_guest()) { return ''; }
	if (!is_string($column) || preg_match('/^(?:[a-z_][a-z0-9_]*\.)?post_username$/D',$column)!==1) { throw new InvalidArgumentException('Invalid guest author search scope'); }
	return ' AND '.substr($column,0,-strlen('post_username')).'post_author_removed=0';
}
function phpbb_removed_author_metadata_sql($identity,$name)
{
	if (!phpbb_author_privacy_guest()) { return ''; }
	foreach(array($identity,$name)as$column){if(!is_string($column)||preg_match('/^(?:[a-z_][a-z0-9_]*\.)?[a-z_][a-z0-9_]*$/D',$column)!==1){throw new InvalidArgumentException('Invalid author metadata scope');}}
	return ' AND NOT EXISTS (SELECT 1 FROM '.phpbb_removed_author_names_table().' n WHERE ((n.author_user_id>0 AND n.author_user_id='.$identity.') OR ('.$identity.'<=0 AND n.author_name='.$name.') OR (n.author_user_id=0 AND n.author_name='.$name.' AND NOT EXISTS (SELECT 1 FROM '.USERS_TABLE.' current_author WHERE current_author.user_id='.$identity.'))) AND (n.author_user_id=0 OR NOT EXISTS (SELECT 1 FROM '.USERS_TABLE.' retired_author WHERE retired_author.user_id=n.author_user_id)))';
}
function phpbb_author_search_cache_tag()
{
	global $db;
	if (!phpbb_author_privacy_guest()) { return 'member-or-legacy'; }
	// Aliases are append-only and are effective only after the actual account
	// disappears. This also invalidates a search created during removal capture,
	// before DELETE, without relying on a later cleanup worker or clock seconds.
	$rows=phpbb_author_visibility_rows($db,'SELECT (SELECT COUNT(*) FROM '.phpbb_removed_author_names_table().' n WHERE n.author_user_id=0 OR NOT EXISTS (SELECT 1 FROM '.USERS_TABLE.' u WHERE u.user_id=n.author_user_id)) AS aliases,(SELECT COUNT(*) FROM '.POSTS_TABLE.' WHERE post_author_removed=1) AS posts,(SELECT COUNT(*) FROM '.SHOUTBOX_TABLE.' WHERE shout_author_removed=1) AS shouts');
	$state=array();foreach(array('aliases','posts','shouts')as$key){if(count($rows)!==1 || !isset($rows[0][$key]) || preg_match('/^[0-9]+$/D',(string)$rows[0][$key])!==1){throw new RuntimeException('Author privacy cache revision unavailable');}$state[]=$rows[0][$key];}
	return 'guest-removed-v1:'.implode(':',$state);
}
function phpbb_author_search_cache_allowed($data)
{
	return !phpbb_author_privacy_guest() || (is_array($data) && isset($data['author_visibility']) && $data['author_visibility']===phpbb_author_search_cache_tag());
}
function phpbb_author_filter_quote_headers($text)
{
	if (!phpbb_author_privacy_guest()) { return $text; }
	return preg_replace_callback('/(\[quote(?::[a-z0-9]{10,})?=")(.*?)("\])/is',function($match){return $match[1].phpbb_author_name(-1,$match[2]).$match[3];},$text);
}
function phpbb_removed_author_keyword_search($value)
{
	global $db;
	if (!phpbb_author_privacy_guest() || !is_string($value) || trim($value)==='') { return false; }
	$terms=array(trim($value," \t\r\n\""));
	$terms=array_merge($terms,preg_split('/[\s"()]+/u',$value,-1,PREG_SPLIT_NO_EMPTY));
	$table=phpbb_removed_author_names_table();
	foreach (array_slice(array_unique($terms),0,51) as $term)
	{
		$term=ltrim($term,'+-'); if ($term==='' || in_array(strtoupper($term),array('AND','OR','NOT'),true)) { continue; }
		$pattern=str_replace(array('!','%','_'),array('!!','!%','!_'),html_entity_decode($term,ENT_QUOTES,'UTF-8'));
		$pattern=str_replace('*','%',$pattern);
		$rows=phpbb_author_visibility_rows($db,'SELECT n.author_user_id FROM '.$table." n WHERE n.author_name LIKE '".$db->sql_escape($pattern)."' ESCAPE '!' AND (n.author_user_id=0 OR NOT EXISTS (SELECT 1 FROM ".USERS_TABLE.' u WHERE u.user_id=n.author_user_id)) LIMIT 1');
		if ($rows) { return true; }
	}
	return false;
}

// Called on the removal writer before deleting the account and again on
// recovery. Records do not hide a still-existing account. Keep exact names;
// never replace or erase the bylines needed by logged-in members.
function phpbb_record_removed_author_name($database,$user_id,$name,$guard)
{
	if (!is_int($user_id) || $user_id<0 || $user_id>8388607 || !is_string($name) || !is_string($guard) || $guard==='') { throw new RuntimeException('Invalid removed-author capture'); }
	$name=trim(html_entity_decode($name,ENT_QUOTES,'UTF-8'));
	if ($name==='' || strlen($name)>1020 || preg_match('//u',$name)!==1 || preg_match_all('/./us',$name)>255) { throw new RuntimeException('Invalid retained author name'); }
	$table=phpbb_removed_author_names_table();$hash=hash('sha256',$name);$literal=$database->sql_escape($name);
	$sql='INSERT INTO '.$table.' (author_user_id,author_name_hash,author_name,recorded_at) SELECT '.$user_id.",'".$hash."','".$literal."',".time().' WHERE ('.$guard.') AND NOT EXISTS (SELECT 1 FROM '.$table.' n WHERE n.author_user_id='.$user_id." AND n.author_name_hash='".$hash."')";
	if (!$database->sql_query($sql)) { throw new RuntimeException('Could not retain removed-author classification'); }
}
function phpbb_removed_author_storage($database)
{
	foreach (array(phpbb_removed_author_names_table(),POSTS_TABLE,SHOUTBOX_TABLE) as $table)
	{
		$result=$database->sql_query('SELECT * FROM '.$table.' LIMIT 0');if(!$result){throw new RuntimeException('Removed-author tables unavailable');}$database->sql_freeresult($result);
		$literal=$database->sql_escape($table);
		$rows=phpbb_author_visibility_rows($database,"SELECT ENGINE, ROW_FORMAT, TABLE_COLLATION FROM information_schema.TABLES t WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='".$literal."' AND NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS c WHERE c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME='".$literal."' AND c.CHARACTER_SET_NAME IS NOT NULL AND (c.CHARACTER_SET_NAME<>'utf8mb4' OR c.COLLATION_NAME<>'utf8mb4_unicode_ci'))");
		if (count($rows)!==1 || $rows[0]['ENGINE']!=='InnoDB' || strtolower($rows[0]['ROW_FORMAT'])!=='dynamic' || $rows[0]['TABLE_COLLATION']!=='utf8mb4_unicode_ci') { throw new RuntimeException('Removed-author classification requires canonical storage'); }
	}
}
function phpbb_capture_removed_author($database,$user_id,$name,$guard)
{
	if (!is_int($user_id) || $user_id<=0 || $user_id>8388607) { throw new RuntimeException('Account classification requires a real removed identity'); }
	phpbb_removed_author_storage($database);
	phpbb_record_removed_author_name($database,$user_id,$name,$guard);
	$rows=phpbb_author_visibility_rows($database,'SELECT DISTINCT post_edit_username FROM '.POSTS_TABLE.' WHERE post_edit_user_id='.(int)$user_id.' AND post_edit_username IS NOT NULL');
	foreach ($rows as $row) { if ($row['post_edit_username']!=='') { phpbb_record_removed_author_name($database,$user_id,$row['post_edit_username'],$guard); } }
	// Flag exact account-owned rows while its identity is still known. A
	// different guest using the same spelling is deliberately not classified.
	if (!$database->sql_query('UPDATE '.POSTS_TABLE.' SET post_author_removed=1 WHERE poster_id='.(int)$user_id.' AND ('.$guard.')') || !$database->sql_query('UPDATE '.SHOUTBOX_TABLE.' SET shout_author_removed=1 WHERE shout_user_id='.(int)$user_id.' AND ('.$guard.')')) { throw new RuntimeException('Could not classify removed-author content'); }
}
