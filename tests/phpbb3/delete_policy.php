<?php
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 ||
	!preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
define('IN_PHPBB', true);
$phpbb_root_path = './';
$phpEx = 'php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
$row = static function ($sql) use ($db) {
	$result = $db->sql_query($sql);
	$row = $db->sql_fetchrow($result);
	$db->sql_freeresult($result);
	return $row;
};
$topic = 965200;
switch ($argv[2])
{
	case 'seed':
		if ((string) $config['plus_post_delete_policy'] !== '0') { throw new RuntimeException('The source deletion policy was not imported'); }
		$user->session_begin();
		$user->setup();
		$member = $row('SELECT * FROM ' . USERS_TABLE . " WHERE username='HistoricMember'");
		$auth->acl($member);
		if (!$auth->acl_get('f_softdelete', 15) || !$auth->acl_get('f_delete', 15) || $auth->acl_get('m_softdelete', 15))
		{ throw new RuntimeException('The personal source grant did not give the author the two native own-post options'); }
		$guest = $row('SELECT * FROM ' . USERS_TABLE . ' WHERE user_id=' . ANONYMOUS);
		$auth->acl($guest);
		if ($auth->acl_gets('f_softdelete', 'f_delete', 15)) { throw new RuntimeException('The guest acquired an account’s delete permission'); }
		$t = $row('SELECT * FROM ' . TOPICS_TABLE . ' WHERE topic_id=1');
		$p = $row('SELECT * FROM ' . POSTS_TABLE . ' WHERE post_id=1');
		$db->sql_transaction('begin');
		foreach (array($topic, $topic + 10) as $id)
		{
			$t = array_replace($t, array('topic_id'=>$id, 'forum_id'=>15, 'topic_poster'=>2, 'topic_title'=>'Deletion policy fixture',
				'topic_visibility'=>ITEM_APPROVED, 'topic_status'=>ITEM_UNLOCKED, 'topic_type'=>POST_NORMAL, 'topic_moved_id'=>0,
				'topic_posts_approved'=>3, 'topic_posts_unapproved'=>0, 'topic_posts_softdeleted'=>0, 'topic_first_post_id'=>$id,
				'topic_last_post_id'=>$id+2, 'topic_time'=>time(), 'topic_last_post_time'=>time(), 'poll_start'=>0, 'topic_attachment'=>0));
			$db->sql_query('INSERT INTO ' . TOPICS_TABLE . ' ' . $db->sql_build_array('INSERT', $t));
			for ($offset = 0; $offset < 3; $offset++)
			{
				$post = array_replace($p, array('post_id'=>$id+$offset, 'forum_id'=>15, 'topic_id'=>$id, 'post_time'=>time()-2+$offset,
					'poster_id'=>$offset ? (int) $member['user_id'] : 2, 'post_username'=>'', 'post_visibility'=>ITEM_APPROVED,
					'plus_public_post'=>1, 'post_edit_locked'=>0, 'post_postcount'=>0, 'post_subject'=>'Deletion policy reply',
					'post_text'=>'<t>Synthetic reply for the native deletion form</t>', 'post_attachment'=>0));
				$db->sql_query('INSERT INTO ' . POSTS_TABLE . ' ' . $db->sql_build_array('INSERT', $post));
			}
		}
		$db->sql_query('UPDATE ' . FORUMS_TABLE . ' SET forum_posts_approved=forum_posts_approved+6,
			forum_topics_approved=forum_topics_approved+2 WHERE forum_id=15');
		$config->increment('num_posts', 6, false);
		$config->increment('num_topics', 2, false);
		$db->sql_transaction('commit');
		break;
	case 'allow':
	case 'restrict':
		$config->set('plus_post_delete_policy', (int) ($argv[2] === 'allow'));
		break;
	case 'unchanged':
	case 'kept':
	case 'removed':
		$post = $row('SELECT post_visibility,plus_public_post FROM ' . POSTS_TABLE . ' WHERE post_id=' . ($topic+2));
		if ($argv[2] === 'removed')
		{
			if ($post) { throw new RuntimeException('An explicitly permitted permanent removal retained the post'); }
		}
		elseif (!$post || (int) $post['post_visibility'] !== ($argv[2] === 'kept' ? ITEM_DELETED : ITEM_APPROVED) || (int) $post['plus_public_post'] !== 1)
		{ throw new RuntimeException('The native deletion confirmation produced the wrong visibility'); }
		break;
	default: exit(2);
}
