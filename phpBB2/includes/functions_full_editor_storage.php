<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_posting_storage.php';
require_once dirname(__FILE__) . '/functions_post_submit_storage.php';
require_once dirname(__FILE__) . '/functions_post_subject.php';

class PhpbbFullEditorDatabase extends PhpbbPostSubmitDatabase
{
	var $post_id;
	var $poster_id = null;
	var $poll_id = 0;
	var $poll_changed = false;
	function __construct($connection, $forum_id, $topic_id, $post_id)
	{
		parent::__construct($connection, 'editpost', $forum_id, $topic_id, array());
		$this->post_id = phpbb_posting_scope_id($post_id);
	}
	function tables() { return array_merge(parent::tables(), array(LOGS_TABLE, VOTE_USERS_TABLE)); }
	function actor()
	{
		$user = parent::actor();
		if ((int)$user['user_id'] === ANONYMOUS) { $this->fail('Edit_own_posts'); }
		return $user;
	}
	function authorize()
	{
		$user = $this->actor();
		$rows = $this->rows('SELECT p.poster_id, f.forum_status, t.topic_status FROM ' . POSTS_TABLE . ' p'
			. ' JOIN ' . POSTS_TEXT_TABLE . ' pt ON pt.post_id = p.post_id'
			. ' JOIN ' . TOPICS_TABLE . ' t ON t.topic_id = p.topic_id AND t.forum_id = p.forum_id AND t.topic_moved_id = 0'
			. ' JOIN ' . FORUMS_TABLE . ' f ON f.forum_id = p.forum_id WHERE p.post_id = ' . $this->post_id
			. ' AND p.topic_id = ' . $this->topic_id . ' AND p.forum_id = ' . $this->forum_id);
		if (count($rows) !== 1 || ($this->poster_id !== null && $this->poster_id !== (int)$rows[0]['poster_id'])) { $this->fail('Posting_target_changed'); }
		$auth = auth(AUTH_ALL, $this->forum_id, $user, '', $this);
		if (empty($auth['auth_view']) || empty($auth['auth_read']) || (empty($auth['auth_mod']) && (empty($auth['auth_edit']) || (int)$rows[0]['poster_id'] !== (int)$user['user_id']))) { $this->fail('Edit_own_posts'); }
		foreach ($this->required as $key) { if (empty($auth[$key])) { $this->fail('Posting_submit_denied'); } }
		if (empty($auth['auth_mod']) && (int)$rows[0]['forum_status'] === FORUM_LOCKED) { $this->fail('Forum_locked'); }
		if (empty($auth['auth_mod']) && (int)$rows[0]['topic_status'] === TOPIC_LOCKED) { $this->fail('Topic_locked'); }
		if ($this->poll_changed && $this->poll_id && empty($auth['auth_mod']))
		{
			$votes = $this->rows('SELECT SUM(vote_result) AS votes FROM ' . VOTE_RESULTS_TABLE . ' WHERE vote_id = ' . $this->poll_id);
			$voters = $this->rows('SELECT vote_id FROM ' . VOTE_USERS_TABLE . ' WHERE vote_id = ' . $this->poll_id . ' LIMIT 1');
			if (!empty($votes[0]['votes']) || $voters) { $this->fail('Posting_target_changed'); }
		}
		return $auth;
	}
}

// Prepared fields use the legacy slash representation, not raw POST data.
function phpbb_full_edit_text($value)
{
	if (!is_string($value)) { throw new PhpbbPostSubmitException('Ajax_edit_invalid_text'); }
	$value = stripslashes($value);
	if (preg_match('//u', $value) !== 1) { throw new PhpbbPostSubmitException('Ajax_edit_invalid_text'); }
	return $value;
}

// Existing option IDs are identities, never dense display positions. A new
// poll may be numbered densely; an existing poll must retain its submitted IDs.
function phpbb_full_edit_poll_options($options, $existing)
{
	if (!is_array($options) || count($options) < 2 || count($options) > 255) { throw new PhpbbPostSubmitException('Full_edit_poll_options'); }
	$out = array(); $next = 1;
	foreach ($options as $id => $text)
	{
		if ((!is_int($id) && !is_string($id)) || !preg_match('/^[0-9]+$/D', (string)$id) || ($existing && ((int)$id < 1 || (int)$id > 255))) { throw new PhpbbPostSubmitException('Full_edit_poll_options'); }
		$id = $existing ? (int)$id : $next++;
		$text = phpbb_full_edit_text($text);
		if ($text === '' || preg_match_all('/./us', $text, $unused) > 255 || isset($out[$id])) { throw new PhpbbPostSubmitException('Full_edit_poll_options'); }
		$out[$id] = $text;
	}
	ksort($out, SORT_NUMERIC); return $out;
}

