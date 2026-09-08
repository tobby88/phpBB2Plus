<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_prune_storage.php';

// Validate the complete current forum contents, including old orphan/foreign
// post inconsistencies. Never delete such content merely to empty its parent.
function phpbb_forum_contents($db,$forum_id)
{
	$topics=phpbb_prune_rows($db,'SELECT topic_id FROM '.TOPICS_TABLE.' WHERE forum_id = '.$forum_id.' ORDER BY topic_id');
	$ids=array(); foreach($topics as $topic) { $ids[]=phpbb_prune_id($topic['topic_id']); }
	$posts=phpbb_prune_rows($db,'SELECT post_id, topic_id, forum_id, poster_id FROM '.POSTS_TABLE.' WHERE forum_id = '.$forum_id
		.($ids ? ' OR topic_id IN ('.implode(',',$ids).')' : '').' ORDER BY post_id');
	$known=array_fill_keys($ids,true); $post_ids=array(); $posters=array();
	foreach($posts as $post)
	{
		if((int)$post['forum_id']!==$forum_id || !isset($known[(int)$post['topic_id']])) { phpbb_prune_error('Prune_selection_changed'); }
		$post_ids[]=phpbb_prune_id($post['post_id']);
		if((int)$post['poster_id']>0) { $posters[(int)$post['poster_id']]=(int)$post['poster_id']; }
	}
	return array('topics'=>$ids,'posts'=>$post_ids,'posters'=>$posters);
}
function phpbb_forum_has_children($db,$forum_id)
{
	return (bool)phpbb_prune_rows($db,'SELECT forum_id FROM '.FORUMS_TABLE." WHERE main_type = 'f' AND cat_id = ".$forum_id.' LIMIT 1')
		|| (bool)phpbb_prune_rows($db,'SELECT cat_id FROM '.CATEGORIES_TABLE." WHERE cat_main_type = 'f' AND cat_main = ".$forum_id.' LIMIT 1');
}

