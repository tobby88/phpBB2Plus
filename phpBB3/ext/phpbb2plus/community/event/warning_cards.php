<?php
namespace phpbb2plus\community\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class warning_cards implements EventSubscriberInterface
{
	private $db, $config, $auth, $user, $language, $template, $request, $log;

	public function __construct($db, $config, $auth, $user, $language, $template, $request, $log)
	{
		$this->db = $db;
		$this->config = $config;
		$this->auth = $auth;
		$this->user = $user;
		$this->language = $language;
		$this->template = $template;
		$this->request = $request;
		$this->log = $log;
	}

	public static function getSubscribedEvents()
	{
		return array('core.memberlist_prepare_profile_data' => 'profile', 'core.acp_users_display_overview' => 'overview',
			'core.acp_users_overview_run_quicktool' => 'reset');
	}

	public function profile($event)
	{
		$data = $event['template_data'];
		if (empty($data['S_WARNINGS']) || empty($event['data']['plus_card_count'])) { return; }
		$this->language->add_lang('info_acp_cards', 'phpbb2plus/community');
		$data['PLUS_CARD_COUNT'] = (int) $event['data']['plus_card_count'];
		$event['template_data'] = $data;
	}

	public function overview($event)
	{
		$row = $event['user_row'];
		if (empty($row['plus_card_count']) || !$this->auth->acl_get('a_user')) { return; }
		$this->language->add_lang('info_acp_cards', 'phpbb2plus/community');
		$this->template->assign_vars(array('PLUS_CARD_COUNT' => (int) $row['plus_card_count'],
			'PLUS_CARD_THRESHOLD' => (int) $this->config['plus_card_threshold']));
		if (empty($this->user->data['user_perm_from']))
		{
			$actions = $event['quick_tool_ary'];
			$actions['plus_reset_cards'] = 'PLUS_RESET_CARDS';
			$event['quick_tool_ary'] = $actions;
		}
	}

	public function reset($event)
	{
		if ($event['action'] !== 'plus_reset_cards') { return; }
		$this->language->add_lang('info_acp_cards', 'phpbb2plus/community');
		$row = $event['user_row'];
		$id = (int) $row['user_id'];
		$back = adm_back_link($event['u_action'] . '&amp;u=' . $id);
		if (!$this->auth->acl_get('a_user') || empty($this->user->data['session_admin']) || !empty($this->user->data['user_perm_from']) || $id === ANONYMOUS)
		{
			send_status_line(403, 'Forbidden');
			trigger_error($this->language->lang('NO_AUTH_OPERATION') . $back, E_USER_WARNING);
		}
		if (!check_form_key('acp_users')) { trigger_error($this->language->lang('FORM_INVALID') . $back, E_USER_WARNING); }
		$count = (int) $row['plus_card_count'];
		if (!$count) { trigger_error($this->language->lang('PLUS_CARDS_NONE') . $back); }
		$account_action = 'plus_reset_cards_' . $id . '_' . $count;
		if (!confirm_box(true))
		{
			confirm_box(false, $this->language->lang('PLUS_CARDS_CONFIRM', $row['username'], $count), build_hidden_fields(array(
				'u' => $id, 'action' => 'plus_reset_cards', 'i' => 'users', 'mode' => 'overview', 'update' => 1,
				'plus_card_account' => generate_link_hash($account_action),
				'form_token' => $this->request->variable('form_token', ''), 'creation_time' => $this->request->variable('creation_time', 0))));
			return;
		}
		if (!check_link_hash($this->request->variable('plus_card_account', ''), $account_action))
		{
			trigger_error($this->language->lang('FORM_INVALID') . $back, E_USER_WARNING);
		}
		$this->db->sql_transaction('begin');
		$this->db->sql_query('UPDATE ' . USERS_TABLE . " SET plus_card_count=0 WHERE user_id=$id AND plus_card_count=$count");
		if ($this->db->sql_affectedrows())
		{
			$this->log->add('admin', $this->user->data['user_id'], $this->user->ip, 'LOG_PLUS_CARDS_RESET', false, array($row['username'], $count));
		}
		$this->db->sql_transaction('commit');
		trigger_error($this->language->lang('PLUS_CARDS_RESET') . $back);
	}
}
