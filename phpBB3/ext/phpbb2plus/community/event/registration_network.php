<?php
namespace phpbb2plus\community\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class registration_network implements EventSubscriberInterface
{
	private $network;
	private $template;
	private $language;
	private $ext;

	public function __construct($network, $template, $language, $ext)
	{
		$this->network = $network; $this->template = $template; $this->language = $language; $this->ext = $ext;
	}

	public static function getSubscribedEvents()
	{
		return array('core.permissions' => 'permissions', 'core.acp_users_display_overview' => 'overview');
	}

	public function permissions($event)
	{
		$this->language->add_lang('info_acp_registration_network', 'phpbb2plus/community');
		$permissions = $event['permissions'];
		$permissions['a_plus_reg_network'] = array('lang' => 'ACL_A_PLUS_REG_NETWORK', 'cat' => 'user_group');
		$event['permissions'] = $permissions;
	}

	public function overview($event)
	{
		$row = $event['user_row'];
		if (!$this->network->permitted() || (int) $row['user_id'] <= ANONYMOUS || (int) $row['user_type'] === USER_IGNORE) { return; }
		$this->language->add_lang('info_acp_registration_network', 'phpbb2plus/community');
		$this->template->assign_vars(array('REG_NETWORK_HOST' => $row['plus_reg_host'],
			'U_REG_NETWORK_ACCOUNT' => append_sid('index.' . $this->ext, 'i=-phpbb2plus-community-acp-registration_network_module&amp;mode=view&amp;u=' . (int) $row['user_id'])));
	}
}