// ACP move-and-delete operation. Null target explicitly discards all contents;
// a numeric target preserves them. The caller refreshes caches after return.
// Coordinated MyISAM operations can still fail partway; no rollback is claimed.
function phpbb_remove_forum($database,$source,$target=null)
{
	global $userdata;
	$source=phpbb_prune_id($source); if($target!==null) { $target=phpbb_prune_id($target); }
	if($source===$target) { phpbb_prune_error('Prune_selection_changed'); }
	if(!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD']!=='POST' || empty($userdata['session_id'])
		|| !isset($_POST['sid']) || !is_string($_POST['sid']) || !hash_equals((string)$userdata['session_id'],$_POST['sid'])) { phpbb_prune_error('Session_invalid'); }
	$lock=new attach_mutation_lock($database);
	if(!$lock->acquired) { phpbb_prune_error('Attachment_storage_busy'); }
	try
	{
		$db=new PhpbbPruneDatabase($lock->connection); $user=phpbb_prune_actor($db);
		if(!phpbb_prune_admin_allowed($db,$user,'admin_forums')) { phpbb_prune_error('Not_Authorised'); }
		$rows=phpbb_prune_rows($db,'SELECT forum_id, forum_link, count_posts FROM '.FORUMS_TABLE.' WHERE forum_id IN ('.$source.($target!==null?','.$target:'').')');
		$forums=array(); foreach($rows as $row) { $forums[(int)$row['forum_id']]=$row; }
		if(!isset($forums[$source]) || ($target!==null && (!isset($forums[$target]) || !empty($forums[$target]['forum_link']))) || phpbb_forum_has_children($db,$source)) { phpbb_prune_error('Prune_selection_changed'); }
		$contents=phpbb_forum_contents($db,$source);
		$moderators=phpbb_prune_rows($db,'SELECT DISTINCT ug.user_id FROM '.AUTH_ACCESS_TABLE.' a JOIN '.USER_GROUP_TABLE.' ug ON ug.group_id = a.group_id WHERE a.forum_id = '.$source.' AND a.auth_mod = 1');
		$moderator_ids=array(); foreach($moderators as $row) { if((int)$row['user_id']>0) { $moderator_ids[]=(int)$row['user_id']; } }
		if($target===null)
		{
			if($contents['topics'])
			{
				$guard=' AND NOT EXISTS (SELECT 1 FROM '.POSTS_TABLE.' p WHERE p.topic_id = '.TOPICS_TABLE.'.topic_id AND p.forum_id <> '.$source.')';
				// A newly added/replaced post was not in the selected full contents.
				// Do not consume it through a previously selected parent topic.
				$guard.=' AND NOT EXISTS (SELECT 1 FROM '.POSTS_TABLE.' p WHERE (p.forum_id = '.$source.' OR p.topic_id = '.TOPICS_TABLE.'.topic_id) AND p.post_id NOT IN ('.($contents['posts']?implode(',',$contents['posts']):'0').'))';
				phpbb_delete_moderated_topics_owned($db,$source,$contents['topics'],$guard);
			}
		}
		elseif($contents['topics'])
		{
			$topic_list=implode(',',$contents['topics']); $post_list=$contents['posts']?implode(',',$contents['posts']):'0';
			// Aggregate snapshots force complete-set validation in the same SQL
			// statement. LEFT JOIN preserves empty topics and empty redirect stubs.
			$db->sql_query('UPDATE '.TOPICS_TABLE.' t LEFT JOIN '.POSTS_TABLE.' p ON p.topic_id = t.topic_id AND p.forum_id = t.forum_id'
				.' JOIN '.FORUMS_TABLE.' origin ON origin.forum_id = t.forum_id JOIN '.FORUMS_TABLE.' destination ON destination.forum_id = '.$target
				.' JOIN (SELECT COUNT(*) AS total, COALESCE(SUM(CASE WHEN topic_id IN ('.$topic_list.') THEN 1 ELSE 0 END),0) AS selected_total FROM '.TOPICS_TABLE.' WHERE forum_id = '.$source.') ts ON ts.total = '.count($contents['topics']).' AND ts.selected_total = '.count($contents['topics'])
				.' JOIN (SELECT COUNT(*) AS total, COALESCE(SUM(CASE WHEN forum_id = '.$source.' AND post_id IN ('.$post_list.') THEN 1 ELSE 0 END),0) AS selected_total FROM '.POSTS_TABLE.' WHERE forum_id = '.$source.' OR topic_id IN ('.$topic_list.')) ps ON ps.total = '.count($contents['posts']).' AND ps.selected_total = '.count($contents['posts'])
				.' SET t.forum_id = '.$target.', p.forum_id = '.$target.' WHERE t.forum_id = '.$source.' AND t.topic_id IN ('.$topic_list.')'
				." AND COALESCE(destination.forum_link, '') = '' AND origin.count_posts = '".$db->sql_escape($forums[$source]['count_posts'])."' AND destination.count_posts = '".$db->sql_escape($forums[$target]['count_posts'])."'");
			if((int)$db->sql_affectedrows()!==count($contents['topics'])+count($contents['posts'])) { phpbb_prune_error('Prune_selection_changed'); }
			if((bool)$forums[$source]['count_posts']!==(bool)$forums[$target]['count_posts'] && $contents['posters'])
			{
				$db->sql_query('UPDATE '.USERS_TABLE.' SET user_posts = (SELECT COUNT(*) FROM '.POSTS_TABLE.' p JOIN '.FORUMS_TABLE.' f ON f.forum_id = p.forum_id WHERE p.poster_id = '.USERS_TABLE.'.user_id AND f.count_posts = 1) WHERE user_id IN ('.implode(',',$contents['posters']).') AND user_id > 0');
			}
			require_once dirname(__FILE__) . '/functions_posting_storage.php';
			phpbb_posting_sync_forum($db,$target);
		}
		// Self-join avoids selecting a deleted table in a MySQL subquery. A late
		// child or remaining topic/post prevents parent deletion and orphaning.
		$db->sql_query('DELETE f FROM '.FORUMS_TABLE.' f LEFT JOIN '.FORUMS_TABLE." child ON child.main_type = 'f' AND child.cat_id = f.forum_id"
			.' LEFT JOIN '.CATEGORIES_TABLE." c ON c.cat_main_type = 'f' AND c.cat_main = f.forum_id"
			.' LEFT JOIN '.TOPICS_TABLE.' t ON t.forum_id = f.forum_id LEFT JOIN '.POSTS_TABLE.' p ON p.forum_id = f.forum_id'
			.' WHERE f.forum_id = '.$source.' AND child.forum_id IS NULL AND c.cat_id IS NULL AND t.topic_id IS NULL AND p.post_id IS NULL');
		if((int)$db->sql_affectedrows()!==1) { phpbb_prune_error('Prune_selection_changed'); }
		foreach(array(AUTH_ACCESS_TABLE,PRUNE_TABLE) as $table)
		{
			$db->sql_query('DELETE FROM '.$table.' WHERE forum_id = '.$source.' AND NOT EXISTS (SELECT 1 FROM '.FORUMS_TABLE.' WHERE forum_id = '.$source.')');
		}
		if($moderator_ids)
		{
			$db->sql_query('UPDATE '.USERS_TABLE.' SET user_level = '.USER.' WHERE user_level = '.MOD.' AND user_id IN ('.implode(',',$moderator_ids).')'
				.' AND NOT EXISTS (SELECT 1 FROM '.AUTH_ACCESS_TABLE.' a JOIN '.USER_GROUP_TABLE.' ug ON ug.group_id = a.group_id JOIN '.FORUMS_TABLE.' f ON f.forum_id = a.forum_id'
				.' WHERE ug.user_id = '.USERS_TABLE.'.user_id AND ug.user_pending = 0 AND a.auth_mod = 1)');
		}
		return array('topics'=>count($contents['topics']),'posts'=>count($contents['posts']),'target'=>$target);
	}
	finally { $lock->release(); }
}
