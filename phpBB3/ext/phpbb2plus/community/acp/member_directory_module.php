<?php
namespace phpbb2plus\community\acp;

use phpbb\request\request_interface;
use phpbb2plus\community\profile\member_directory;

class member_directory_module
{
	public const RIGHTS = array('emails' => 'a_plus_mail_list', 'members' => 'a_plus_user_list');
	public $u_action;
	public $tpl_name = 'acp_plus_member_directory';
	public $page_title;

	public function main($module_id, $mode)
	{
		global $auth, $config, $db, $module, $phpbb_container, $phpEx, $request, $template, $user;
		if (!isset(self::RIGHTS[$mode]) || !defined('IN_ADMIN') || !IN_ADMIN || empty($user->data['session_admin']) ||
			empty($user->data['is_registered']) || !empty($user->data['user_perm_from']) || !$auth->acl_get(self::RIGHTS[$mode]))
		{
			send_status_line(403, 'Forbidden'); trigger_error('NO_AUTH_OPERATION', E_USER_WARNING);
		}
		$language = $phpbb_container->get('language');
		$language->add_lang('info_acp_member_directory', 'phpbb2plus/community');
		$this->page_title = 'ACP_PLUS_DIRECTORY_' . strtoupper($mode);
		$members = $mode === 'members';
		$sorts = member_directory::COMMON_SORTS + ($members ? member_directory::MEMBER_SORTS : array());
		$default = $members ? 'user_regdate' : 'username';
		$sort = $request->variable('sort', $default, false, request_interface::GET);
		if (!isset($sorts[$sort])) { $sort = $default; }
		$order = strtoupper($request->variable('order', $members ? 'DESC' : 'ASC', false, request_interface::GET)) === 'ASC' ? 'ASC' : 'DESC';
		$show = min(200, max(1, $request->variable('show', (int) $config['topics_per_page'], false, request_interface::GET)));
		$letter = strtoupper($request->variable('alpha', '', false, request_interface::GET));
		if (!preg_match('/^[A-Z0]$/D', $letter)) { $letter = ''; }
		$query = mb_substr(trim(htmlspecialchars_decode($request->variable('q', '', true, request_interface::GET), ENT_QUOTES)), 0, 255, 'UTF-8');
		$directory = new member_directory($db, $members, $query, $letter);
		$pagination = $phpbb_container->get('pagination');
		$total = $directory->total();
		$start = (int) $pagination->validate_start(max(0, $request->variable('start', 0, false, request_interface::GET)), $show, $total);
		$rows = $directory->page($sort, $order === 'ASC', $show, $start);
		$bans = $directory->account_bans(array_column($rows, 'user_id'));

		// The current ACP menu already incorporates enabled modules, their native permissions and delegated scope.
		$edit = $module->is_active('acp_users', 'overview');
		$global = $members && $module->is_active('acp_permissions', 'setting_user_global');
		$local = $members && $module->is_active('acp_permissions', 'setting_user_local');
		$founder = (int) $user->data['user_type'] === USER_FOUNDER;
		foreach ($rows as $row)
		{
			$id = (int) $row['user_id'];
			$manageable = $founder || (int) $row['user_type'] !== USER_FOUNDER;
			$data = array('NAME' => htmlspecialchars_decode($row['username'], ENT_QUOTES), 'EMAIL' => $row['user_email'],
				'MAILTO' => !$members && filter_var($row['user_email'], FILTER_VALIDATE_EMAIL) ? 'mailto:' . str_replace('%40', '@', rawurlencode($row['user_email'])) : '',
				'U_EDIT' => $edit && $manageable ? append_sid("index.$phpEx", 'i=users&amp;mode=overview&amp;u=' . $id) : '');
			if ($members)
			{
				$data += array('ID' => $id, 'POSTS' => (int) $row['user_posts'], 'JOINED' => $user->format_date($row['user_regdate']),
					'VISITED' => $row['user_lastvisit'] ? $user->format_date($row['user_lastvisit']) : '',
					'ACTIVE' => (int) $row['user_type'] !== USER_INACTIVE, 'BANNED' => isset($bans[$id]),
					'COLOUR' => preg_match('/^[a-f0-9]{6}$/iD', $row['user_colour']) ? $row['user_colour'] : '',
					'U_GLOBAL' => $global && $manageable ? append_sid("index.$phpEx", 'i=permissions&amp;mode=setting_user_global&amp;user_id%5B%5D=' . $id) : '',
					'U_LOCAL' => $local && $manageable ? append_sid("index.$phpEx", 'i=permissions&amp;mode=setting_user_local&amp;user_id%5B%5D=' . $id) : '');
			}
			$template->assign_block_vars('directory_row', $data);
		}
		foreach ($sorts as $field => $label)
		{
			$template->assign_block_vars('directory_sort', array('VALUE' => $field, 'LABEL' => $language->lang($label), 'SELECTED' => $sort === $field));
		}
		foreach (array_merge(array('0'), range('A', 'Z')) as $alpha)
		{
			$template->assign_block_vars('directory_alpha', array('VALUE' => $alpha, 'LABEL' => $alpha === '0' ? $language->lang('PLUS_DIRECTORY_OTHER') : $alpha,
				'SELECTED' => $alpha === $letter));
		}
		$url = explode('?', htmlspecialchars_decode($this->u_action, ENT_QUOTES), 2);
		parse_str($url[1] ?? '', $context);
		$criteria = http_build_query(array('sort' => $sort, 'order' => $order, 'show' => $show, 'alpha' => $letter, 'q' => $query), '', '&amp;', PHP_QUERY_RFC3986);
		$pagination->generate_template_pagination($this->u_action . '&amp;' . $criteria, 'pagination', 'start', $total, $show, $start);
		$template->assign_vars(array('DIRECTORY_TITLE' => $language->lang($this->page_title), 'S_MEMBER_DIRECTORY' => $members,
			'S_DIRECTORY_ACTIONS' => $members && ($edit || $global || $local), 'DIRECTORY_TOTAL' => $language->lang('PLUS_DIRECTORY_TOTAL', $total),
			'U_DIRECTORY_FORM' => $url[0], 'DIRECTORY_CONTEXT' => build_hidden_fields($context), 'DIRECTORY_QUERY' => $query,
			'ASCENDING' => $order === 'ASC', 'DIRECTORY_SIZE' => $show, 'PAGE_NUMBER' => $pagination->on_page($total, $show, $start)));
	}
}
