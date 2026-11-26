<?php
namespace phpbb2plus\community\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class topic_readers implements EventSubscriberInterface
{
	private $db;
	private $table;
	private $auth;
	private $config;
	private $request;
	private $user;
	private $language;
	private $helper;
	private $template;

	public function __construct($db, $prefix, $auth, $config, $request, $user, $language, $helper, $template)
	{
		$this->db = $db;
		$this->table = $prefix . 'plus_topic_readers';
		$this->auth = $auth;
		$this->config = $config;
		$this->request = $request;
		$this->user = $user;
		$this->language = $language;
		$this->helper = $helper;
		$this->template = $template;
	}

	public static function getSubscribedEvents()
	{
		return array('core.viewtopic_modify_page_title' => 'opened', 'core.acp_board_config_edit_add' => 'settings',
			'core.delete_user_before' => 'remove_users', 'core.delete_topics_before_query' => 'remove_content',
			'core.delete_forum_content_before_query' => 'remove_content', 'core.move_posts_after' => 'move_readers');
	}

	public function settings($event)
	{
		if ($event['mode'] !== 'features') { return; }
		$this->language->add_lang('topic_readers', 'phpbb2plus/community');
		$display = $event['display_vars'];
		$field = array('plus_topic_readers' => array('lang' => 'PLUS_READERS_ENABLE', 'validate' => 'bool', 'type' => 'radio:yes_no', 'explain' => true));
		$display['vars'] = phpbb_insert_config_array($display['vars'], $field, array('after' => 'allow_bookmarks'));
		$event['display_vars'] = $display;
	}

	public function opened($event)
	{
		$topic = $event['topic_data'];
		$forum = (int) $event['forum_id'];
		if (empty($this->config['plus_topic_readers']) || (int) $topic['topic_visibility'] !== ITEM_APPROVED ||
			!empty($topic['topic_moved_id']) || !$this->auth->acl_get('f_list', $forum) || !$this->auth->acl_get('f_read', $forum)) { return; }

		$id = (int) $topic['topic_id'];
		if ($this->user->data['is_registered'] && $this->auth->acl_gets('u_viewprofile', 'a_user', 'a_useradd', 'a_userdel'))
		{
			$this->language->add_lang('topic_readers', 'phpbb2plus/community');
			$this->template->assign_var('U_PLUS_TOPIC_READERS', $this->helper->route('phpbb2plus_community_topic_readers', array('topic_id' => $id)));
		}
		if ($this->request->server('REQUEST_METHOD') !== 'GET' || $this->request->variable('view', '') === 'print' || $this->user->data['is_bot']) { return; }

		$actor = (int) $this->user->data['user_id'];
		$stamp = time();
		// The native event runs after the password and post-visibility checks. Recheck the live session and its
		// visibility in the write itself. All anonymous views share a single count without session or IP data.
		$this->db->sql_query("INSERT INTO {$this->table} (topic_id,user_id,forum_id,last_read,visit_count)
			SELECT t.topic_id,u.user_id,t.forum_id,$stamp,1 FROM " . TOPICS_TABLE . ' t JOIN ' . USERS_TABLE . " u ON u.user_id=$actor
			JOIN " . SESSIONS_TABLE . " s ON s.session_user_id=u.user_id AND s.session_id='" . $this->db->sql_escape($this->user->session_id) . "'
			WHERE t.topic_id=$id AND t.forum_id=$forum AND t.topic_moved_id=0 AND t.topic_visibility=" . ITEM_APPROVED . '
			AND (u.user_id=' . ANONYMOUS . ' OR (u.user_type IN (' . USER_NORMAL . ',' . USER_FOUNDER . ') AND u.user_allow_viewonline=1 AND s.session_viewonline=1))
			AND EXISTS (SELECT 1 FROM ' . POSTS_TABLE . ' p WHERE p.topic_id=t.topic_id AND p.post_visibility=' . ITEM_APPROVED . ")
			ON DUPLICATE KEY UPDATE last_read=GREATEST(last_read,$stamp), visit_count=LEAST(2147483647,visit_count+1)");
	}

	public function remove_users($event)
	{
		$ids = array_filter(array_map('intval', array_keys($event['user_rows'])), static function ($id) { return $id > ANONYMOUS; });
		if (!$ids) { return; }
		$where = $this->db->sql_in_set('user_id', $ids);
		// The native account-removal transaction also owns this deletion.
		$result = $this->db->sql_query('SELECT user_id FROM ' . USERS_TABLE . " WHERE $where ORDER BY user_id FOR UPDATE");
		$this->db->sql_freeresult($result);
		$this->db->sql_query("DELETE FROM {$this->table} WHERE $where");
	}

	public function remove_content($event)
	{
		if (isset($event['forum_id']))
		{
			// Native bulk forum deletion bypasses delete_topics(). Its topic_ids can contain
			// only topics with attachments. Remove all visits to the actual topics being erased.
			$this->db->sql_query("DELETE r FROM {$this->table} r JOIN " . TOPICS_TABLE .
				' t ON t.topic_id=r.topic_id WHERE t.forum_id=' . (int) $event['forum_id']);
		}
		$event['table_ary'] = array_merge($event['table_ary'], array($this->table));
	}

	public function move_readers($event)
	{
		$destination = (int) $event['topic_id'];
		$sources = array_diff(array_unique(array_map('intval', $event['topic_ids'])), array($destination));
		if (!$sources) { return; }
		$this->db->sql_transaction('begin');
		try
		{
			// Only a complete merge moves readership, and its original forum remains part of each record.
			$sql = 'SELECT t.topic_id FROM ' . TOPICS_TABLE . ' t JOIN ' . TOPICS_TABLE . " d ON d.topic_id=$destination
				WHERE " . $this->db->sql_in_set('t.topic_id', $sources) . ' AND NOT EXISTS (SELECT 1 FROM ' . POSTS_TABLE . ' p WHERE p.topic_id=t.topic_id)
				ORDER BY t.topic_id FOR UPDATE';
			$result = $this->db->sql_query($sql);
			$empty = array_map('intval', array_column($this->db->sql_fetchrowset($result), 'topic_id'));
			$this->db->sql_freeresult($result);
			if ($empty)
			{
				$table = $this->table;
				$where = $this->db->sql_in_set('topic_id', $empty);
				$this->db->sql_query("INSERT INTO $table (topic_id,user_id,forum_id,last_read,visit_count)
					SELECT $destination,user_id,forum_id,MAX(last_read),LEAST(2147483647,SUM(visit_count)) FROM $table src WHERE $where GROUP BY user_id,forum_id
					ON DUPLICATE KEY UPDATE last_read=GREATEST($table.last_read,VALUES(last_read)),
					visit_count=LEAST(2147483647,$table.visit_count+VALUES(visit_count))");
				$this->db->sql_query("DELETE FROM $table WHERE $where");
			}
			$this->db->sql_transaction('commit');
		}
		catch (\Throwable $error)
		{
			$this->db->sql_transaction('rollback');
			throw $error;
		}
	}
}
