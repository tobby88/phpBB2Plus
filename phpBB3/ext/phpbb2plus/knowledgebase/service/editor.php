<?php
namespace phpbb2plus\knowledgebase\service;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class editor
{
	private $articles;
	private $db;
	private $config;
	private $user;
	private $auth;
	private $prefix;
	private $root;
	private $php_ext;
	private $publication;

	public function __construct($articles, $db, $config, $user, $auth, $prefix, $root, $php_ext, $publication)
	{
		$this->articles = $articles;
		$this->db = $db;
		$this->config = $config;
		$this->user = $user;
		$this->auth = $auth;
		$this->prefix = $prefix;
		$this->root = $root;
		$this->php_ext = $php_ext;
		$this->publication = $publication;
	}

	public function allowed($action, $a = null)
	{
		if (!empty($this->user->data['is_bot'])) { return false; }
		if ($action === 'new') { return $this->articles->moderator() || ((int) $this->articles->setting('allow_new', 1) &&
			(!empty($this->user->data['is_registered']) || (int) $this->articles->setting('allow_anon', 0))); }
		if (!$a) { return false; }
		if ($action === 'edit') { return $this->articles->moderator() || (!empty($this->user->data['is_registered']) &&
			(int) $this->articles->setting('allow_edit', 1) && (int) $a['author_id'] === (int) $this->user->data['user_id']); }
		if (!$this->articles->reviewer()) { return false; }
		return $action === 'delete' || ($action === 'approve' && (int) $a['approval'] !== 1) || ($action === 'unapprove' && (int) $a['approval'] === 1);
	}

	public function context($action, $id)
	{
		$a = $id ? $this->articles->find($id) : null;
		if (!$this->allowed($action, $a)) { throw new NotFoundHttpException(); }
		return $a;
	}

	public function editor_text($article)
	{
		$body = $article['editor_text'] ?? '';
		if (stripos($body, '[acronym:') === false) { return $body; }
		// Early previews retained the source UID in the editable article, but their
		// native page XML had already removed it. Only offer a correction when both agree.
		$pages = explode('[page]', $body);
		$sql = 'SELECT page_number,content FROM ' . $this->prefix . 'pages WHERE article_id=' . (int) $article['article_id'];
		$result = $this->db->sql_query($sql);
		while ($row = $this->db->sql_fetchrow($result))
		{
			$i = (int) $row['page_number'] - 1;
			if (!isset($pages[$i]) || stripos($pages[$i], '[acronym:') === false) { continue; }
			$clean = preg_replace('~\[code(?:[ =][^\]]*)?\].*?\[/code\](*SKIP)(*F)|(\[acronym):[a-z0-9]{1,10}(?==")~is', '$1', $pages[$i]);
			if ($clean !== $pages[$i] && str_replace('[toc]', '', $clean) === \s9e\TextFormatter\Unparser::unparse($row['content'])) { $pages[$i] = $clean; }
		}
		$this->db->sql_freeresult($result);
		return implode('[page]', $pages);
	}

	public static function form_revision($article)
	{
		// Moving an article between categories or types invalidates an open editor,
		// while the content revision used by the notification queue stays current.
		return $article ? (int) $article['revision'] . ':' . (int) $article['cat_id'] . ':' . (int) $article['type_id'] : '';
	}

	public function prepare($form)
	{
		if ($form['title'] === '' || utf8_strlen($form['title']) > 255 || utf8_strlen($form['description']) > 255 ||
			$form['body'] === '' || strlen($form['body']) > 262140 || utf8_strlen($form['body']) > 65535) { throw new \DomainException('PLUS_KB_INVALID_TEXT'); }
		$pages = array();
		foreach (explode('[page]', $form['body']) as $part)
		{
			$pieces = explode('[toc]', $part, 2);
			$heading = count($pieces) === 2 ? trim(preg_replace('/\[\/?[a-z][a-z0-9]*(?:[=:\s][^\]\r\n]*)?\]/i', '', $pieces[0])) : '';
			$text = utf8_htmlspecialchars(str_replace('[toc]', '', $part));
			$uid = $bits = ''; $flags = 0;
			generate_text_for_storage($text, $uid, $bits, $flags, (bool) $this->config['allow_bbcode'], (bool) $this->config['allow_post_links'], (bool) $this->config['allow_smilies']);
			$pages[] = array('heading' => utf8_substr($heading, 0, 255), 'content' => $text, 'bbcode_uid' => $uid, 'bbcode_bitfield' => $bits, 'bbcode_options' => $flags);
		}
		return $pages;
	}

	public function locked(callable $operation, $publish = false)
	{
		$lock = new \phpbb\lock\db('plus_kb_editor_lock', $this->config, $this->db);
		if (!$lock->acquire()) { throw new \DomainException('PLUS_KB_CONFLICT'); }
		try
		{
			$this->db->sql_transaction('begin');
			$table = $this->prefix . 'state';
			$this->db->sql_query("UPDATE $table SET state_id=1 WHERE state_id=1");
			$result = $this->db->sql_query("SELECT state_id FROM $table WHERE state_id=1");
			$exists = $this->db->sql_fetchfield('state_id') !== false;
			$this->db->sql_freeresult($result);
			if (!$exists) { throw new \RuntimeException('Knowledge Base coordinator missing.'); }
			$this->articles->refresh();
			$out = $operation();
			if (!$this->db->sql_transaction('commit')) { throw new \RuntimeException('Article transaction did not commit.'); }
			// Native posting commits before it indexes and updates read tracking. Keep
			// its complete workflow outside the article transaction, under the same writer lock.
			if ($publish) { $this->publication->discuss($out); }
			return $out;
		}
		catch (\Throwable $e) { if ($this->db->get_transaction()) { $this->db->sql_transaction('rollback'); } throw $e; }
		finally { $lock->release(); }
	}

