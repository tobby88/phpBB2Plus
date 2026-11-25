<?php
// Create a normal guest topic and exercise phpBB's moderation API on the loopback fixture.
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || !in_array($argc, array(2, 3), true) ||
	!preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
define('IN_PHPBB', true);
$phpbb_root_path = './';
$phpEx = 'php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
if ($argc === 3)
{
	if (!ctype_digit($argv[2])) { exit(2); }
	$id = (int) $argv[2];
	$r = $db->sql_query('SELECT topic_id FROM ' . POSTS_TABLE . " WHERE post_id=$id AND forum_id=14
		AND post_username='CaseGuestNative' AND post_subject='Position fixture Removed three' AND post_visibility=" . ITEM_DELETED);
	$topic = (int) $db->sql_fetchfield('topic_id');
	$db->sql_freeresult($r);
	if (!$topic) { exit(2); }
	$user->session_begin();
	$user->setup();
	$phpbb_container->get('content.visibility')->set_post_visibility(ITEM_APPROVED, $id, $topic, 14, 2, time(), '', false, false);
	return;
}
require_once './includes/acp/auth.php';
require_once './includes/functions_posting.php';
$user->session_begin();
$user->setup();
$forum = 14;
$r = $db->sql_query('SELECT forum_name,forum_parents FROM ' . FORUMS_TABLE . ' WHERE forum_id=' . $forum);
$f = $db->sql_fetchrow($r);
$db->sql_freeresult($r);
if (!$f || $f['forum_name'] !== 'Ballots open to guests' || (int) $user->data['user_id'] !== ANONYMOUS) { exit(2); }
$writer = new auth_admin();
$writer->acl_set('user', $forum, ANONYMOUS, array('f_post'=>ACL_YES,'f_reply'=>ACL_YES,'f_noapprove'=>ACL_YES));
$auth->acl($user->data);
$posts = array();
$topic = 0;
$stamp = time() - 300;
$titles = array('Beginning', 'Removed one', 'Removed two', 'Middle', 'Removed three', 'End', 'Never published');
foreach ($titles as $index => $subject)
{
	$subject = 'Position fixture ' . $subject;
	$body = $subject . ' body';
	$uid = $bits = ''; $flags = 0;
	generate_text_for_storage($body, $uid, $bits, $flags, false, false, false);
	$poll = array();
	$data = array('forum_id'=>$forum, 'forum_name'=>$f['forum_name'], 'forum_parents'=>$f['forum_parents'], 'topic_id'=>$topic,
		'topic_title'=>'Position fixture Beginning', 'post_id'=>0,'topic_first_post_id'=>$posts ? $posts[0] : 0,
		'topic_last_post_id'=>$posts ? end($posts) : 0,'topic_status'=>ITEM_UNLOCKED,'topic_time_limit'=>0,'topic_attachment'=>0,
		'icon_id'=>0,'poster_ip'=>'192.0.2.181','poster_id'=>ANONYMOUS,'post_time'=>$stamp,'enable_sig'=>false,
		'enable_bbcode'=>false,'enable_smilies'=>false,'enable_urls'=>false,'enable_indexing'=>true,'message_md5'=>md5($body),
		'post_edit_locked'=>0,'post_edit_reason'=>'','post_edit_user'=>0,'notify'=>false,'notify_set'=>false,'post_checksum'=>'',
		'bbcode_uid'=>$uid,'bbcode_bitfield'=>$bits,'message'=>$body,'attachment_data'=>array(),'filename_data'=>array());
	if ($index === 6) { $data['force_approved_state'] = ITEM_UNAPPROVED; }
	submit_post($topic ? 'reply' : 'post', $subject, 'CaseGuestNative', POST_NORMAL, $poll, $data);
	$topic = (int) $data['topic_id'];
	$posts[] = (int) $data['post_id'];
}
$visibility = $phpbb_container->get('content.visibility');
$visibility->set_post_visibility(ITEM_DELETED, array($posts[1], $posts[2], $posts[4], $posts[6]), $topic, $forum, 2, time(),
	'Fixture moderation note', false, false);
$r = $db->sql_query('SELECT p.post_id,p.plus_public_post,b.author_key FROM ' . POSTS_TABLE . ' p LEFT JOIN ' .
	$table_prefix . 'plus_bylines b ON b.post_id=p.post_id WHERE p.topic_id=' . $topic . ' ORDER BY p.post_id');
$rows = $db->sql_fetchrowset($r);
$db->sql_freeresult($r);
if (array_map('intval', array_column($rows, 'plus_public_post')) !== array(1,1,1,1,1,1,0) ||
	array_unique(array_column($rows, 'author_key')) !== array(hash('sha256','caseguestnative')))
{ throw new RuntimeException('Native guest posting or moderation lost the byline or publication state'); }
$config->set('posts_per_page', 2);
echo json_encode(array('topic'=>$topic,'posts'=>$posts));
