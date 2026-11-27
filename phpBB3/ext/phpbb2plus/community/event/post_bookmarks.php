<?php
namespace phpbb2plus\community\event;

use phpbb\request\request_interface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class post_bookmarks implements EventSubscriberInterface
{
	private $db, $config, $auth, $user, $request, $visibility, $language, $template;

	public function __construct($db, $config, $auth, $user, $request, $visibility, $language, $template)
	{
		$this->db = $db; $this->config = $config; $this->auth = $auth; $this->user = $user;
		$this->request = $request; $this->visibility = $visibility; $this->language = $language; $this->template = $template;
	}

	public static function getSubscribedEvents()
	{
		return array('core.posting_modify_template_vars' => 'editor', 'core.posting_modify_submit_post_after' => 'posted',
			'core.ucp_prefs_post_data' => 'preference', 'core.ucp_prefs_post_update_data' => 'save_preference',
			'core.acp_users_prefs_modify_data' => 'preference', 'core.acp_users_prefs_modify_sql' => 'save_preference',
			'core.ucp_register_data_before' => 'registration', 'core.ucp_register_user_row_after' => 'save_registration');
	}

	private function enabled()
	{
		return $this->config['allow_bookmarks'] && isset($this->user->data['plus_post_bookmark']);
	}

	private function show_preference($default)
	{
		if (!$this->enabled()) { return; }
		$this->language->add_lang('post_bookmarks', 'phpbb2plus/community');
		$this->template->assign_vars(array('S_PLUS_BOOKMARK_DEFAULT' => true,
			'PLUS_BOOKMARK_DEFAULT' => $this->request->variable('plus_bookmark_default', (int) $default, false, request_interface::POST) === 1));
	}

	public function preference($event)
	{
		$account = $event->offsetExists('user_row') ? $event['user_row'] : $this->user->data;
		$this->show_preference($account['plus_post_bookmark'] ?? 0);
	}

	public function registration() { $this->show_preference(0); }

	private function preference_row($event, $key)
	{
		if (!$this->enabled()) { return; }
		$value = $this->request->raw_variable('plus_bookmark_default', null, request_interface::POST);
		if ($value !== '0' && $value !== '1') { return; }
		$row = $event[$key];
		$row['plus_post_bookmark'] = (int) $value;
		$event[$key] = $row;
	}

	public function save_preference($event) { $this->preference_row($event, 'sql_ary'); }
	public function save_registration($event) { $this->preference_row($event, 'user_row'); }

	private function available($mode, $forum)
	{
		return $this->enabled() && !empty($this->user->data['is_registered']) && empty($this->user->data['is_bot']) &&
			in_array($mode, array('post', 'reply', 'quote'), true) && $forum > 0 &&
			$this->auth->acl_get('f_list', $forum) && $this->auth->acl_get('f_read', $forum);
	}

	public function editor($event)
	{
		if (!$this->available($event['mode'], (int) $event['forum_id'])) { return; }
		$this->language->add_lang('post_bookmarks', 'phpbb2plus/community');
		$data = $event['page_data'];
		$data['S_PLUS_POST_BOOKMARK'] = true;
		$data['PLUS_POST_BOOKMARK'] = $this->request->is_set_post('plus_bookmark_choice')
			? $this->request->variable('setbm', 0, false, request_interface::POST) === 1
			: !empty($this->user->data['plus_post_bookmark']);
		$event['page_data'] = $data;
	}

	public function posted($event)
	{
		$data = $event['data'];
		$forum = (int) $data['forum_id'];
		$topic = (int) $data['topic_id'];
		if (!$this->available($event['mode'], $forum) || !$topic || !$this->request->is_set_post('plus_bookmark_choice') ||
			$this->request->variable('setbm', 0, false, request_interface::POST) !== 1) { return; }
		$user = (int) $this->user->data['user_id'];
		$visible = $this->visibility->get_visibility_sql('topic', $forum, 't.');
		// Only the ordinary native composer reaches this event after publication. An unchecked box leaves existing bookmarks alone.
		// The unique (topic_id,user_id) key also covers two replies written concurrently.
		$this->db->sql_return_on_error(true);
		try
		{
			$ok = $this->db->sql_query('INSERT INTO ' . BOOKMARKS_TABLE . ' (topic_id,user_id) SELECT t.topic_id,u.user_id FROM ' .
				TOPICS_TABLE . ' t JOIN ' . USERS_TABLE . ' u ON u.user_id=' . $user . ' JOIN ' . CONFIG_TABLE . " c ON c.config_name='allow_bookmarks' AND c.config_value='1'
				WHERE t.topic_id=$topic AND t.forum_id=$forum AND u.user_type IN (" . USER_NORMAL . ',' . USER_FOUNDER . ") AND ($visible)
				ON DUPLICATE KEY UPDATE user_id=$user");
			if (!$ok) { error_log('Unable to bookmark the published phpBB topic.'); }
		}
		catch (\Throwable $error) { error_log('Unable to bookmark the published phpBB topic.'); }
		finally { $this->db->sql_return_on_error(false); }
	}
}
