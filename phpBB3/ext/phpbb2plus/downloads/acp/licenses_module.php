<?php
namespace phpbb2plus\downloads\acp;

use phpbb\request\request_interface;
use phpbb2plus\downloads\service\licenses;

class licenses_module
{
	public $u_action;
	public $tpl_name = 'acp_plus_dl_licenses';
	public $page_title = 'ACP_PLUS_DL_LICENSES';

	private function link($action, $ids = array())
	{
		return $this->u_action . '&amp;action=' . $action . ($ids ? '&amp;ids=' . implode(',',$ids) : '');
	}

	public function main($module, $mode)
	{
		global $phpbb_container,$request,$template;
		if (!$phpbb_container->get('phpbb2plus.downloads.access')->license_admin()) { trigger_error('NO_AUTH_OPERATION',E_USER_WARNING); }
		$manager = $phpbb_container->get('phpbb2plus.downloads.licenses');
		$lang = $phpbb_container->get('language');
		$lang->add_lang('info_acp_downloads','phpbb2plus/downloads');
		$template->assign_var('U_BACK',$this->u_action);
		$action = $request->variable('action','',false,request_interface::GET);
		$posted = $request->server('REQUEST_METHOD') === 'POST';
		try
		{
			if ($action === '' && !$posted)
			{
				foreach ($manager->listing() as $row)
				{
					$template->assign_block_vars('dl_licenses',array('ID'=>$row['id'],'NAME'=>$row['title'],'USES'=>$row['uses'],
						'U_EDIT'=>$this->link('edit',array($row['id'])),'U_DELETE'=>$this->link('delete',array($row['id']))));
				}
				$template->assign_vars(array('U_DL_ADD'=>$this->link('create'),'U_DL_SELECT'=>$this->link('select'),
					'DL_SAVED'=>$request->variable('saved',0,false,request_interface::GET)));
				add_form_key('plus_dl_licenses_select');
				return;
			}
			if ($action === 'select' && $posted)
			{
				if (!check_form_key('plus_dl_licenses_select') || !$request->is_set_post('complete')) { throw new \DomainException('FORM_INVALID'); }
				$ids = licenses::selection($request->variable('remove',array(0=>''),false,request_interface::POST));
				redirect($this->link('delete',$ids));
			}
			$raw = $request->variable('ids','',false,request_interface::GET);
			$ids = $raw === '' ? array() : licenses::selection(explode(',',$raw));
			$key = 'plus_dl_licenses_' . $action . '_' . hash('sha256',implode(',',$ids));
			$error = '';
			if ($posted)
			{
				if (!check_form_key($key)) { throw new \DomainException('FORM_INVALID'); }
				if (in_array($action,array('create','edit'),true) && (!$request->is_set_post('name') || !$request->is_set_post('terms')))
				{ throw new \DomainException('FORM_INVALID'); }
				$name = htmlspecialchars_decode($request->variable('name','',true,request_interface::POST),ENT_QUOTES);
				$terms = htmlspecialchars_decode($request->untrimmed_variable('terms','',true,request_interface::POST),ENT_QUOTES);
				try
				{
					$manager->save($action,$ids,$request->variable('state','',false,request_interface::POST),$name,$terms,
						$request->variable('confirm','',false,request_interface::POST) === '1');
					redirect($this->u_action . '&amp;saved=1');
				}
				catch (\DomainException $e)
				{
					if (!in_array($e->getMessage(),array('ACP_PLUS_DL_LICENSE_CONFIRM','ACP_PLUS_DL_LICENSE_INVALID'),true)) { throw $e; }
					$error = $lang->lang($e->getMessage());
				}
			}
			$view = $manager->review($action,$ids);
			$uses = 0;
			foreach ($view['rows'] as $id=>$row)
			{
				$template->assign_block_vars('dl_selected',array('ID'=>$id,'NAME'=>$row['title'],'USES'=>$row['uses']));
				$uses += $row['uses'];
			}
			$row = reset($view['rows']);
			$template->assign_vars(array('DL_ACTION'=>$action,'DL_LABEL'=>$lang->lang('ACP_PLUS_DL_LICENSE_' . strtoupper($action)),
				'DL_ERROR'=>$error,'DL_NAME'=>$posted ? $name : ($row['title'] ?? ''),'DL_TERMS'=>$posted ? $terms : ($row['terms'] ?? ''),
				'DL_USES'=>$uses,'DL_STATE'=>$view['state'],'U_DL_SAVE'=>$this->link($action,$ids)));
			add_form_key($key);
		}
		catch (\DomainException $e)
		{ trigger_error($lang->lang($e->getMessage()) . adm_back_link($this->u_action),E_USER_WARNING); }
	}
}
