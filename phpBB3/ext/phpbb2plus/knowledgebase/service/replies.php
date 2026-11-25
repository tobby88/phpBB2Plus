<?php
namespace phpbb2plus\knowledgebase\service;

class replies
{
	private $db;
	private $auth;
	private $user;
	private $config;
	private $visibility;
	private $dispatcher;
	private $profiles;

	public function __construct($db, $auth, $user, $config, $visibility, $dispatcher, $profiles)
	{
		$this->db = $db;
		$this->auth = $auth;
		$this->user = $user;
		$this->config = $config;
		$this->visibility = $visibility;
		$this->dispatcher = $dispatcher;
		$this->profiles = $profiles;
	}

	private function relation($topic)
	{
		$forum = (int) $topic['forum_id'];
		$visible = $this->visibility->get_visibility_sql('post', $forum, 'p.');
		$event = $this->dispatcher->trigger_event('phpbb2plus.knowledgebase.reply_visibility', array('forum_id' => $forum, 'visible' => $visible));
		return 'p.topic_id=' . (int) $topic['topic_id'] . ' AND t.forum_id=' . $forum . ' AND p.forum_id=t.forum_id AND t.topic_moved_id=0 AND t.topic_status<>' . ITEM_MOVED
			. ' AND EXISTS (SELECT 1 FROM ' . FORUMS_TABLE . " f WHERE f.forum_id=t.forum_id AND f.forum_password='" . $this->db->sql_escape($topic['forum_password']) . "')"
			. ' AND p.post_id<>t.topic_first_post_id AND ' . $this->visibility->get_visibility_sql('topic', $forum, 't.') . ' AND (' . $event['visible'] . ')';
	}

	public function count($topic)
	{
		$result = $this->db->sql_query('SELECT COUNT(*) AS total FROM ' . POSTS_TABLE . ' p JOIN ' . TOPICS_TABLE . ' t ON t.topic_id=p.topic_id WHERE ' . $this->relation($topic));
		$count = (int) $this->db->sql_fetchfield('total');
		$this->db->sql_freeresult($result);
		return $count;
	}

	private function locations($rows)
	{
		$locations = array();
		if (!$this->config['load_cpf_viewtopic'] || empty($this->user->data['is_registered'])) { return $locations; }
		$users = array_filter(array_unique(array_column($rows, 'user_id')), static function ($id) { return $id > ANONYMOUS; });
		foreach ($this->profiles->grab_profile_fields_data($users) as $id => $fields)
		{
			if (empty($fields['phpbb_location']['data']['field_show_on_vt'])) { continue; }
			$display = $this->profiles->generate_profile_fields_template_data(array('phpbb_location' => $fields['phpbb_location']));
			$locations[$id] = $display['row']['PROFILE_PHPBB_LOCATION_VALUE'] ?? '';
		}
		return $locations;
	}

	private function attachments($topic, $rows)
	{
		$files = array();
		$ids = array_keys(array_filter($rows, static function ($r) { return $r['post_attachment']; }));
		if (!$ids || !$this->config['allow_attachments'] || !$this->auth->acl_get('u_download') || !$this->auth->acl_get('f_download', $topic['forum_id'])) { return $files; }
		$result = $this->db->sql_query('SELECT * FROM ' . ATTACHMENTS_TABLE . ' WHERE is_orphan=0 AND in_message=0 AND topic_id=' . (int) $topic['topic_id']
			. ' AND ' . $this->db->sql_in_set('post_msg_id', $ids) . ' ORDER BY attach_id DESC');
		while ($file = $this->db->sql_fetchrow($result)) { $files[(int) $file['post_msg_id']][] = $file; }
		$this->db->sql_freeresult($result);
		return $files;
	}

	private function edited($row, $author)
	{
		if (!$row['post_edit_count'] || !$this->config['display_last_edited']) { return ''; }
		$editor = $row['editor_id'] > ANONYMOUS ? get_username_string('full', $row['editor_id'], $row['editor_name'], $row['editor_colour']) : $author;
		$text = $this->user->lang('EDITED_TIMES_TOTAL', (int) $row['post_edit_count'], $editor, $this->user->format_date($row['post_edit_time'], false, true));
		$event = $this->dispatcher->trigger_event('phpbb2plus.community.preview_edited', array('row' => $row, 'post_row' => array('EDITED_MESSAGE' => $text)));
		return $event['post_row']['EDITED_MESSAGE'];
	}

