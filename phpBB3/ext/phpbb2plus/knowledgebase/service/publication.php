<?php
namespace phpbb2plus\knowledgebase\service;

class publication
{
	private $articles;
	private $db;
	private $auth;
	private $user;
	private $config;
	private $language;
	private $prefix;
	private $root;
	private $php_ext;

	public function __construct($articles, $db, $auth, $user, $config, $language, $prefix, $root, $php_ext)
	{
		$this->articles = $articles;
		$this->db = $db;
		$this->auth = $auth;
		$this->user = $user;
		$this->config = $config;
		$this->language = $language;
		$this->prefix = $prefix;
		$this->root = $root;
		$this->php_ext = $php_ext;
	}

	private function article_url($id)
	{
		// The configured board URL is durable. Never save an editor's session URL in a post or message.
		return rtrim(generate_board_url(), '/') . '/app.' . $this->php_ext . '/knowledge/article/' . (int) $id;
	}

	public function pending($a)
	{
		$mode = (int) $this->articles->setting('notify', 0);
		if ($mode === 2)
		{
			$this->db->sql_query('INSERT INTO ' . $this->prefix . 'mail ' . $this->db->sql_build_array('INSERT',
				array('article_id' => (int) $a['article_id'], 'revision' => (int) $a['revision'], 'queued_at' => time())));
			return;
		}
		if ($mode !== 1 || !$this->config['allow_privmsg']) { return; }
		$id = (int) $this->articles->setting('admin_id', 0);
		$result = $this->db->sql_query_limit('SELECT user_id,username,user_lang FROM ' . USERS_TABLE . ' WHERE user_id=' . $id .
			' AND ' . $this->db->sql_in_set('user_type', array(USER_NORMAL, USER_FOUNDER)), 1);
		$recipient = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		if (!$recipient || $id <= ANONYMOUS) { return; }
		include_once $this->root . 'includes/functions_privmsgs.' . $this->php_ext;
		$locale = clone $this->language;
		$locale->set_user_language($recipient['user_lang'], true);
		$locale->add_lang('knowledgebase', 'phpbb2plus/knowledgebase');
		$subject = utf8_htmlspecialchars($locale->lang('PLUS_KB_REVIEW_PM'));
		// Disable BBCode for user-supplied metadata in this generated notice.
		$text = utf8_htmlspecialchars($a['title'] . "\n\n" . $this->article_url($a['article_id']));
		$uid = $bits = ''; $flags = 0;
		generate_text_for_storage($text, $uid, $bits, $flags, false, true, false);
		$data = array('from_user_id' => $id, 'from_user_ip' => $this->user->ip, 'from_username' => $recipient['username'],
			'reply_from_root_level' => 0, 'reply_from_msg_id' => 0, 'icon_id' => 0, 'enable_sig' => false, 'enable_bbcode' => false,
			'enable_smilies' => false, 'enable_urls' => true, 'bbcode_bitfield' => $bits, 'bbcode_uid' => $uid, 'message' => $text,
			'attachment_data' => array(), 'filename_data' => array(), 'address_list' => array('u' => array($id => 'to')));
		submit_pm('post', $subject, $data, false);
	}

