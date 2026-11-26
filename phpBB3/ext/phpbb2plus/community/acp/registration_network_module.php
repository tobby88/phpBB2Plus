<?php
namespace phpbb2plus\community\acp;

use phpbb\request\request_interface;

class registration_network_module
{
	public $u_action;
	public $tpl_name = 'acp_plus_registration_network';
	public $page_title = 'ACP_PLUS_REG_NETWORK';

	public function main($module, $mode)
	{
		global $auth, $request, $phpbb_container, $phpEx, $template, $user;
		$network = $phpbb_container->get('phpbb2plus.community.registration_network');
		if (!$network->permitted()) { send_status_line(403, 'Forbidden'); trigger_error('NO_AUTH_OPERATION', E_USER_WARNING); }
		$language = $phpbb_container->get('language');
		$language->add_lang('info_acp_registration_network', 'phpbb2plus/community');
		$template->assign_var('U_REG_NETWORK', $this->u_action);
		$posted = $request->server('REQUEST_METHOD') === 'POST';
		$name = $posted ? trim($request->variable('username', '', true, request_interface::POST)) : '';
		$id = $request->variable('u', 0, false, request_interface::GET);
		if ($posted && (!check_form_key('plus_reg_network') || $name === '')) { trigger_error('FORM_INVALID', E_USER_WARNING); }
		add_form_key('plus_reg_network');
		if (!$posted && !$id) { return; }
		$row = $network->find($id, $name);
		if (!$row) { $template->assign_var('S_NETWORK_UNKNOWN', true); return; }
		$id = (int) $row['user_id'];
		$address = trim($row['user_ip']);
		list($members, $next) = $network->matches($address, $posted ? 0 : $request->variable('after', 0, false, request_interface::GET),
			$request->variable('date', 0, false, request_interface::GET));
		$template->assign_vars(array('S_NETWORK_FOUND' => true, 'NETWORK_USERNAME' => htmlspecialchars_decode($row['username'], ENT_QUOTES),
			'NETWORK_IP' => filter_var($address, FILTER_VALIDATE_IP) !== false ? $address : '',
			'NETWORK_HOST' => $row['plus_reg_host'], 'U_NETWORK_NEXT' => $next ? $this->u_action . '&amp;u=' . $id .
				'&amp;after=' . (int) $next['user_id'] . '&amp;date=' . (int) $next['user_regdate'] : ''));
		foreach ($members as $member)
		{
			$uid = (int) $member['user_id'];
			$template->assign_block_vars('network_member', array('ID' => $uid, 'NAME' => htmlspecialchars_decode($member['username'], ENT_QUOTES),
				'EMAIL' => $member['user_email'], 'POSTS' => (int) $member['user_posts'], 'JOINED' => $user->format_date($member['user_regdate']),
				'U_ACCOUNT' => $auth->acl_get('a_user') ? append_sid("index.$phpEx", "i=users&amp;mode=overview&amp;u=$uid") : ''));
		}
	}
}