	public function page($topic, $limit, $start)
	{
		$from = POSTS_TABLE . ' p JOIN ' . TOPICS_TABLE . ' t ON p.topic_id=t.topic_id LEFT JOIN ' . ZEBRA_TABLE . ' z ON z.zebra_id=p.poster_id AND z.user_id='
			. (int) $this->user->data['user_id'];
		$where = $this->relation($topic);
		$result = $this->db->sql_query_limit("SELECT p.post_id,p.post_visibility,z.foe FROM $from WHERE $where ORDER BY p.post_time,p.post_id", $limit, $start);
		$rows = array_column($this->db->sql_fetchrowset($result), null, 'post_id');
		$this->db->sql_freeresult($result);
		$ids = array_keys(array_filter($rows, static function ($row) { return (int) $row['post_visibility'] !== ITEM_DELETED && !$row['foe']; }));
		$readable = array();
		if ($ids)
		{
			// The page positions include placeholders. Only fetch bodies, subjects and identities for visible replies.
			$result = $this->db->sql_query('SELECT p.post_id,p.post_time,p.post_visibility,p.post_subject,p.post_text,p.post_attachment,
				p.bbcode_uid,p.bbcode_bitfield,p.enable_bbcode,p.enable_smilies,p.enable_magic_url,p.post_edit_user,p.post_edit_time,p.post_edit_count,
				p.poster_id AS user_id,p.post_username,u.username,u.user_colour,e.user_id AS editor_id,e.username AS editor_name,e.user_colour AS editor_colour
				FROM ' . $from . ' LEFT JOIN ' . USERS_TABLE . ' u ON u.user_id=p.poster_id LEFT JOIN ' . USERS_TABLE . ' e ON e.user_id=p.post_edit_user
				WHERE ' . $where . ' AND p.post_visibility<>' . ITEM_DELETED . ' AND COALESCE(z.foe,0)=0 AND ' . $this->db->sql_in_set('p.post_id', $ids));
			$readable = array_column($this->db->sql_fetchrowset($result), null, 'post_id');
			$this->db->sql_freeresult($result);
		}
		$authors = $this->dispatcher->trigger_event('phpbb2plus.community.preview_bylines', array('rowset' => $readable, 'post_list' => array_keys($readable)));
		$locations = $this->locations($readable);
		$files = $this->attachments($topic, $readable);
		foreach ($rows as $id => $row)
		{
			if ((int) $row['post_visibility'] === ITEM_DELETED) { yield array('ID' => $id, 'REMOVED' => true); continue; }
			if ($row['foe']) { yield array('ID' => $id, 'IGNORED' => true); continue; }
			if (!isset($authors['rowset'][$id])) { continue; }
			$row = $authors['rowset'][$id];
			$author = get_username_string('full', $row['username'] === null ? ANONYMOUS : (int) $row['user_id'], $row['username'], $row['user_colour'], $row['post_username']);
			$options = ($row['enable_bbcode'] ? OPTION_FLAG_BBCODE : 0) | ($row['enable_smilies'] ? OPTION_FLAG_SMILIES : 0) | ($row['enable_magic_url'] ? OPTION_FLAG_LINKS : 0);
			$body = generate_text_for_display($row['post_text'], $row['bbcode_uid'], $row['bbcode_bitfield'], $options);
			$attached = $files[$id] ?? array();
			if ($attached) { $counts = array(); parse_attachments((int) $topic['forum_id'], $body, $attached, $counts); }
			yield array('ID' => $id, 'SUBJECT' => censor_text($row['post_subject']), 'BODY' => $body, 'AUTHOR' => $author, 'ATTACHMENTS' => $attached,
				'LOCATION' => $locations[$row['user_id']] ?? '', 'DATE' => $this->user->format_date($row['post_time']), 'EDITED' => $this->edited($row, $author),
				'PENDING' => (int) $row['post_visibility'] !== ITEM_APPROVED);
		}
	}
}
