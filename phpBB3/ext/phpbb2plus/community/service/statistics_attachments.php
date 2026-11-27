<?php
namespace phpbb2plus\community\service;

class statistics_attachments
{
	private $db;
	private $cache;
	private $auth;
	private $config;
	private $user;
	private $root;
	private $ext;

	public function __construct($db, $cache, $auth, $config, $user, $root, $ext)
	{
		$this->db = $db;
		$this->cache = $cache;
		$this->auth = $auth;
		$this->config = $config;
		$this->user = $user;
		$this->root = $root;
		$this->ext = $ext;
	}

	private function extension_filter($forums)
	{
		// Generate one condition per distinct native list of allowed forums.
		$extensions = $this->cache->obtain_attach_extensions(true);
		$groups = $conditions = array();
		foreach ($extensions['_allowed_post'] ?? array() as $extension => $allowed)
		{
			$ids = is_array($allowed) ? array_intersect($forums, array_map('intval', $allowed)) : $forums;
			if (!$ids) { continue; }
			$key = implode(',', $ids);
			$groups[$key]['forums'] = $ids;
			$groups[$key]['extensions'][] = (string) $extension;
		}
		foreach ($groups as $group)
		{
			$conditions[] = '(' . $this->db->sql_in_set('a.extension', $group['extensions']) . ' AND ' .
				$this->db->sql_in_set('p.forum_id', $group['forums']) . ')';
		}
		return implode(' OR ', $conditions);
	}

	private function downloadable_forums($forums)
	{
		if (!$forums || !$this->config['allow_attachments'] || !$this->auth->acl_get('u_download')) { return array(); }
		return array_intersect(array_unique($forums), array_keys($this->auth->acl_getf('f_download', true)));
	}

	public function topic_markers(array $rows, $visibility)
	{
		$topics = array_column(array_filter($rows, static function ($topic) { return $topic['topic_attachment']; }), 'forum_id', 'topic_id');
		$forums = $this->downloadable_forums($topics);
		if (!$forums || ($extensions = $this->extension_filter($forums)) === '') { return array(); }
		// A topic flag can refer to an orphan, an unapproved post or a file type no longer allowed here.
		// Reduce duplicates on MariaDB/MySQL while preserving the exact spelling checked by the download handler.
		$projection = $this->db->get_sql_layer() === 'mysqli' ? 'DISTINCT p.topic_id,p.forum_id,BINARY a.extension AS extension' :
			'p.topic_id,p.forum_id,a.extension';
		$result = $this->db->sql_query('SELECT ' . $projection . ' FROM ' . ATTACHMENTS_TABLE . ' a
			JOIN ' . POSTS_TABLE . ' p ON p.post_id=a.post_msg_id AND p.topic_id=a.topic_id
			WHERE a.in_message=0 AND a.is_orphan=0 AND p.plus_legacy_erased=0 AND p.post_visibility<>' . ITEM_DELETED .
			' AND ' . $this->db->sql_in_set('a.topic_id', array_keys($topics)) . ' AND (' . $extensions . ')
			AND (' . $visibility->get_forums_visibility_sql('post', $forums, 'p.') . ')');
		$found = $native = array();
		while ($row = $this->db->sql_fetchrow($result))
		{
			$id = (int) $row['topic_id']; $forum = (int) $row['forum_id'];
			if (isset($found[$id]) || (int) $topics[$id] !== $forum) { continue; }
			if (!isset($native[$forum])) { $native[$forum] = $this->cache->obtain_attach_extensions($forum); }
			if (extension_allowed($forum, $row['extension'], $native[$forum])) { $found[$id] = true; }
		}
		$this->db->sql_freeresult($result);
		return $found;
	}

	public function ranking($forums, $scope, $limit)
	{
		$forums = $this->downloadable_forums($forums);
		if (!$forums) { return array(); }
		$extensions = $this->extension_filter($forums);
		if ($extensions === '') { return array(); }
		$query = 'SELECT a.attach_id,a.extension,a.real_filename,a.attach_comment,a.filesize,a.filetime,a.download_count,p.post_id,p.forum_id,t.topic_title
			FROM ' . ATTACHMENTS_TABLE . ' a JOIN ' . POSTS_TABLE . ' p ON p.post_id=a.post_msg_id AND p.topic_id=a.topic_id
			JOIN ' . TOPICS_TABLE . ' t ON t.topic_id=p.topic_id AND t.forum_id=p.forum_id
			WHERE a.in_message=0 AND a.is_orphan=0 AND ' . $scope . ' AND (' . $extensions . ')';
		$rows = $native = array();
		$after = '';
		$batch = max(25, $limit);
		do
		{
			$count = 0;
			$result = $this->db->sql_query_limit($query . $after . ' ORDER BY a.download_count DESC,a.attach_id', $batch);
			while (count($rows) < $limit && ($row = $this->db->sql_fetchrow($result)))
			{
				++$count;
				$id = (int) $row['attach_id'];
				$downloads = (int) $row['download_count'];
				$after = " AND (a.download_count<$downloads OR (a.download_count=$downloads AND a.attach_id>$id))";
				$forum = (int) $row['forum_id'];
				if (!isset($native[$forum])) { $native[$forum] = $this->cache->obtain_attach_extensions($forum); }
				// A case-insensitive database can match a spelling which the download handler rejects.
				if (!extension_allowed($forum, $row['extension'], $native[$forum])) { continue; }
				$post = (int) $row['post_id'];
				$rows[] = array('LABEL' => htmlspecialchars_decode($row['real_filename'], ENT_QUOTES), 'COUNT' => $downloads,
					'COMMENT' => htmlspecialchars_decode(censor_text($row['attach_comment']), ENT_QUOTES), 'SIZE' => get_formatted_filesize($row['filesize']),
					'DATE' => $this->user->format_date($row['filetime']), 'TOPIC' => htmlspecialchars_decode(censor_text($row['topic_title']), ENT_QUOTES),
					'U_POST' => append_sid($this->root . 'viewtopic.' . $this->ext, 'p=' . $post, false) . '#p' . $post,
					'URL' => append_sid($this->root . 'download/file.' . $this->ext, 'id=' . $id, false));
			}
			$this->db->sql_freeresult($result);
		} while (count($rows) < $limit && $count === $batch);
		return $rows;
	}
}
