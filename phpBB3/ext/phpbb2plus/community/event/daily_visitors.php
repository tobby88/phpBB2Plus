<?php
namespace phpbb2plus\community\event;

use phpbb\request\request_interface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class daily_visitors implements EventSubscriberInterface
{
	private $visitors;
	private $config;
	private $user;
	private $auth;
	private $request;
	private $template;
	private $language;
	private $board_activity = null;
	private $category_activity = false;

	public function __construct($visitors, $config, $user, $auth, $request, $template, $language)
	{
		$this->visitors = $visitors; $this->config = $config; $this->user = $user; $this->auth = $auth;
		$this->request = $request; $this->template = $template; $this->language = $language;
	}
	public static function getSubscribedEvents()
	{
		return array('core.session_create_after' => 'created', 'core.update_session_after' => 'updated',
			'core.garbage_collection' => 'cleanup', 'core.index_modify_page_title' => 'index', 'core.viewforum_modify_page_title' => 'category',
			'core.page_header' => 'online_scope', 'core.page_header_after' => 'category_online',
			'core.index_modify_birthdays_sql' => array('index_birthday_scope', 10),
			'phpbb2plus.community.portal_content' => 'portal', 'core.acp_board_config_edit_add' => 'settings',
			'core.ucp_prefs_view_data' => 'member_form', 'core.ucp_prefs_view_update_data' => 'member_save');
	}
	public function cleanup() { $this->visitors->cleanup(); }
	public function created($event)
	{
		$session = $event['session_data'];
		$id = (int) $session['session_user_id'];
		if ($id !== ANONYMOUS && !in_array((int) $this->user->data['user_type'], array(USER_NORMAL, USER_FOUNDER), true)) { return; }
		$this->visitors->remember($id, empty($session['session_viewonline']) || empty($this->user->data['user_allow_viewonline']),
			$session['session_ip'], (int) $session['session_time']);
	}
	public function updated($event)
	{
		$session = $event['session_data'];
		$account = $this->user->data;
		if (!isset($session['session_time'], $account['user_id']) || $event['session_id'] !== $this->user->session_id ||
			((int) $account['user_id'] !== ANONYMOUS && empty($account['is_registered']))) { return; }
		$this->visitors->remember((int) $account['user_id'], empty($account['session_viewonline']) || empty($account['user_allow_viewonline']),
			$account['session_ip'] ?? $this->user->ip, (int) $session['session_time']);
	}
	private function scope()
	{
		$member = !empty($this->user->data['is_registered']) ? (int) ($this->user->data['plus_daily_scope'] ?? -1) : -1;
		$scope = empty($this->config['plus_daily_force']) && $member >= 0 ? $member : (int) ($this->config['plus_daily_scope'] ?? 0);
		return in_array($scope, array(1, 2), true) ? $scope : 0;
	}
	public function index_birthday_scope($event)
	{
		if (!isset($this->config['plus_daily_scope'])) { return; }
		$visible = $this->scope() > 0;
		$this->template->assign_var('S_PLUS_BOARD_ACTIVITY', $visible);
		if (!$visible) { $sql = $event['sql_ary']; $sql['WHERE'] = '1=0'; $event['sql_ary'] = $sql; }
	}
	public function index()
	{
		if (!isset($this->config['plus_daily_scope'])) { return; }
		$this->board_activity = $this->scope() > 0;
		$this->template->assign_var('S_PLUS_BOARD_ACTIVITY', $this->board_activity);
		if (!$this->board_activity)
		{
			$this->template->assign_vars(array('NEWEST_USER' => '', 'S_DISPLAY_BIRTHDAY_LIST' => false));
			return;
		}
		$this->display(false);
	}
	public function category($event)
	{
		if (!isset($this->config['plus_daily_scope']) || (int) $event['forum_data']['forum_type'] !== FORUM_CAT) { return; }
		$this->board_activity = $this->scope() === 2;
		if (!$this->board_activity) { return; }
		$this->category_activity = true;
		$this->template->assign_vars(array('S_PLUS_BOARD_ACTIVITY' => true, 'S_PLUS_CATEGORY_STATS' => true,
			'PLUS_BOARD_POSTS' => $this->language->lang('TOTAL_POSTS_COUNT', (int) $this->config['num_posts']),
			'PLUS_BOARD_TOPICS' => $this->language->lang('TOTAL_TOPICS', (int) $this->config['num_topics']),
			'PLUS_BOARD_MEMBERS' => $this->language->lang('TOTAL_USERS', (int) $this->config['num_users']),
			'PLUS_BOARD_NEWEST' => $this->language->lang('NEWEST_USER', get_username_string('full', $this->config['newest_user_id'],
				$this->config['newest_username'], $this->config['newest_user_colour']))));
		$this->display(false);
	}
	public function online_scope($event)
	{
		if ($this->board_activity === false) { $event['display_online_list'] = false; }
		elseif ($this->category_activity) { $event['item_id'] = 0; } // A Plus category shows the whole board.
	}
	public function category_online()
	{
		if (!$this->category_activity) { return; }
		$this->template->assign_vars(array('S_PLUS_CATEGORY_ONLINE' => (bool) $this->template->retrieve_var('S_DISPLAY_ONLINE_LIST'),
			'S_DISPLAY_ONLINE_LIST' => false));
	}
	public function portal() { $this->display(true); }
	private function display($portal)
	{
		if (!$portal && !empty($this->config['plus_registration_counts']))
		{
			$this->language->add_lang('daily_visitors', 'phpbb2plus/community');
			$counts = $this->visitors->registrations(time(), $this->user->timezone);
			$this->template->assign_vars(array(
				'S_PLUS_REGISTRATION_COUNTS' => true,
				'PLUS_REGISTERED_TODAY' => $counts['today'],
				'PLUS_REGISTERED_YESTERDAY' => $counts['yesterday'],
			));
		}
		$mode = $this->visitors->mode();
		if (!$mode || !$this->scope()) { return; }
		$this->language->add_lang('daily_visitors', 'phpbb2plus/community');
		$names = !$portal && $this->auth->acl_gets('u_viewprofile', 'a_user', 'a_useradd', 'a_userdel');
		$hidden = !empty($this->user->data['is_registered']) && $this->auth->acl_get('a_') && $this->auth->acl_get('u_viewonline');
		$counts = $this->visitors->read(time(), $names, $hidden);
		$this->template->assign_vars(array('S_PLUS_DAILY' => true, 'S_PLUS_DAILY_DETAIL' => $mode === 2,
			'PLUS_DAILY_TOTAL' => $this->language->lang($mode === 2 ? 'PLUS_DAILY_TOTAL' : 'PLUS_DAILY_MEMBERS', $counts['visible'] + $counts['hidden'] + $counts['guests']),
			'PLUS_DAILY_BREAKDOWN' => $this->language->lang('PLUS_DAILY_BREAKDOWN', $counts['visible'], $counts['hidden'], $counts['guests']),
			'PLUS_DAILY_RECENT' => $this->language->lang('PLUS_DAILY_RECENT', $counts['recent'])));
		foreach ($counts['names'] as $member)
		{
			$this->template->assign_block_vars('plus_day_members', array('HIDDEN' => (bool) $member['hidden'],
				'NAME' => get_username_string('full', $member['user_id'], $member['username'], $member['user_colour'])));
		}
	}

	public function settings($event)
	{
		if ($event['mode'] !== 'load') { return; }
		$this->language->add_lang('daily_visitors', 'phpbb2plus/community');
		$display = $event['display_vars'];
		$fields = array();
		foreach (array('plus_daily_mode' => array('DISABLED', 'PLUS_DAILY_MEMBERS_ONLY', 'PLUS_DAILY_WITH_GUESTS'),
			'plus_daily_scope' => array('DISABLED', 'PLUS_DAILY_INDEX', 'PLUS_DAILY_CATEGORIES')) as $key => $options)
		{
			$fields[$key] = array('lang' => strtoupper($key), 'validate' => 'int:0:2', 'type' => 'select',
				'function' => 'build_select', 'params' => array($options, '{CONFIG_VALUE}'), 'explain' => true);
		}
		$fields['plus_daily_force'] = array('lang' => 'PLUS_DAILY_FORCE', 'validate' => 'bool', 'type' => 'radio:yes_no', 'explain' => false);
		if (isset($this->config['plus_registration_counts']))
		{
			$fields['plus_registration_counts'] = array('lang' => 'PLUS_REGISTRATION_COUNTS', 'validate' => 'bool', 'type' => 'radio:yes_no', 'explain' => true);
		}
		$display['vars'] = phpbb_insert_config_array($display['vars'], $fields, array('after' => 'load_online_guests'));
		$event['display_vars'] = $display;
	}
	private function member_choice()
	{
		$value = $this->request->variable('plus_daily_scope', '', false, request_interface::POST);
		return in_array($value, array('-1', '0', '1', '2'), true) ? (int) $value : (int) $this->user->data['plus_daily_scope'];
	}
	public function member_form($event)
	{
		if (!isset($this->user->data['plus_daily_scope']) || !empty($this->config['plus_daily_force'])) { return; }
		$this->language->add_lang('daily_visitors', 'phpbb2plus/community');
		$choice = $event['submit'] ? $this->member_choice() : (int) $this->user->data['plus_daily_scope'];
		$this->template->assign_vars(array('S_PLUS_DAILY_PREF' => true, 'PLUS_DAILY_PREF' => $choice));
	}
	public function member_save($event)
	{
		if (!isset($this->user->data['plus_daily_scope']) || !empty($this->config['plus_daily_force']) || !$this->request->is_set_post('plus_daily_scope')) { return; }
		$fields = $event['sql_ary']; $fields['plus_daily_scope'] = $this->member_choice(); $event['sql_ary'] = $fields;
	}
}
