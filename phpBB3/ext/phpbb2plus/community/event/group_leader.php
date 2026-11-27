<?php
namespace phpbb2plus\community\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class group_leader implements EventSubscriberInterface
{
	private $groups;
	private $request;
	private $helper;
	private $user;
	private $auth;
	private $ext;

	public function __construct($groups, $request, $helper, $user, $auth, $ext)
	{
		$this->groups = $groups; $this->request = $request; $this->helper = $helper;
		$this->user = $user; $this->auth = $auth; $this->ext = $ext;
	}

	public static function getSubscribedEvents()
	{
		return array('core.add_form_key'=>'open', 'core.user_setup_after'=>'roster');
	}

	public function roster()
	{
		// The other link in a native group-request notification opens the public roster.
		if (($this->user->page['page_name'] ?? '') !== 'memberlist.' . $this->ext || $this->request->variable('mode', '') !== 'group' ||
			!in_array($this->request->server('REQUEST_METHOD'), array('GET','HEAD'), true) ||
			!$this->auth->acl_gets('u_viewprofile', 'a_user', 'a_useradd', 'a_userdel')) { return; }
		$group = $this->request->variable('g', 0);
		$managed = $group ? $this->groups->managed_group($group) : false;
		if ($managed && (int) $managed['group_type'] === GROUP_HIDDEN)
		{
			$this->redirect($group, 'list');
		}
	}

	public function open($event)
	{
		global $module;
		if ($event['form_name'] !== 'ucp_groups' || ($module->p_name ?? '') !== 'ucp_groups' || $module->p_mode !== 'manage' ||
			!in_array($this->request->server('REQUEST_METHOD'), array('GET','HEAD'), true)) { return; }
		$action = $this->request->variable('action', '');
		$group = $this->request->variable('g', 0);
		if (!$group || !in_array($action, array('list', 'edit'), true) || !$this->groups->managed_group($group)) { return; }
		$this->redirect($group, $action);
	}

	private function redirect($group, $action)
	{
		$params = array('group'=>$group);
		if ($start = $this->request->variable('start', 0)) { $params['start'] = max(0, $start); }
		redirect($this->helper->route('phpbb2plus_community_group_lead', $params, false) . ($action === 'edit' ? '#group-settings' : ''));
	}
}
