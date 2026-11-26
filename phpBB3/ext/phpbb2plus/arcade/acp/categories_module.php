<?php
namespace phpbb2plus\arcade\acp;

use phpbb\request\request_interface;
use phpbb2plus\arcade\service\catalog;
use phpbb2plus\arcade\service\category_tree;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class categories_module
{
	public $u_action;
	public $tpl_name = 'acp_plus_arcade_categories';
	public $page_title = 'ACP_PLUS_ARC_CATEGORIES';

	private function link($action, $id = 0)
	{
		return $this->u_action . '&amp;action=' . $action . ($id ? '&amp;category=' . $id : '');
	}

	private function deny()
	{
		send_status_line(403, 'Forbidden');
		trigger_error('NO_AUTH_OPERATION', E_USER_WARNING);
	}

	public function main($module, $mode)
	{
		global $auth, $phpbb_container, $request, $template, $phpbb_admin_path, $phpEx;
		$manager = $phpbb_container->get('phpbb2plus.arcade.category_management');
		$lang = $phpbb_container->get('language');
		$lang->add_lang('info_acp_arc_categories', 'phpbb2plus/arcade');
		try { $manager->authorize(); }
		catch (AccessDeniedHttpException $e) { $this->deny(); }
		$id = $request->variable('category', 0);
		$action = $request->variable('action', '');
		$rows = $manager->records();
		$shape = category_tree::shape($rows);
		$old = $id > 0 ? ($rows[$id] ?? null) : null;
		$post = $request->server('REQUEST_METHOD') === 'POST';
		$form = 'arc_category_manage';
		$input = array('name'=>catalog::plain($old['cat_name'] ?? ''), 'description'=>catalog::plain($old['cat_desc'] ?? ''),
			'icon'=>catalog::plain($old['cat_icon'] ?? ''), 'type'=>$old['cat_type'] ?? 'p', 'parent'=>(int) ($old['cat_parent'] ?? 0),
			'special'=>(string) (int) ($old['special_play'] ?? 0), 'group'=>$old && (int) $old['group_required'] !== 0 ? 'keep' : '0',
			'moderator'=>$manager->moderator($old['moderator_id'] ?? 0), 'clear_moderator'=>false);
		if ($post)
		{
			if (!check_form_key($form)) { trigger_error('FORM_INVALID', E_USER_WARNING); }
			if (in_array($action, array('edit','create'), true))
			{
				foreach (array('name','description','icon','type','special','group','moderator') as $key)
				{ $input[$key] = htmlspecialchars_decode($request->variable($key, '', true, request_interface::POST), ENT_QUOTES); }
				$input['parent'] = $request->variable('parent', 0, false, request_interface::POST);
				$input['clear_moderator'] = $request->variable('clear_moderator', false, false, request_interface::POST);
			}
			if (in_array($action, array('delete','resync'), true) && !$request->variable('confirm', 0, false, request_interface::POST))
			{ redirect($this->u_action); }
			try
			{
				$manager->change($action, $id, $request->variable('revision', '', false, request_interface::POST), $input);
				redirect($this->u_action);
			}
			catch (AccessDeniedHttpException $e) { $this->deny(); }
			catch (\DomainException $e)
			{
				$conflict = in_array($e->getMessage(), array('PLUS_ARC_CAT_CHANGED','PLUS_ARC_CAT_REPAIR','PLUS_ARC_CAT_GLOBAL'), true);
				send_status_line($conflict ? 409 : 422, $conflict ? 'Conflict' : 'Unprocessable Entity');
				$template->assign_var('CAT_ERROR', $lang->lang($e->getMessage()));
				if ($conflict) { $action = ''; }
			}
		}
		add_form_key($form);
		$template->assign_vars(array('U_CAT_BACK'=>$this->u_action, 'U_CAT_NEW'=>$this->link('create'), 'U_CAT_RESYNC'=>$this->link('resync')));
		try { $tree = new category_tree($rows); }
		catch (\DomainException $e) { trigger_error($lang->lang($e->getMessage()), E_USER_WARNING); }
		if ($action === 'picture' && !$post && $old)
		{
			try { $picture = $phpbb_container->get('phpbb2plus.arcade.artwork')->response($old); }
			catch (NotFoundHttpException $e)
			{
				send_status_line(404, 'Not Found');
				trigger_error('NO_ATTACHMENT_SELECTED', E_USER_WARNING);
			}
			$picture->prepare($phpbb_container->get('symfony_request'))->send();
			garbage_collection();
			exit_handler();
		}
		if (in_array($action, array('edit','delete'), true) && !$old) { trigger_error('FORM_INVALID', E_USER_WARNING); }
		if ($action === 'delete' || $action === 'resync')
		{
			$template->assign_vars(array('S_CAT_CONFIRM'=>true, 'CAT_CONFIRM'=>$action === 'delete' ? $lang->lang('PLUS_ARC_CAT_CONFIRM_DELETE', $input['name']) :
				$lang->lang('PLUS_ARC_CAT_CONFIRM_RESYNC'), 'U_CAT_SAVE'=>$this->link($action, $id), 'CAT_REVISION'=>category_tree::revision($shape, $old)));
			return;
		}
		if ($action === 'create' || $action === 'edit')
		{
			$template->assign_vars(array('S_CAT_EDIT'=>true, 'CAT_HEADING'=>$lang->lang($action === 'edit' ? 'PLUS_ARC_CAT_EDIT' : 'PLUS_ARC_CAT_NEW'),
				'CAT_NAME'=>$input['name'], 'CAT_DESCRIPTION'=>$input['description'], 'CAT_TYPE'=>$input['type'], 'CAT_ICON'=>$input['icon'],
				'U_CAT_PICTURE'=>!empty($old['icon_storage']) && $input['icon'] === catalog::plain($old['cat_icon']) ? $this->link('picture',$id) : '',
				'CAT_SPECIAL'=>$input['special'], 'CAT_MODERATOR'=>$input['moderator'], 'S_CAT_MISSING_MOD'=>empty($old['moderator_id']) && !empty($old['mod_id']),
				'CAT_REVISION'=>category_tree::revision($shape, $old), 'U_CAT_SAVE'=>$this->link($action,$id)));
			foreach ($tree->roots as $key)
			{
				if ($key !== $id && $tree->rows[$key]['cat_type'] === 'p')
				{ $template->assign_block_vars('parents', array('ID'=>$key,'NAME'=>catalog::plain($rows[$key]['cat_name']),'SEL'=>$input['parent'] === $key)); }
			}
			$groups = array_column($manager->groups(), 'group_name', 'group_id');
			if ($old && (int) $old['group_required'] !== 0)
			{
				$gid = (int) ($old['native_group_id'] ?: $old['group_required']);
				$name = $old['native_group_id'] ? catalog::plain($groups[$gid] ?? '') : $manager->group_label($gid);
				$template->assign_block_vars('groups', array('ID'=>'keep', 'NAME'=>$lang->lang('PLUS_ARC_CAT_KEEP_GROUP', $name ?: '#' . $gid), 'SEL'=>$input['group'] === 'keep'));
			}
			$template->assign_block_vars('groups', array('ID'=>0, 'NAME'=>$lang->lang('PLUS_ARC_CAT_ALL'), 'SEL'=>$input['group'] === '0'));
			foreach ($groups as $gid => $name) { $template->assign_block_vars('groups', array('ID'=>$gid, 'NAME'=>catalog::plain($name), 'SEL'=>(string) $gid === $input['group'])); }
			return;
		}
		foreach ($tree->order() as $key)
		{
			if ($key === -1) { continue; }
			$row = $tree->rows[$key];
			$siblings = $tree->siblings($key);
			$template->assign_block_vars('cats', array('ID'=>$key, 'NAME'=>catalog::plain($row['cat_name']),
				'DESCRIPTION'=>catalog::plain($row['cat_desc']),
				'TYPE'=>$lang->lang('PLUS_ARC_CAT_TYPE_' . strtoupper($row['cat_type'])), 'CHILD'=>$row['cat_type'] === 's',
				'U_EDIT'=>$this->link('edit',$key), 'U_DELETE'=>$this->link('delete',$key), 'U_UP'=>$this->link('up',$key), 'U_DOWN'=>$this->link('down',$key),
				'REVISION'=>category_tree::revision($shape,$rows[$key]), 'UP'=>$siblings[0] !== $key && !$tree->repair, 'DOWN'=>$siblings[count($siblings)-1] !== $key && !$tree->repair,
				'U_PICTURE'=>!empty($row['icon_storage']) ? $this->link('picture',$key) : '',
				'U_GAMES'=>$row['cat_type'] !== 'l' && $auth->acl_get('a_plus_arc_scores') ? append_sid($phpbb_admin_path . 'index.' . $phpEx,
					'i=-phpbb2plus-arcade-acp-scores_module&amp;mode=manage&amp;category=' . $key) : ''));
		}
		$template->assign_var('S_CAT_REPAIR', $tree->repair);
	}
}