function phpbb_submit_full_edit($database, $forum_id, $topic_id, $post_id, $input)
{
	$lock = attach_require_mutation_lock($database);
	$db = null;
	try
	{
		$db = new PhpbbFullEditorDatabase($lock->connection, $forum_id, $topic_id, $post_id);
		$db->begin();
		$rows = $db->rows('SELECT p.*, pt.post_text, pt.post_subject, pt.bbcode_uid FROM ' . POSTS_TABLE . ' p JOIN ' . POSTS_TEXT_TABLE . ' pt ON pt.post_id = p.post_id WHERE p.post_id = ' . $db->post_id . ' FOR UPDATE');
		if (count($rows) !== 1) { $db->fail('Topic_post_not_exist'); }
		$post = $rows[0]; $db->poster_id = (int)$post['poster_id']; $auth = $db->authorize();
		$topic = $db->rows('SELECT * FROM ' . TOPICS_TABLE . ' WHERE topic_id = ' . $db->topic_id)[0];
		$db->rows('SELECT post_id FROM ' . POSTS_TABLE . ' WHERE topic_id = ' . $db->topic_id . ' ORDER BY post_id FOR UPDATE');
		$bounds = $db->rows('SELECT MIN(post_id) AS first_post, MAX(post_id) AS last_post FROM ' . POSTS_TABLE . ' WHERE topic_id = ' . $db->topic_id)[0];
		$first = (int)$bounds['first_post'] === $db->post_id; $last = (int)$bounds['last_post'] === $db->post_id;
		$subject = phpbb_storage_subject(htmlspecialchars_decode(phpbb_full_edit_text($input['subject']), ENT_QUOTES));
		if ($subject === false) { $db->fail('Ajax_edit_invalid_text'); }
		if ($first && $subject === '') { $db->fail('Empty_subject'); }
		$text = phpbb_full_edit_text($input['message']);
		if (trim($text) === '') { $db->fail('Empty_message'); }
		$uid = (string)$input['bbcode_uid'];
		if ((int)$input['bbcode_on'] === (int)$post['enable_bbcode'] && $uid !== '' && str_replace(':' . $uid, ':' . (string)$post['bbcode_uid'], $text) === (string)$post['post_text']) { $text = (string)$post['post_text']; $uid = (string)$post['bbcode_uid']; }
		$post_values = array('post_username'=>phpbb_full_edit_text($input['username']), 'enable_bbcode'=>(int)$input['bbcode_on'], 'enable_html'=>(int)$input['html_on'], 'enable_smilies'=>(int)$input['smilies_on'], 'enable_sig'=>(int)$input['attach_sig'], 'post_icon'=>(int)$input['post_icon']);
		foreach (array('enable_bbcode','enable_html','enable_smilies','enable_sig') as $key) { if (!in_array($post_values[$key], array(0,1), true)) { $db->fail('Ajax_edit_invalid_text'); } }
		$post_changed = $subject !== (string)$post['post_subject'] || $text !== (string)$post['post_text'] || $uid !== (string)$post['bbcode_uid'];
		foreach ($post_values as $key => $value) { if ((string)$value !== (string)$post[$key]) { $post_changed = true; } }
		$topic_values = array();
		if ($first)
		{
			$topic_values = array('topic_title'=>$subject, 'topic_desc'=>phpbb_full_edit_text($input['topic_desc']), 'topic_icon'=>(int)$input['post_icon']);
			$types = array(POST_NORMAL=>'auth_post',POST_STICKY=>'auth_sticky',POST_ANNOUNCE=>'auth_announce',POST_GLOBAL_ANNOUNCE=>'auth_global_announce',POST_NEWS=>'auth_news');
			$type = (int)$input['topic_type']; $news = (int)$input['news_category'];
			// Missing controls must not remove metadata the actor cannot manage.
			if (empty($auth['auth_news'])) { $news = (int)$topic['news_id']; }
			if ($news > 0) { $type = POST_NEWS; }
			if (!isset($types[$type])) { $db->fail('No_valid_mode'); }
			if ($type !== (int)$topic['topic_type']) { $db->required[] = $types[$type]; if (isset($types[(int)$topic['topic_type']])) { $db->required[] = $types[(int)$topic['topic_type']]; } }
			if ($news !== (int)$topic['news_id']) { $db->required[] = 'auth_news'; }
			$topic_values['topic_type'] = $type; $topic_values['news_id'] = $news;
			foreach (array('topic_calendar_time','topic_calendar_duration') as $key)
			{
				$value = empty($auth['auth_cal']) ? (int)$topic[$key] : (int)$input[$key];
				if ($value !== (int)$topic[$key]) { $db->required[] = 'auth_cal'; }
				$topic_values[$key] = $value;
			}
			$duration = (int)$input['topic_announce_duration'];
			if ($type === (int)$topic['topic_type'] && empty($auth[$types[$type]])) { $duration = (int)$topic['topic_announce_duration']; }
			if ($duration !== (int)$topic['topic_announce_duration'] && in_array($type,array(POST_ANNOUNCE,POST_GLOBAL_ANNOUNCE),true)) { $db->required[] = $types[$type]; }
			$topic_values['topic_announce_duration'] = $duration;
		}
		$polls = $db->rows('SELECT * FROM ' . VOTE_DESC_TABLE . ' WHERE topic_id = ' . $db->topic_id . ' FOR UPDATE');
		$poll = count($polls) === 1 ? $polls[0] : null;
		$poll_options = array(); $old_options = array(); $poll_title = phpbb_full_edit_text($input['poll_title']);
		$poll_requested = $first && ($poll_title !== '' || count($input['poll_options']));
		if ($poll_requested)
		{
			if (count($polls) > 1) { $db->fail('Full_edit_poll_ambiguous'); }
			if (($poll ? (int)$poll['vote_id'] : 0) !== (int)$input['poll_id']) { $db->fail('Posting_target_changed'); }
			if ($poll_title === '') { $db->fail('Empty_poll_title'); }
			$days = $input['poll_length'];
			if ((!is_int($days) && !is_string($days)) || !preg_match('/^[0-9]+$/D',(string)$days) || (int)$days > 24855) { $db->fail('Full_edit_poll_options'); }
			$poll_length = (int)$days * 86400;
			$poll_options = phpbb_full_edit_poll_options($input['poll_options'], $poll !== null);
			if ($poll)
			{
				$db->poll_id = (int)$poll['vote_id'];
				foreach ($db->rows('SELECT vote_option_id, vote_option_text, vote_result FROM ' . VOTE_RESULTS_TABLE . ' WHERE vote_id = ' . $db->poll_id . ' ORDER BY vote_option_id FOR UPDATE') as $option)
				{
					$id = (int)$option['vote_option_id'];
					if ($id < 1 || $id > 255 || isset($old_options[$id])) { $db->fail('Full_edit_poll_ambiguous'); }
					$old_options[$id] = (string)$option['vote_option_text'];
				}
				$db->rows('SELECT vote_id FROM ' . VOTE_USERS_TABLE . ' WHERE vote_id = ' . $db->poll_id . ' FOR UPDATE');
			}
			$db->poll_changed = !$poll || (string)$poll['vote_text'] !== $poll_title || (int)$poll['vote_length'] !== $poll_length || $old_options !== $poll_options;
			if ($db->poll_changed) { $db->required[] = 'auth_pollcreate'; }
			$topic_values['topic_vote'] = 1;
		}
		$topic_changed = false;
		foreach ($topic_values as $key => $value) { if ((string)$value !== (string)$topic[$key]) { $topic_changed = true; } }
		$auth = $db->authorize(); $changed = $post_changed || $topic_changed || $db->poll_changed;
		if ($changed)
		{
			if ($post_changed)
			{
				$assignments = array(); foreach ($post_values as $key => $value) { $assignments[] = $key . " = '" . $db->sql_escape((string)$value) . "'"; }
				if ((int)$post['poster_id'] === (int)$GLOBALS['userdata']['user_id'] && !$last) { $assignments[] = 'post_edit_time = ' . time(); $assignments[] = 'post_edit_count = COALESCE(post_edit_count, 0) + 1'; }
				$db->sql_query('UPDATE ' . POSTS_TABLE . ' SET ' . implode(', ', $assignments) . ' WHERE post_id = ' . $db->post_id);
				$db->sql_query('UPDATE ' . POSTS_TEXT_TABLE . " SET post_subject = '" . $db->sql_escape($subject) . "', post_text = '" . $db->sql_escape($text) . "', bbcode_uid = '" . $db->sql_escape($uid) . "' WHERE post_id = " . $db->post_id);
			}
			if ($topic_changed)
			{
				$assignments = array(); foreach ($topic_values as $key => $value) { $assignments[] = $key . " = '" . $db->sql_escape((string)$value) . "'"; }
				$db->sql_query('UPDATE ' . TOPICS_TABLE . ' SET ' . implode(', ', $assignments) . ' WHERE topic_id = ' . $db->topic_id);
			}
			if ($db->poll_changed)
			{
				if (!$poll)
				{
					$db->sql_query('INSERT INTO ' . VOTE_DESC_TABLE . " (topic_id, vote_text, vote_start, vote_length) VALUES (" . $db->topic_id . ", '" . $db->sql_escape($poll_title) . "', " . time() . ', ' . $poll_length . ')');
					$db->poll_id = (int)$db->sql_nextid();
				}
				else { $db->sql_query('UPDATE ' . VOTE_DESC_TABLE . " SET vote_text = '" . $db->sql_escape($poll_title) . "', vote_length = " . $poll_length . ' WHERE vote_id = ' . $db->poll_id); }
				foreach ($poll_options as $id => $value)
				{
					if (isset($old_options[$id]) && $old_options[$id] === $value) { continue; }
					$db->sql_query(isset($old_options[$id]) ? 'UPDATE ' . VOTE_RESULTS_TABLE . " SET vote_option_text = '" . $db->sql_escape($value) . "' WHERE vote_id = " . $db->poll_id . ' AND vote_option_id = ' . $id : 'INSERT INTO ' . VOTE_RESULTS_TABLE . " (vote_id, vote_option_id, vote_option_text, vote_result) VALUES (" . $db->poll_id . ', ' . $id . ", '" . $db->sql_escape($value) . "', 0)");
				}
				foreach ($old_options as $id => $value) { if (!isset($poll_options[$id])) { $db->sql_query('DELETE FROM ' . VOTE_RESULTS_TABLE . ' WHERE vote_id = ' . $db->poll_id . ' AND vote_option_id = ' . $id); } }
			}
			if ($subject !== (string)$post['post_subject'] || $text !== (string)$post['post_text'])
			{
				require_once dirname(__FILE__) . '/functions_search.php';
				remove_search_post($db->post_id, true, true, $db); add_search_words('single', $db->post_id, $text, $subject, $db);
			}
			$auth = $db->authorize();
			if (!empty($auth['auth_mod']))
			{
				require_once dirname(__FILE__) . '/functions_log.php';
				$user = $db->actor(); $names = $db->rows('SELECT username FROM ' . USERS_TABLE . ' WHERE user_id = ' . (int)$user['user_id']);
				if (!log_action('edit', $db->topic_id, (int)$user['user_id'], $names[0]['username'], $db)) { $db->fail('Posting_submit_unconfirmed'); }
			}
		}
		$db->commit();
		return array('bbcode_uid'=>$uid, 'poll_id'=>$db->poll_id ?: ($poll ? (int)$poll['vote_id'] : 0), 'post_data'=>array('first_post'=>$first, 'last_post'=>$last, 'poster_id'=>(int)$post['poster_id'], 'poster_post'=>(int)$post['poster_id']===(int)$GLOBALS['userdata']['user_id'], 'has_poll'=>(bool)$polls || $db->poll_changed, '_edit_audit_completed'=>true));
	}
	catch (PhpbbPostSubmitException $e) { throw $e; }
	catch (Exception $e) { throw new PhpbbPostSubmitException('Posting_submit_unconfirmed'); }
	catch (Error $e) { throw new PhpbbPostSubmitException('Posting_submit_unconfirmed'); }
	finally { if ($db !== null) { $db->rollback(); } $lock->release(); }
}