	public function save($mode, $id, $form, $nonce, $revision)
	{
		$this->context($mode, $id);
		$pages = $this->prepare($form);
		if (!preg_match('/^[a-f0-9]{32}$/D', $nonce)) { throw new \DomainException('FORM_INVALID'); }
		$key = hash('sha256', $this->user->session_id . ':' . (int) $this->user->data['user_id'] . ':' . $mode . ':' . $id . ':' . $nonce);
		$digest = hash('sha256', serialize(array($form, $revision)));
		return $this->locked(function () use ($id, $mode, $key, $digest, $form, $pages, $revision)
		{
			$a = $this->context($mode, $id);
			$table = $this->prefix . 'articles';
			$result = $this->db->sql_query('SELECT payload,article_id FROM ' . $this->prefix . "submissions WHERE form_key='$key'");
			$saved = $this->db->sql_fetchrow($result);
			$this->db->sql_freeresult($result);
			if ($saved)
			{
				if (!hash_equals($saved['payload'], $digest)) { throw new \DomainException('FORM_INVALID'); }
				return (int) $saved['article_id'];
			}
			if (self::form_revision($a) !== $revision) { throw new \DomainException('PLUS_KB_CONFLICT'); }
			$keep_untyped = $a && (int) $a['type_id'] === 0 && $form['type'] === 0;
			if (!$form['category'] || !$this->articles->trail($form['category']) || (!$keep_untyped && !isset($this->articles->types()[$form['type']])))
			{ throw new \DomainException('PLUS_KB_INVALID_CATEGORY'); }
			$forum = array('m_edit','m_delete','m_lock','m_approve','m_move','m_split','m_merge');
			$can_publish = $this->articles->moderator() || (!$a && !empty($this->user->data['is_registered']) &&
				($this->auth->acl_gets($forum) || $this->auth->acl_getf_global($forum)));
			$approval = $can_publish || !(int) $this->articles->setting($a ? 'approve_edit' : 'approve_new', 1) ? 1 : ($a ? 2 : 0);
			$row = array('cat_id' => $form['category'], 'type_id' => $form['type'], 'title' => $form['title'], 'description' => $form['description'],
				'created_at' => time(), 'editor_text' => $form['body'], 'search_text' => preg_replace('/\[\/?[a-z][a-z0-9]*(?:[=:\s][^\]\r\n]*)?\]/i', ' ', $form['body']),
				'approval' => $approval, 'revision' => $a ? (int) $a['revision'] + 1 : 1);
			if ($a) { $this->db->sql_query("UPDATE $table SET " . $this->db->sql_build_array('UPDATE', $row) . ' WHERE article_id=' . (int) $id); }
			else
			{
				$name = empty($this->user->data['is_registered']) ? $form['guest'] : '';
				if ($name !== '')
				{
					if (!function_exists('validate_username')) { include_once $this->root . 'includes/functions_user.' . $this->php_ext; }
					if (utf8_strlen($name) > 32 || validate_username(utf8_htmlspecialchars($name)) !== false) { throw new \DomainException('PLUS_KB_BAD_NAME'); }
				}
				$row += array('author_id' => (int) $this->user->data['user_id'], 'author_name' => $name, 'source_date' => '');
				$this->db->sql_query("INSERT INTO $table " . $this->db->sql_build_array('INSERT', $row));
				$id = (int) $this->db->sql_nextid();
			}
			$this->db->sql_query('DELETE FROM ' . $this->prefix . 'pages WHERE article_id=' . $id);
			foreach ($pages as $i => $page)
			{
				$this->db->sql_query('INSERT INTO ' . $this->prefix . 'pages ' . $this->db->sql_build_array('INSERT', $page + array('article_id' => $id, 'page_number' => $i + 1)));
			}
			$this->db->sql_query('INSERT INTO ' . $this->prefix . 'submissions ' . $this->db->sql_build_array('INSERT',
				array('form_key' => $key, 'payload' => $digest, 'article_id' => $id, 'created_at' => time())));
			if ($approval !== 1) { $this->publication->pending($row + array('article_id' => $id)); }
			return $id;
		}, true);
	}

	public function moderate($mode, $id, $expected)
	{
		$this->locked(function () use ($mode, $id, $expected)
		{
			$where = ' WHERE article_id=' . (int) $id;
			$a = $this->context($mode, $id);
			if (self::form_revision($a) !== $expected) { throw new \DomainException('PLUS_KB_CONFLICT'); }
			if ($mode === 'delete')
			{
				$this->publication->remove_article($a);
			}
			else
			{
				$this->db->sql_query('UPDATE ' . $this->prefix . 'articles SET revision=revision+1,approval=' . ($mode === 'approve' ? 1 : 0) . $where);
			}
			return (int) $id;
		}, $mode === 'approve');
	}
}
