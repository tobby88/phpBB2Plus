<?php
namespace phpbb2plus\knowledgebase\acp;

use phpbb\request\request_interface;

class categories_module
{
	public $u_action;
	public $tpl_name = 'acp_plus_kb_categories';
	public $page_title = 'ACP_PLUS_KB_CATEGORIES';

	public function main($module, $mode)
	{
		global $auth, $phpbb_container, $request, $template;
		if (!$auth->acl_gets('a_plus_kb', 'a_plus_kb_categories')) { trigger_error('NO_AUTH_OPERATION', E_USER_WARNING); }
		$language = $phpbb_container->get('language');
		$language->add_lang(array('knowledgebase','info_acp_knowledgebase'), 'phpbb2plus/knowledgebase');
		$categories = $phpbb_container->get('phpbb2plus.knowledgebase.categories');
		try { $tree = $categories->tree(); }
		catch (\DomainException $e) { trigger_error($language->lang($e->getMessage()), E_USER_WARNING); }
		$id = $request->variable('cat_id', 0);
		$action = $request->variable('action', '');
		if ($action === '')
		{
			$counts = $categories->counts();
			$first = $last = array();
			foreach ($tree as $cat_id => $cat)
			{
				$parent = (int) $cat['parent_id'];
				if (!isset($first[$parent])) { $first[$parent] = $cat_id; }
				$last[$parent] = $cat_id;
			}
			foreach ($tree as $cat_id => $cat)
			{
				$url = $this->u_action . '&amp;cat_id=' . $cat_id;
				$template->assign_block_vars('kb_cats', array('TITLE' => $cat['title'], 'DESCRIPTION' => $cat['description'],
					'INDENT' => str_repeat('— ', min(12, $cat['depth'])), 'TOTAL' => $counts[$cat_id] ?? 0,
					'U_EDIT' => $url . '&amp;action=edit', 'U_DELETE' => $url . '&amp;action=delete',
					'U_UP' => $first[$cat['parent_id']] !== $cat_id ? $url . '&amp;action=up' : '',
					'U_DOWN' => $last[$cat['parent_id']] !== $cat_id ? $url . '&amp;action=down' : ''));
			}
			$template->assign_var('U_CREATE', $this->u_action . '&amp;action=create');
			return;
		}
		if (!in_array($action, array('create','edit','delete','up','down'), true) || ($action === 'create' ? $id !== 0 : $id <= 0))
		{ trigger_error('FORM_INVALID', E_USER_WARNING); }
		$key = 'plus_kb_category_' . $action . '_' . $id;
		$cat = $tree[$id] ?? array();
		$input = array('title' => $cat['title'] ?? '', 'description' => $cat['description'] ?? '', 'parent' => (string) ($cat['parent_id'] ?? 0), 'destination' => '');
		$error = '';
		if ($request->server('REQUEST_METHOD') === 'POST')
		{
			if (!check_form_key($key)) { trigger_error('FORM_INVALID', E_USER_WARNING); }
			foreach (array('title','description') as $field)
			{ $input[$field] = trim(htmlspecialchars_decode($request->variable($field, '', true, request_interface::POST), ENT_QUOTES)); }
			foreach (array('parent','destination') as $field) { $input[$field] = $request->variable($field, '', false, request_interface::POST); }
			try
			{
				$categories->change($action, $id, $input, $request->variable('state', '', false, request_interface::POST));
				redirect($this->u_action);
			}
			catch (\DomainException $e)
			{
				if (!in_array($e->getMessage(), array('ACP_PLUS_KB_CAT_TEXT_INVALID','ACP_PLUS_KB_CAT_DEST_INVALID','ACP_PLUS_KB_CAT_PARENT_INVALID'), true))
				{ trigger_error($language->lang($e->getMessage()) . adm_back_link($this->u_action), E_USER_WARNING); }
				$error = $language->lang($e->getMessage());
			}
		}
		try { $review = $categories->review($tree, $action, $id); }
		catch (\DomainException $e) { trigger_error($language->lang($e->getMessage()) . adm_back_link($this->u_action), E_USER_WARNING); }
		$editing = $action === 'create' || $action === 'edit';
		if ($editing || $action === 'delete')
		{
			$blocked = array();
			foreach ($tree as $cat_id => $row)
			{
				// The parent precedes its children in the directory.
				if ($cat_id === $id || ($editing && isset($blocked[(int) $row['parent_id']]))) { $blocked[$cat_id] = true; continue; }
				$template->assign_block_vars('kb_choices', array('ID' => $cat_id, 'TITLE' => $row['title'],
					'INDENT' => str_repeat('— ', min(12, $row['depth'])), 'SELECTED' => (string) $cat_id === $input[$editing ? 'parent' : 'destination']));
			}
		}
		add_form_key($key);
		$template->assign_vars(array('KB_FORM' => true, 'KB_EDIT' => $editing, 'KB_DELETE' => $action === 'delete',
			'KB_LABEL' => $language->lang('ACP_PLUS_KB_CAT_' . strtoupper($action)), 'KB_ERROR' => $error,
			'KB_TITLE' => $editing ? $input['title'] : $cat['title'], 'KB_DESCRIPTION' => $input['description'],
			'KB_ROOT' => $input['parent'] === '0', 'KB_DROP_CONTENTS' => $input['destination'] === '0',
			'KB_AFFECTED' => $language->lang('ACP_PLUS_KB_CAT_AFFECTED', $review['count'], $cat['children'] ?? 0),
			'KB_STATE' => $review['state'], 'U_SAVE' => $this->u_action . '&amp;action=' . $action . '&amp;cat_id=' . $id, 'U_BACK' => $this->u_action));
	}
}
