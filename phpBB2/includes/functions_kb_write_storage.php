<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_kb_publication.php';

// Separate owner for frontend add/edit, including ordinary authors and explicitly
// permitted anonymous submissions. ACP delegation alone grants no KB authorship.
class PhpbbKbWriteDatabase extends PhpbbKbPublicationDatabase
{
	var $mode;
	var $article_id;
	var $article = null;
	var $settings = array();
	var $hierarchy = array();
	var $approval = null;
	function __construct($database, $mode, $article_id)
	{
		if (!in_array($mode, array('add','edit'), true)) { kb_publication_fail('KB_write_failed'); }
		$this->mode = $mode; $this->article_id = $article_id;
		parent::__construct($database, 'moderator');
	}
	function write_tables()
	{
		return array_merge(parent::write_tables(), array(PRIVMSGS_TABLE, PRIVMSGS_TEXT_TABLE));
	}
	function actor()
	{
		global $userdata;
		if (!$this->settings || empty($userdata['session_id']) || !is_string($userdata['session_id'])) { kb_publication_fail('Not_Authorised'); }
		$guest = empty($userdata['session_logged_in']);
		if ($guest) {
			if (!isset($userdata['user_id']) || (string)$userdata['user_id'] !== (string)ANONYMOUS) { kb_publication_fail('Not_Authorised'); }
			$user = array('user_id'=>ANONYMOUS,'user_level'=>USER,'username'=>'','user_attachsig'=>0);
		} else {
			$user = phpbb_current_moderator_user($this);
			if (!$user) { kb_publication_fail('Not_Authorised'); }
		}
		$id = (int)$user['user_id']; $sid = $this->sql_escape($userdata['session_id']);
		if (count($this->rows('SELECT session_id FROM ' . SESSIONS_TABLE . " WHERE session_id='" . $sid . "' AND HEX(session_id)=HEX('" . $sid . "') AND session_user_id=" . $id . ' AND session_logged_in=' . ($guest ? 0 : 1))) !== 1) { kb_publication_fail('Not_Authorised'); }
		$moderator = !$guest && (int)$user['user_level'] === ADMIN;
		if (!$guest && !$moderator && $this->settings['mod_group'] !== '0') {
			try { $group = kb_counter_category_id($this->settings['mod_group']); } catch (RuntimeException $error) { kb_publication_fail('Not_Authorised'); }
			$moderator = count($this->rows('SELECT g.group_id FROM ' . GROUPS_TABLE . ' g INNER JOIN ' . USER_GROUP_TABLE . ' m ON m.group_id=g.group_id WHERE g.group_id=' . $group . ' AND m.user_id=' . $id . ' AND m.user_pending=0')) === 1;
		}
		if ($this->mode === 'add') {
			if (!$moderator && ($this->settings['allow_new'] !== '1' || ($guest && $this->settings['allow_anon'] !== '1'))) { kb_publication_fail('No_add'); }
		} elseif (!$moderator && ($guest || !$this->article || (int)$this->article['article_author_id'] !== $id)) { kb_publication_fail('No_edit'); }
		$user['kb_moderator'] = $moderator;
		if ($this->approval !== null) {
			$current = $this->mode === 'add' ? (($moderator || (int)$user['user_level'] === MOD || $this->settings['approve_new'] === '0') ? 1 : 0)
				: (($moderator || $this->settings['approve_edit'] === '0') ? 1 : 2);
			if ($current !== $this->approval) { kb_publication_fail('Not_Authorised'); }
		}
		return $user;
	}
	function begin()
	{
		$this->control("SET SESSION sql_mode = CONCAT_WS(',', @@SESSION.sql_mode, 'STRICT_ALL_TABLES')");
		$this->control('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
		$this->control('START TRANSACTION'); $this->transactional = true;
		kb_publication_pin_tables($this, array_unique(array_merge($this->write_tables(), array(KB_CONFIG_TABLE,KB_TYPES_TABLE,SESSIONS_TABLE,JR_ADMIN_TABLE,GROUPS_TABLE,USER_GROUP_TABLE))));
		foreach ($this->rows('SELECT config_name,config_value FROM ' . KB_CONFIG_TABLE . ' ORDER BY config_name FOR UPDATE') as $row) { $this->settings[$row['config_name']] = $row['config_value']; }
		foreach (array('allow_new','allow_anon','approve_new','approve_edit','comments','bump_post') as $flag) {
			if (!isset($this->settings[$flag]) || !in_array($this->settings[$flag], array('0','1'), true)) { kb_publication_fail('KB_write_failed'); }
		}
		if (!isset($this->settings['notify'],$this->settings['mod_group']) || !in_array($this->settings['notify'], array('0','1','2'), true)) { kb_publication_fail('KB_write_failed'); }
		$categories = $this->rows('SELECT category_id,parent,number_articles FROM ' . KB_CATEGORIES_TABLE . ' ORDER BY category_id FOR UPDATE');
		try { $this->hierarchy = kb_counter_rebuild_plan($categories, array()); } catch (RuntimeException $error) { kb_publication_fail('KB_write_failed'); }
		if ($this->mode === 'edit') {
			$rows = $this->rows('SELECT * FROM ' . KB_ARTICLES_TABLE . ' WHERE article_id=' . $this->article_id . ' FOR UPDATE');
			if (count($rows) !== 1) { kb_publication_fail('Article_not_exsist'); }
			$this->article = $rows[0];
			if (!in_array((string)$this->article['approved'], array('0','1','2'), true) || !isset($this->hierarchy['expected'][(int)$this->article['article_category_id']])) { kb_publication_fail('KB_write_failed'); }
		}
		$this->actor();
	}
}

function kb_write_article($database, $mode, $article_id, $input)
{
	global $userdata;
	if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST' || empty($userdata['session_id']) || !is_string($userdata['session_id']) || !isset($_POST['sid']) || !is_string($_POST['sid']) || !hash_equals($userdata['session_id'], $_POST['sid'])) { kb_publication_fail('Session_invalid'); }
	if (!is_array($input)) { kb_publication_fail('KB_write_invalid'); }
	foreach (array('title','description','body','username') as $field) {
		if (!isset($input[$field]) || !is_string($input[$field]) || strpos($input[$field], "\0") !== false || preg_match('//u', $input[$field]) !== 1) { kb_publication_fail('KB_write_invalid'); }
	}
	foreach (array('title','description','body') as $field) { if (trim($input[$field]) === '') { kb_publication_fail('KB_write_invalid'); } }
	foreach (array('html_on','bbcode_on','smilies_on') as $field) { if (!isset($input[$field]) || !in_array($input[$field], array(0,1,false,true), true)) { kb_publication_fail('KB_write_invalid'); } }
	try {
		$category = kb_counter_category_id(isset($input['category']) ? $input['category'] : null);
		$type = kb_counter_category_id(isset($input['type']) ? $input['type'] : null);
		$article_id = $mode === 'add' ? 0 : kb_counter_category_id($article_id);
	} catch (RuntimeException $error) { kb_publication_fail('KB_write_invalid'); }
	$owner = new PhpbbKbWriteDatabase($database, $mode, $article_id);
	$original = $GLOBALS['db']; $original_config = $GLOBALS['kb_config'];
	try {
		$GLOBALS['db'] = $owner; $owner->begin(); $GLOBALS['kb_config'] = $owner->settings; $actor = $owner->actor();
		if (!isset($owner->hierarchy['expected'][$category]) || count($owner->rows('SELECT id FROM ' . KB_TYPES_TABLE . ' WHERE id=' . $type . ' FOR UPDATE')) !== 1) { kb_publication_fail('KB_write_invalid'); }
		$approved = $mode === 'add' ? ((!empty($actor['kb_moderator']) || (int)$actor['user_level'] === MOD || $owner->settings['approve_new'] === '0') ? 1 : 0)
			: ((!empty($actor['kb_moderator']) || $owner->settings['approve_edit'] === '0') ? 1 : 2);
		$uid = $input['bbcode_on'] ? make_bbcode_uid() : '';
		$owner->approval = $approved;
		$body = prepare_message(trim($input['body']), $input['html_on'], $input['bbcode_on'], $input['smilies_on'], $uid);
		$title = kb_limit_text($input['title'],255); $description = kb_limit_text($input['description'],255);
		$author = $mode === 'edit' ? (int)$owner->article['article_author_id'] : ((int)$actor['user_id'] > 0 ? (int)$actor['user_id'] : 0);
		$username = $mode === 'edit' ? $owner->article['username'] : $input['username'];
		if ($mode === 'add' && $author === 0) {
			$username = phpbb_post_author_input($username);
			if ($username === null) { kb_publication_fail('Post_author_invalid'); }
		}
		if ($mode === 'add' && $author > 0) {
			$users = $owner->rows('SELECT username FROM ' . USERS_TABLE . ' WHERE user_id=' . $author);
			if (count($users) !== 1) { kb_publication_fail('KB_write_failed'); } $username = $users[0]['username'];
		}
		$fields = 'article_category_id=' . $category . ",article_title='" . $owner->sql_escape($title) . "',article_description='" . $owner->sql_escape($description) . "',article_date=" . time() . ',article_author_id=' . $author . ",username='" . $owner->sql_escape($username) . "',bbcode_uid='" . $owner->sql_escape($uid) . "',article_body='" . $owner->sql_escape($body) . "',article_type=" . $type . ',approved=' . $approved;
		if ($mode === 'add') {
			$owner->sql_query('INSERT INTO ' . KB_ARTICLES_TABLE . ' SET ' . $fields . ',views=0'); $article_id = kb_counter_category_id($owner->sql_nextid());
		} else { $owner->sql_query('UPDATE ' . KB_ARTICLES_TABLE . ' SET ' . $fields . ' WHERE article_id=' . $article_id); }
		$old = $owner->article;
		if ($old && (int)$old['approved'] === 1 && ($approved !== 1 || (int)$old['article_category_id'] !== $category)) { update_kb_number((int)$old['article_category_id'], -1); }
		if ($approved === 1 && (!$old || (int)$old['approved'] !== 1 || (int)$old['article_category_id'] !== $category)) { update_kb_number($category, 1); }
		$rows = $owner->rows('SELECT * FROM ' . KB_ARTICLES_TABLE . ' WHERE article_id=' . $article_id);
		if (count($rows) !== 1) { kb_publication_fail('KB_write_failed'); } $article = $rows[0];
		if ($approved === 1 && $owner->settings['comments'] === '1') { kb_write_discussion($owner, $article, $actor); }
		if ($approved === 1) { add_kb_words($article_id, $body, $title); } else { kb_remove_article_words($article_id); }
		if ($approved !== 1 && $owner->settings['notify'] === '1') { kb_write_pending_pm($owner, $article); }
		$owner->commit();
		return array('article_id'=>$article_id,'article_title'=>$title,'approved'=>$approved,'notify_email'=>$approved !== 1 && $owner->settings['notify'] === '2');
	} catch (PhpbbKbPublicationException $error) { throw $error; }
	catch (Exception $error) { kb_publication_fail('KB_write_failed'); }
	catch (Error $error) { kb_publication_fail('KB_write_failed'); }
	finally { $GLOBALS['db'] = $original; $GLOBALS['kb_config'] = $original_config; $owner->release(); }
}

function kb_write_discussion($owner, $article, $actor)
{
	global $lang, $phpEx;
	$topic = kb_counter_category_id($article['topic_id'], true);
	if ($topic) {
		$references = $owner->rows('SELECT article_id FROM ' . KB_ARTICLES_TABLE . ' WHERE topic_id=' . $topic . ' ORDER BY article_id FOR UPDATE');
		$topics = $owner->rows('SELECT forum_id,topic_first_post_id,topic_moved_id FROM ' . TOPICS_TABLE . ' WHERE topic_id=' . $topic . ' FOR UPDATE');
		if (count($references) !== 1 || (int)$references[0]['article_id'] !== (int)$article['article_id'] || count($topics) !== 1 || (int)$topics[0]['topic_moved_id'] !== 0) { kb_publication_fail('KB_deletion_relation'); }
		$forum = kb_counter_category_id($topics[0]['forum_id']); $first = kb_counter_category_id($topics[0]['topic_first_post_id']);
		$posts = $owner->rows('SELECT post_id,topic_id,forum_id FROM ' . POSTS_TABLE . ' WHERE post_id=' . $first . ' FOR UPDATE');
		if (count($posts) !== 1 || (int)$posts[0]['topic_id'] !== $topic || (int)$posts[0]['forum_id'] !== $forum || $owner->rows('SELECT post_id FROM ' . POSTS_TABLE . ' WHERE topic_id=' . $topic . ' AND forum_id<>' . $forum . ' LIMIT 1 FOR UPDATE')) { kb_publication_fail('KB_deletion_relation'); }
	} else { $forum = kb_counter_category_id(isset($owner->settings['forum_id']) ? $owner->settings['forum_id'] : null); }
	$forums = $owner->rows('SELECT forum_id,forum_link,count_posts FROM ' . FORUMS_TABLE . ' WHERE forum_id=' . $forum . ' FOR UPDATE');
	if (count($forums) !== 1 || (string)$forums[0]['forum_link'] !== '' || !in_array((string)$forums[0]['count_posts'], array('0','1'), true)) { kb_publication_fail('KB_write_failed'); }
	$message = kb_discussion_message($article);
	$poster = (int)$actor['user_id'];
	if ($poster > 0) {
		$users = $owner->rows('SELECT username,user_attachsig FROM ' . USERS_TABLE . ' WHERE user_id=' . $poster . ' FOR UPDATE');
		if (count($users) !== 1) { kb_publication_fail('KB_write_failed'); } $user = $users[0];
	} else { $user = array('username'=>$article['username'],'user_attachsig'=>0); }
	$update = $topic ? '[ [i]' . kb_discussion_literal($lang['Edited_Article_info']) . kb_discussion_literal($user['username'], false) . "[/i] ]\n\n" : '';
	$created = kb_insert_discussion_post($message, '[ KB ] ' . $article['article_title'], $forum, $poster, $user['username'], $user['user_attachsig'], $topic ? $topic : null, $update, $topic ? (int)$owner->settings['bump_post'] : 1);
	if (!is_array($created) || empty($created['topic_id']) || empty($created['post_id'])) { kb_publication_fail('KB_write_failed'); }
	$topic = kb_counter_category_id($created['topic_id']);
	$owner->sql_query('UPDATE ' . KB_ARTICLES_TABLE . ' SET topic_id=' . $topic . ' WHERE article_id=' . (int)$article['article_id']);
	// insert_post updates the first post even when bumping adds a reply. Reindex
	// that stored first post too, not only the optional newly created reply.
	$rows = $owner->rows('SELECT t.topic_first_post_id,p.post_text,p.post_subject FROM ' . TOPICS_TABLE . ' t INNER JOIN ' . POSTS_TEXT_TABLE . ' p ON p.post_id=t.topic_first_post_id WHERE t.topic_id=' . $topic);
	if (count($rows) !== 1) { kb_publication_fail('KB_write_failed'); }
	remove_search_post((int)$rows[0]['topic_first_post_id'],true,true,$owner);
	add_search_words('edit',(int)$rows[0]['topic_first_post_id'],$rows[0]['post_text'],$rows[0]['post_subject'],$owner);
}

function kb_write_pending_pm($owner, $article)
{
	global $lang, $phpEx, $user_ip;
	if (!is_string($user_ip) || preg_match('/^[a-f0-9]{8}$/iD', $user_ip) !== 1) { kb_publication_fail('KB_write_failed'); }
	$recipient = kb_counter_category_id(isset($owner->settings['admin_id']) ? $owner->settings['admin_id'] : null);
	$users = $owner->rows('SELECT user_id,user_active FROM ' . USERS_TABLE . ' WHERE user_id=' . $recipient . ' FOR UPDATE');
	if (count($users) !== 1 || empty($users[0]['user_active'])) { kb_publication_fail('KB_write_failed'); }
	$uid = make_bbcode_uid(); $now = time();
	// Pending metadata has not been reviewed. Only the generated ACP link is
	// BBCode, never a submitter's title/description (including remote images).
	$plain = function($value) { return str_replace(array('[',']'),array('&#91;','&#93;'),$value); };
	$text = $lang['Email_body'] . "\n\n" . $plain($article['article_title']) . "\n" . $plain($article['article_description']) . "\n[url=" . phpbb_board_url('admin/admin_kb_art.' . $phpEx) . ']' . $lang['Art_man'] . '[/url]';
	$text = prepare_message($text,false,true,false,$uid);
	$owner->sql_query('INSERT INTO ' . PRIVMSGS_TABLE . ' (privmsgs_type,privmsgs_subject,privmsgs_from_userid,privmsgs_to_userid,privmsgs_date,privmsgs_ip,privmsgs_enable_html,privmsgs_enable_bbcode,privmsgs_enable_smilies,privmsgs_attach_sig) VALUES(' . PRIVMSGS_NEW_MAIL . ", '" . $owner->sql_escape($lang['New_article']) . "'," . $recipient . ',' . $recipient . ',' . $now . ",'" . $owner->sql_escape($user_ip) . "',0,1,0,0)");
	$id = kb_counter_category_id($owner->sql_nextid());
	$owner->sql_query('INSERT INTO ' . PRIVMSGS_TEXT_TABLE . " (privmsgs_text_id,privmsgs_bbcode_uid,privmsgs_text) VALUES(" . $id . ",'" . $owner->sql_escape($uid) . "','" . $owner->sql_escape($text) . "')");
	$owner->sql_query('UPDATE ' . USERS_TABLE . ' SET user_last_privmsg=' . $now . ',user_new_privmsg=(SELECT COUNT(*) FROM ' . PRIVMSGS_TABLE . ' WHERE privmsgs_to_userid=' . $recipient . ' AND privmsgs_type=' . PRIVMSGS_NEW_MAIL . ' AND privmsgs_write_payload IS NULL) WHERE user_id=' . $recipient);
}
