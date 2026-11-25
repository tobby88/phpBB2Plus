<?php
namespace phpbb2plus\gallery\acp;

use phpbb\request\request_interface;
use phpbb2plus\gallery\service\categories;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class categories_module
{
	public $u_action;
	public $tpl_name = 'acp_plus_album_categories';
	public $page_title = 'ACP_PLUS_ALBUM_CATEGORIES';

	private function url($action, $id)
	{
		return $this->u_action . '&amp;action=' . $action . '&amp;category=' . $id;
	}

	public function main($module, $mode)
	{
		global $phpbb_container, $request, $template;
		$language = $phpbb_container->get('language');
		$language->add_lang(array('gallery','info_acp_gallery'), 'phpbb2plus/gallery');
		$service = $phpbb_container->get('phpbb2plus.gallery.categories');
		$action = $request->variable('action', '', false, request_interface::GET);
		$id = $request->variable('category', 0, false, request_interface::GET);
		$template->assign_var('U_BACK', $this->u_action);
		try
		{
			$tree = $service->tree();
			if ($action === '')
			{
				$last = $up = $down = array();
				foreach ($tree as $other => $cat)
				{
					$parent = (int) $cat['parent_id'];
					if (isset($last[$parent])) { $up[$other] = $down[$last[$parent]] = true; }
					$last[$parent] = $other;
				}
				foreach ($tree as $other => $cat)
				{
					$template->assign_block_vars('categories', array('TITLE' => $cat['title'], 'DESCRIPTION' => $cat['description'],
						'INDENT' => str_repeat('— ', min(12, $cat['depth'])), 'U_EDIT' => $this->url('edit', $other),
						'U_ADD' => $this->url('add', $other), 'U_DELETE' => $this->url('delete', $other),
						'U_UP' => isset($up[$other]) ? $this->url('up', $other) : '', 'U_DOWN' => isset($down[$other]) ? $this->url('down', $other) : ''));
				}
				$template->assign_vars(array('S_DIRECTORY' => true, 'U_ADD' => $this->url('add', 0)));
				return;
			}
			$review = $service->review($tree, $id, $action);
			$cat = $tree[$id] ?? array();
			$editing = $action === 'add' || $action === 'edit';
			$fields = array('title' => '', 'description' => '', 'parent' => (string) $id, 'destination' => '') + categories::DEFAULTS;
			if ($action === 'edit')
			{
				$fields['title'] = $cat['title']; $fields['description'] = $cat['description']; $fields['parent'] = (string) $cat['parent_id'];
				foreach (categories::DEFAULTS as $name => $default) { $fields[$name] = (string) $cat[$name === 'approval' ? $name : $name . '_level']; }
			}
			$key = 'plus_album_public_' . $action . '_' . $id;
			if ($request->server('REQUEST_METHOD') === 'POST')
			{
				if (!check_form_key($key)) { trigger_error('FORM_INVALID', E_USER_WARNING); }
				foreach ($fields as $name => $default)
				{
					$fields[$name] = trim(htmlspecialchars_decode($request->variable($name, '', true, request_interface::POST), ENT_QUOTES));
				}
				try
				{
					$service->change($id, $action, $fields, $request->variable('state', '', false, request_interface::POST));
					redirect($this->u_action);
				}
				catch (\DomainException $e)
				{
					if (in_array($e->getMessage(), array('PLUS_ALBUM_TREE_CHANGED','PLUS_ALBUM_TREE_INVALID','PLUS_ALBUM_PUBLIC_MISSING'), true)) { throw $e; }
					$template->assign_var('ALBUM_ERROR', $language->lang($e->getMessage()));
				}
			}
			if ($editing || $action === 'delete')
			{
				foreach ($service->choices($tree, $id, $action) as $other => $row)
				{
					$template->assign_block_vars('choices', array('ID' => $other, 'TITLE' => $row['title'], 'INDENT' => str_repeat('— ', min(12, $row['depth'])),
						'SELECTED' => (string) $other === $fields[$editing ? 'parent' : 'destination']));
				}
			}
			if ($editing)
			{
				foreach (categories::DEFAULTS as $name => $default)
				{
					$options = categories::options($name);
					$template->assign_block_vars('levels', array('NAME' => $name, 'LABEL' => $language->lang('PLUS_ALBUM_PUBLIC_' . strtoupper($name) . '_LEVEL')));
					foreach ($options as $level => $label)
					{
						$template->assign_block_vars('levels.options', array('ID' => $level, 'LABEL' => $language->lang('PLUS_ALBUM_CFG_' . $label),
							'SELECTED' => (string) $level === $fields[$name]));
					}
					if ($action === 'edit')
					{
						$current = (string) $cat[$name === 'approval' ? $name : $name . '_level'];
						if (!isset($options[$current])) { $template->assign_block_vars('levels.options', array('ID' => $current,
							'LABEL' => $language->lang('PLUS_ALBUM_CFG_IMPORTED', $current), 'SELECTED' => $current === $fields[$name])); }
					}
				}
			}
			add_form_key($key);
			$template->assign_vars(array('S_FORM' => true, 'S_EDITING' => $editing, 'S_DELETE' => $action === 'delete',
				'S_ROOT_PARENT' => $fields['parent'] === '0', 'ALBUM_TITLE' => $fields['title'], 'ALBUM_DESCRIPTION' => $fields['description'],
				'ALBUM_CATEGORY' => $action === 'add' ? '' : $cat['title'], 'ALBUM_STATE' => $review['state'], 'U_SAVE' => $this->url($action, $id),
				'ALBUM_ACTION' => $language->lang('PLUS_ALBUM_PUBLIC_' . strtoupper($action)), 'PICTURES' => $review['pictures'], 'CHILDREN' => $cat['children'] ?? 0));
		}
		catch (AccessDeniedHttpException $e) { trigger_error('NO_AUTH_OPERATION', E_USER_WARNING); }
		catch (\DomainException $e) { $template->assign_var('ALBUM_ERROR', $language->lang($e->getMessage())); }
	}
}
