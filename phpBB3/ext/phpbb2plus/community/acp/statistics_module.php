<?php
namespace phpbb2plus\community\acp;

use phpbb\request\request_interface;
use phpbb2plus\community\service\statistics_admin;
use phpbb2plus\community\service\statistics_modules;

class statistics_module
{
	public $u_action;
	public $tpl_name = 'acp_plus_statistics';
	public $page_title;

	private function integer($request, $name)
	{
		$raw = $request->variable($name, '', false, request_interface::POST);
		if ($raw === '' || strlen($raw) > 10 || !ctype_digit($raw)) { throw new \DomainException('FORM_INVALID'); }
		return (int) $raw;
	}

	public function main($module, $mode)
	{
		global $phpbb_container, $request, $template;
		$settings = $phpbb_container->get('phpbb2plus.community.statistics_admin');
		if (!$settings->allowed($mode)) { trigger_error('NO_AUTH_OPERATION', E_USER_WARNING); }
		$language = $phpbb_container->get('language');
		$language->add_lang(array('statistics','info_acp_statistics'), 'phpbb2plus/community');
		$this->page_title = 'ACP_PLUS_STATS_' . strtoupper($mode);
		$snapshot = $settings->snapshot($mode);
		$action = $mode === 'settings' ? 'settings' : $request->variable('action', '');
		$id = $request->variable('item', 0);
		$index = $action === 'edit' ? array_search($id, array_column($snapshot, 'module_id'), true) : false;
		if (!in_array($action, array('','add','edit','settings'), true) || ($mode === 'manage' && $action === 'settings') ||
			($action === 'edit' && ($index === false || !statistics_modules::supported($snapshot[$index])))) { trigger_error('FORM_INVALID', E_USER_WARNING); }
		$key = 'plus_statistics_' . $action . '_' . ($action === 'edit' ? $id : 0);
		$link = $this->u_action . ($mode === 'settings' ? '' : '&amp;action=' . $action . ($action === 'edit' ? '&amp;item=' . $id : ''));
		if ($request->server('REQUEST_METHOD') === 'POST')
		{
			if ($action === '' || !check_form_key($key)) { trigger_error('FORM_INVALID', E_USER_WARNING); }
			try
			{
				$data = $mode === 'settings' ? array('limit' => $this->integer($request, 'limit'), 'ttl' => $this->integer($request, 'ttl'),
					'clear' => $request->variable('clear', 0, false, request_interface::POST) === 1) :
					array('id' => $action === 'add' ? 0 : $id, 'name' => $request->variable('name', '', false, request_interface::POST),
						'state' => $this->integer($request, 'state'), 'audience' => $this->integer($request, 'audience'), 'position' => $this->integer($request, 'position'));
				$settings->save($mode, $request->variable('revision', '', false, request_interface::POST), $data);
			}
			catch (\DomainException $e) { trigger_error($language->lang($e->getMessage()) . adm_back_link($this->u_action), E_USER_WARNING); }
			$user = $phpbb_container->get('user');
			$operation = $mode === 'settings' ? 'LOG_PLUS_STATS_SETTINGS' : 'LOG_PLUS_STATS_MODULE';
			$args = $mode === 'settings' ? array() : array($action === 'add' ? $data['name'] : $snapshot[$index]['name']);
			$phpbb_container->get('log')->add('admin', $user->data['user_id'], $user->ip, $operation, false, $args);
			redirect($this->u_action);
		}
		if ($action !== '') { add_form_key($key); }
		$template->assign_vars(array('STATS_MODE' => $action, 'STATS_REVISION' => statistics_admin::revision($snapshot),
			'U_STATS_SAVE' => $link, 'U_STATS_RETURN' => $this->u_action));
		$text = $phpbb_container->get('config_text');
		if ($mode === 'settings')
		{
			$template->assign_vars(array('STATS_LIMIT' => $snapshot['plus_statistics_limit'], 'STATS_TTL' => $snapshot['plus_statistics_storage_ttl'],
				'STATS_SOURCE_DIR' => (string) $text->get('plus_statistics_source_dir')));
			return;
		}
		$available = statistics_admin::available($snapshot);
		if ($action === '')
		{
			$template->assign_var('U_STATS_ADD', $available ? $this->u_action . '&amp;action=add' : '');
			$original = json_decode((string) $text->get('plus_statistics_source_modules'), true);
			$original = array_column((array) $original, null, 'module_id');
			foreach ($snapshot as $row)
			{
				$known = statistics_modules::supported($row);
				$name = trim($row['name']);
				$source = $original[$row['module_id']] ?? array();
				$template->assign_block_vars('registrations', array('NAME' => $known ? $language->lang('PLUS_STATS_' . strtoupper($name)) : $name,
					'STATE' => $language->lang('PLUS_STATS_STATE_' . statistics_admin::state($row)),
					'AUDIENCE' => $language->lang(statistics_admin::AUDIENCES[$row['auth_value']] ?? 'PLUS_STATS_UNMAPPED'),
					'EDIT' => $known ? $this->u_action . '&amp;action=edit&amp;item=' . $row['module_id'] : '',
					'SOURCE' => $source ? $language->lang('PLUS_STATS_SOURCE_RECORD', $source['module_id'], $source['name'], $source['display_order'],
						$source['installed'], $source['active'], $source['auth_value'], $source['update_time']) : '',
					'DIGEST' => $source['source_sha256'] ?? ''));
			}
			return;
		}
		if ($action === 'add' && !$available) { trigger_error('FORM_INVALID', E_USER_WARNING); }
		$row = $action === 'add' ? array('auth_value' => 5, 'installed' => 1, 'active' => 0) : $snapshot[$index];
		foreach ($available as $name)
		{
			$template->assign_block_vars('available', array('KEY' => $name, 'NAME' => $language->lang('PLUS_STATS_' . strtoupper($name))));
		}
		foreach (statistics_admin::AUDIENCES as $value => $label)
		{
			$template->assign_block_vars('audiences', array('KEY' => $value, 'LABEL' => $language->lang($label), 'SELECTED' => $value === $row['auth_value']));
		}
		$template->assign_vars(array('STATS_ADD' => $action === 'add', 'STATS_SELECTED' => !isset(statistics_admin::AUDIENCES[$row['auth_value']]),
			'STATS_NAME' => $action === 'edit' ? $language->lang('PLUS_STATS_' . strtoupper(trim($row['name']))) : '',
			'STATS_POSITION' => $action === 'add' ? count($snapshot) + 1 : $index + 1, 'STATS_MAX_POSITION' => count($snapshot) + ($action === 'add' ? 1 : 0),
			'STATS_STATE' => statistics_admin::state($row)));
	}
}