	private function topic($a)
	{
		$result = $this->db->sql_query('SELECT t.*,p.post_id AS intro_id FROM ' . TOPICS_TABLE . ' t JOIN ' . POSTS_TABLE . ' p
			ON p.post_id=t.topic_first_post_id AND p.topic_id=t.topic_id AND p.forum_id=t.forum_id WHERE t.topic_id=' . (int) $a['topic_id']);
		$topic = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		if (!$topic || (int) $topic['intro_id'] !== (int) $a['intro_post'] || $topic['topic_moved_id']) { return false; }
		$result = $this->db->sql_query('SELECT COUNT(*) AS total FROM ' . $this->prefix . 'articles WHERE topic_id=' . (int) $a['topic_id']);
		$single = (int) $this->db->sql_fetchfield('total') === 1;
		$this->db->sql_freeresult($result);
		return $single ? $topic : false;
	}

	// Native post subjects and guest names use phpBB's character references on
	// MySQL. Preserve complete references when the encoded value reaches the limit.
	private function native_label($text, $limit)
	{
		$result = ''; $stored = 0;
		foreach (utf8_str_split(utf8_substr($text, 0, $limit)) as $character)
		{
			$encoded = utf8_encode_ucr(utf8_htmlspecialchars($character));
			$stored += utf8_strlen($encoded);
			if ($stored > 255) { break; }
			$result .= $encoded;
		}
		return $result;
	}

	private function post($a, $topic, $hash)
	{
		$forum = $topic ? (int) $topic['forum_id'] : (int) $this->articles->setting('forum_id', 0);
		$result = $this->db->sql_query('SELECT forum_id,forum_type,forum_status,forum_password,forum_name,forum_parents,enable_indexing FROM ' . FORUMS_TABLE . ' WHERE forum_id=' . $forum);
		$f = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		if (!$f || (int) $f['forum_type'] !== FORUM_POST || (int) $f['forum_status'] === ITEM_LOCKED ||
			!$this->auth->acl_get('f_list', $forum) || !$this->auth->acl_get('f_read', $forum) || !$this->auth->acl_get($topic ? 'f_reply' : 'f_post', $forum) ||
			!$this->articles->forum_open($forum, $f['forum_password']) || ($topic && (int) $topic['topic_status'] === ITEM_LOCKED)) { return false; }
		include_once $this->root . 'includes/functions_posting.' . $this->php_ext;
		$subject = $this->native_label('[KB] ' . $a['title'], 120);
		$text = utf8_htmlspecialchars($this->language->lang($topic ? 'PLUS_KB_UPDATED_POST' : 'PLUS_KB_DISCUSSION_POST') . "\n\n" . $this->article_url($a['article_id']));
		$uid = $bits = ''; $flags = 0;
		generate_text_for_storage($text, $uid, $bits, $flags, false, true, false);
		$poll = array();
		$data = array('plus_kb_article' => (int) $a['article_id'], 'plus_kb_digest' => $hash,
			'forum_id' => $forum, 'forum_name' => $f['forum_name'], 'forum_parents' => $f['forum_parents'],
			'topic_title' => $topic ? $topic['topic_title'] : $subject, 'topic_id' => $topic ? (int) $topic['topic_id'] : 0, 'post_id' => 0,
			'topic_first_post_id' => $topic ? (int) $topic['topic_first_post_id'] : 0, 'topic_last_post_id' => $topic ? (int) $topic['topic_last_post_id'] : 0,
			'topic_status' => ITEM_UNLOCKED, 'topic_time_limit' => 0, 'topic_attachment' => 0, 'icon_id' => 0,
			'poster_id' => (int) $this->user->data['user_id'], 'poster_ip' => $this->user->ip, 'enable_sig' => false, 'enable_bbcode' => false,
			'enable_smilies' => false, 'enable_urls' => true, 'enable_indexing' => (bool) $f['enable_indexing'], 'message_md5' => md5($text),
			'post_edit_locked' => 0, 'post_edit_reason' => '', 'post_edit_user' => 0, 'notify' => false, 'notify_set' => false, 'post_checksum' => '',
			'bbcode_uid' => $uid, 'bbcode_bitfield' => $bits, 'message' => $text, 'attachment_data' => array(), 'filename_data' => array());
		// New native posts follow the forum's own approval rules and posting identity.
		submit_post($topic ? 'reply' : 'post', $subject, empty($this->user->data['is_registered']) ? $this->native_label($a['author_name'], 32) : '', POST_NORMAL, $poll, $data);
		if (empty($data['topic_id']) || empty($data['post_id'])) { throw new \RuntimeException('phpBB failed to create the article discussion.'); }
		return $data;
	}

	private function recover($a)
	{
		// The receipt is inserted with the native post. Retried article submissions
		// can reconnect it even if the process stopped before recording the link.
		$id = (int) $a['article_id'];
		$result = $this->db->sql_query_limit('SELECT p.topic_id,p.plus_kb_digest,t.topic_first_post_id FROM ' . POSTS_TABLE . ' p
			JOIN ' . TOPICS_TABLE . ' t ON t.topic_id=p.topic_id JOIN ' . POSTS_TABLE . ' firstpost ON firstpost.post_id=t.topic_first_post_id
			WHERE p.plus_kb_article=' . $id . ' AND firstpost.plus_kb_article=' . $id .
			($a['topic_id'] ? ' AND t.topic_id=' . (int) $a['topic_id'] : '') . ' ORDER BY p.post_id DESC', 1);
		$post = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		if ($post && ((int) $a['topic_id'] !== (int) $post['topic_id'] || $a['discussion_hash'] !== $post['plus_kb_digest']))
		{
			$fields = array('topic_id' => (int) $post['topic_id'], 'managed_topic' => 1,
				'discussion_hash' => $post['plus_kb_digest'], 'intro_post' => (int) $post['topic_first_post_id']);
			$this->db->sql_query('UPDATE ' . $this->prefix . 'articles SET ' . $this->db->sql_build_array('UPDATE', $fields) . ' WHERE article_id=' . $id);
			$a = array_merge($a, $fields);
		}
		return $a;
	}

	static public function digest($a)
	{
		return hash('sha256', serialize(array($a['title'], $a['description'], (int) $a['cat_id'], (int) $a['type_id'], $a['editor_text'])));
	}

	public function discuss($id)
	{
		if (!(int) $this->articles->setting('comments', 1)) { return; }
		$result = $this->db->sql_query('SELECT * FROM ' . $this->prefix . 'articles WHERE article_id=' . (int) $id . ' AND approval=1');
		$a = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		if (!$a) { return; }
		$a = $this->recover($a);
		$hash = self::digest($a);
		if ($a['discussion_hash'] === $hash) { return; }
		$topic = false;
		if ($a['topic_id'])
		{
			if (!$a['managed_topic'] || !$this->articles->discussion($a['topic_id']) || !($topic = $this->topic($a))) { return; }
			if (!(int) $this->articles->setting('bump_post', 1)) { return; }
		}
		$data = $this->post($a, $topic, $hash);
		if ($data)
		{
			$fields = array('topic_id' => (int) $data['topic_id'], 'managed_topic' => 1, 'discussion_hash' => $hash,
				'intro_post' => $topic ? (int) $a['intro_post'] : (int) $data['post_id']);
			$this->db->sql_query('UPDATE ' . $this->prefix . 'articles SET ' . $this->db->sql_build_array('UPDATE', $fields) . ' WHERE article_id=' . $id);
		}
	}

	public function remove_article($a)
	{
		$where = ' WHERE article_id=' . (int) $a['article_id'];
		// A rating takes this row lock before writing its receipt.
		$this->db->sql_query('UPDATE ' . $this->prefix . 'articles SET article_id=article_id' . $where);
		$this->remove_discussion($a);
		foreach (array('mail','votes','pages','articles') as $table) { $this->db->sql_query('DELETE FROM ' . $this->prefix . $table . $where); }
	}

	private function remove_discussion($a)
	{
		$a = $this->recover($a);
		if (!$a['managed_topic'] || !(int) $this->articles->setting('del_topic', 1) || !($topic = $this->topic($a))) { return; }
		$forum = (int) $topic['forum_id'];
		$own = !empty($this->user->data['is_registered']) && (int) $topic['topic_poster'] === (int) $this->user->data['user_id'] &&
			(int) $topic['topic_first_post_id'] === (int) $topic['topic_last_post_id'] && $this->auth->acl_get('f_delete', $forum);
		if (!$own && !$this->auth->acl_get('m_delete', $forum)) { return; }
		include_once $this->root . 'includes/functions_admin.' . $this->php_ext;
		delete_topics('topic_id', array((int) $topic['topic_id']));
	}
}
