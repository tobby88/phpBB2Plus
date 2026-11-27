<?php
namespace phpbb2plus\community\event;

use phpbb\request\request_interface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class last_login_display implements EventSubscriberInterface
{
	private $config;
	private $user;
	private $auth;
	private $language;
	private $request;

	public function __construct($config, $user, $auth, $language, $request)
	{
		$this->config = $config;
		$this->user = $user;
		$this->auth = $auth;
		$this->language = $language;
		$this->request = $request;
	}

	public static function getSubscribedEvents()
	{
		return array('core.common'=>'bookmark', 'core.memberlist_prepare_profile_data'=>'display', 'core.memberlist_modify_sql_query_data'=>'sort',
			'core.memberlist_modify_template_vars'=>'directory', 'core.acp_board_config_edit_add'=>'settings');
	}

	public function bookmark()
	{
		if ($this->request->server('REQUEST_METHOD','') !== 'GET' || $this->request->variable('mode','') !== 'lastlogon' ||
			basename($this->request->server('SCRIPT_NAME','')) !== 'memberlist.php') { return; }
		// phpBB validates mode before building the directory. The later sort event checks the viewer's native rights.
		$this->request->overwrite('mode','');
		if (!$this->request->is_set('sk'))
		{
			$this->request->overwrite('sk','pluslogin');
			$this->request->overwrite('sd', strtoupper($this->request->variable('order','ASC',false,request_interface::GET)) === 'DESC' ? 'd' : 'a');
		}
	}

	private function can_view_hidden()
	{
		return !empty($this->user->data['is_registered']) && $this->auth->acl_get('a_user') && $this->auth->acl_get('u_viewonline');
	}

	public function display($event)
	{
		$member = $event['data'];
		if (!isset($this->config['plus_hide_last_login'], $member['plus_last_login']) || (int) $member['user_id'] <= ANONYMOUS ||
			(int) $member['user_type'] === USER_IGNORE) { return; }
		$this->language->add_lang('profile_visits', 'phpbb2plus/community');
		$visible = $this->can_view_hidden() || (empty($this->config['plus_hide_last_login']) && !empty($member['user_allow_viewonline']) && empty($member['plus_login_hidden']));
		$data = $event['template_data'];
		$data['PLUS_LAST_LOGIN'] = $visible
			? ($member['plus_last_login'] ? $this->user->format_date((int) $member['plus_last_login']) : $this->language->lang('NEVER'))
			: $this->language->lang('PLUS_LAST_LOGIN_HIDDEN');
		$event['template_data'] = $data;
	}

	private function list_available()
	{
		return isset($this->config['plus_hide_last_login']) && !empty($this->user->data['is_registered']) &&
			in_array($this->request->variable('mode',''), array('','searchuser'), true) && $this->request->variable('form','') === '';
	}

	private function sort_direction()
	{
		if ($this->request->variable('sk','') !== 'pluslogin') { return ''; }
		return $this->request->variable('sd','a') === 'd' ? 'DESC' : 'ASC';
	}

	public function sort($event)
	{
		if (!$this->list_available() || !$this->can_view_hidden() || !($direction = $this->sort_direction())) { return; }
		$event['sort_key'] = 'pluslogin';
		$event['sort_dir'] = $direction === 'DESC' ? 'd' : 'a';
		$event['order_by'] = 'u.plus_last_login ' . $direction . ', u.user_id ASC';
	}

	public function directory($event)
	{
		if (!$this->list_available()) { return; }
		$this->language->add_lang('profile_visits', 'phpbb2plus/community');
		$data = $event['template_vars'];
		$data['S_PLUS_LAST_LOGIN_LIST'] = true;
		if ($this->can_view_hidden())
		{
			$data['U_PLUS_SORT_LOGIN'] = $event['sort_url'] . '&amp;sk=pluslogin&amp;sd=' . ($this->sort_direction() === 'DESC' ? 'a' : 'd');
		}
		$event['template_vars'] = $data;
	}

	public function settings($event)
	{
		if ($event['mode'] !== 'features' || !isset($this->config['plus_hide_last_login'])) { return; }
		$this->language->add_lang('profile_visits','phpbb2plus/community');
		$display = $event['display_vars'];
		$display['vars'] = phpbb_insert_config_array($display['vars'], array('plus_hide_last_login'=>array('lang'=>'PLUS_HIDE_LAST_LOGIN',
			'validate'=>'bool','type'=>'radio:yes_no','explain'=>true)), array('before'=>'legend2'));
		$event['display_vars'] = $display;
	}
}
