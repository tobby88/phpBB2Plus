<?php
namespace phpbb2plus\community\acp;

use phpbb\request\request_interface;
use phpbb2plus\community\profile\country_flags;

class flags_module
{
	public $u_action;
	public $tpl_name = 'acp_plus_flags';
	public $page_title = 'ACP_PLUS_FLAGS';

	private function link($action, $id)
	{
		return $this->u_action . '&amp;action=' . $action . ($id ? '&amp;flag=' . $id : '');
	}

	private function deny()
	{
		send_status_line(403, 'Forbidden');
		trigger_error('NO_AUTH_OPERATION', E_USER_WARNING);
	}

	public function main($module, $mode)
	{
		global $phpbb_container, $request, $template;
		$catalogue = $phpbb_container->get('phpbb2plus.community.flag_catalogue');
		try { $catalogue->authorize(); }
		catch (\DomainException $e) { $this->deny(); }
		$lang = $phpbb_container->get('language');
		$lang->add_lang('info_acp_flags', 'phpbb2plus/community');
		$action = $request->variable('action', '');
		if ($action === '')
		{
			foreach ($catalogue->listing() as $row)
			{
				$id = (int) $row['option_value'];
				$template->assign_block_vars('plus_flags', array('ID'=>$id, 'NAME'=>html_entity_decode($row['label'], ENT_QUOTES, 'UTF-8'),
					'FILENAME'=>$row['source_file'], 'IMAGE'=>country_flags::public_image($row),
					'EDIT'=>$this->link('edit', $id), 'DELETE'=>$this->link('delete', $id)));
			}
			$template->assign_var('U_FLAG_CREATE', $this->link('create', 0));
			return;
		}
		$id = $request->variable('flag', 0);
		$revision = $catalogue->revision();
		$row = $id > 0 ? $catalogue->find($id) : null;
		if (!in_array($action, array('create','edit','delete'), true) || ($action === 'create' ? $id !== 0 : !$row))
		{ trigger_error('FORM_INVALID', E_USER_WARNING); }
		$label = $row ? html_entity_decode($row['label'], ENT_QUOTES, 'UTF-8') : '';
		$filename = $row['source_file'] ?? '';
		$error = '';
		$key = 'plus_flag_' . $action . '_' . $id;
		if ($request->server('REQUEST_METHOD') === 'POST')
		{
			if (!check_form_key($key) || ($action === 'delete' && !$request->variable('confirm', 0, false, request_interface::POST)))
			{ trigger_error('FORM_INVALID', E_USER_WARNING); }
			if ($action !== 'delete')
			{
				$label = htmlspecialchars_decode($request->variable('label', '', true, request_interface::POST), ENT_QUOTES);
				$filename = $request->variable('filename', '', true, request_interface::POST);
			}
			try
			{
				$catalogue->save($action, $id, $request->variable('revision', -1, false, request_interface::POST), $label, $filename);
				redirect($this->u_action);
			}
			catch (\DomainException $e)
			{
				if ($e->getMessage() === 'NO_AUTH_OPERATION') { $this->deny(); }
				if (in_array($e->getMessage(), array('ACP_PLUS_FLAG_CHANGED','ACP_PLUS_FLAG_NATIVE'), true))
				{ trigger_error($lang->lang($e->getMessage()) . adm_back_link($this->u_action), E_USER_WARNING); }
				$error = $lang->lang($e->getMessage());
			}
		}
		add_form_key($key);
		$template->assign_vars(array('S_FLAG_FORM'=>true, 'S_FLAG_DELETE'=>$action === 'delete', 'FLAG_LABEL'=>$label,
			'FLAG_FILENAME'=>$filename, 'FLAG_ERROR'=>$error, 'FLAG_REVISION'=>$revision, 'U_BACK'=>$this->u_action,
			'U_FLAG_SAVE'=>$this->link($action, $id), 'FLAG_HEADING'=>$lang->lang('ACP_PLUS_FLAG_' . strtoupper($action))));
	}
}
