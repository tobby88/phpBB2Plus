<?php
namespace phpbb2plus\downloads\acp;

use phpbb\request\request_interface;
use phpbb2plus\downloads\service\access;
use phpbb2plus\downloads\service\catalogue_selection;
use phpbb2plus\downloads\service\file_list;

class file_actions_module
{
	public $u_action;
	public $tpl_name = 'acp_plus_dl_file_actions';
	public $page_title = 'ACP_PLUS_DL_FILE_ACTIONS';
	private $request;
	private $template;
	private $lang;
	private $access;

	private function selection_url($action,$ids)
	{
		return $this->u_action . '&amp;action=' . $action . '&amp;ids=' . implode(',',$ids);
	}

	private function display_rows($rows)
	{
		$edit = $this->access->file_admin('edit');
		foreach ($rows as $row)
		{
			$this->template->assign_block_vars('dl_files',array('ID'=>(int) $row['file_id'],'TITLE'=>access::plain($row['title']),
				'CATEGORY'=>access::plain($row['category'] ?? ''),'CAT_ID'=>(int) $row['cat_id'],
				'U_EDIT'=>$edit ? $this->u_action . '&amp;action=edit&amp;file=' . (int) $row['file_id'] : '',
				'APPROVED'=>(bool) $row['approved'],'BROKEN'=>(bool) $row['broken']));
		}
	}

	private function filter($source)
	{
		$q = htmlspecialchars_decode($this->request->variable('q','',true,$source),ENT_QUOTES);
		$status = $this->request->variable('filter','all',false,$source);
		if (!in_array($status,file_list::FILTERS,true)) { throw new \DomainException('FORM_INVALID'); }
		$cat = max(0,$this->request->variable('category',0,false,$source));
		return array($q,$status,$cat,'&amp;q=' . rawurlencode($q) . '&amp;filter=' . $status . '&amp;category=' . $cat);
	}

	private function listing($files)
	{
		list($q,$status,$cat,$params) = $this->filter(request_interface::GET);
		$start = max(0,$this->request->variable('start',0,false,request_interface::GET));
		$rows = $files->page($q,$start,$status,$cat);
		$this->display_rows(array_slice($rows,0,file_list::PAGE_SIZE));
		foreach ($this->access->categories() as $id=>$row)
		{ $this->template->assign_block_vars('dl_categories',array('ID'=>$id,'NAME'=>access::plain($row['title']))); }
		foreach (file_list::FILTERS as $item)
		{ $this->template->assign_block_vars('dl_filters',array('VALUE'=>$item,'NAME'=>$this->lang->lang('ACP_PLUS_DL_FILE_FILTER_' . strtoupper($item)))); }
		$this->template->assign_vars(array('DL_LIST'=>true,'DL_QUERY'=>$q,'DL_FILTER'=>$status,'DL_CATEGORY'=>$cat,
			'DL_APPROVAL'=>$this->access->file_admin('approve'),'DL_DELETE'=>$this->access->file_admin('delete'),
			'U_DL_CREATE'=>$this->access->file_admin('edit') ? $this->u_action . '&amp;action=create' : '',
			'DL_DONE'=>$this->request->is_set('done',request_interface::GET),
			'DL_RESULT'=>$this->lang->lang('ACP_PLUS_DL_FILES_DONE',max(0,$this->request->variable('done',0,false,request_interface::GET))),
			'U_DL_SEARCH'=>$this->u_action . '&amp;action=find','U_DL_SELECT'=>$this->u_action . '&amp;action=select',
			'U_DL_PREV'=>$start ? $this->u_action . $params . '&amp;start=' . max(0,$start-file_list::PAGE_SIZE) : '',
			'U_DL_NEXT'=>count($rows)>file_list::PAGE_SIZE ? $this->u_action . $params . '&amp;start=' . ($start+file_list::PAGE_SIZE) : ''));
		add_form_key('plus_dl_file_selection');
	}

	public function main($id, $mode)
	{
		global $phpbb_container;
		$this->access = $phpbb_container->get('phpbb2plus.downloads.access');
		if (!$this->access->file_admin('approve') && !$this->access->file_admin('delete') && !$this->access->file_admin('edit'))
		{ trigger_error('NO_AUTH_OPERATION',E_USER_WARNING); }
		$this->request = $phpbb_container->get('request');
		$this->template = $phpbb_container->get('template');
		$this->lang = $phpbb_container->get('language');
		$this->lang->add_lang('info_acp_downloads','phpbb2plus/downloads');
		$manager = $phpbb_container->get('phpbb2plus.downloads.file_actions');
		$action = $this->request->variable('action','',false,request_interface::GET);
		$posted = $this->request->server('REQUEST_METHOD') === 'POST';
		try
		{
			if ($action === '' && !$posted) { $this->listing($phpbb_container->get('phpbb2plus.downloads.file_list')); return; }
			if ($action === 'edit' || $action === 'create')
			{
				if (!$this->access->file_admin('edit')) { throw new \DomainException('NO_AUTH_OPERATION'); }
				$this->tpl_name = 'acp_plus_dl_file_editor';
				(new file_editor())->open($phpbb_container,$this->u_action,$action,$posted);
				return;
			}
			if ($action === 'find' && $posted && check_form_key('plus_dl_file_selection'))
			{
				list(,,,$params) = $this->filter(request_interface::POST);
				redirect($this->u_action . $params);
			}
			if ($action === 'select' && $posted && check_form_key('plus_dl_file_selection') && $this->request->is_set_post('complete'))
			{
				$operation = $this->request->variable('operation','',false,request_interface::POST);
				if (!in_array($operation,array('approve','withdraw','delete'),true) || !$this->access->file_admin($operation)) { throw new \DomainException('NO_AUTH_OPERATION'); }
				$ids = catalogue_selection::ids($this->request->variable('selected',array(0=>''),false,request_interface::POST),'ACP_PLUS_DL_FILE_SELECTION');
				redirect($this->selection_url($operation,$ids));
			}
			if (!$this->access->file_admin($action)) { throw new \DomainException('NO_AUTH_OPERATION'); }
			$ids = catalogue_selection::ids(explode(',',$this->request->variable('ids','',false,request_interface::GET)),'ACP_PLUS_DL_FILE_SELECTION');
			$key = 'plus_dl_files_' . $action . '_' . hash('sha256',implode(',',$ids));
			$error = '';
			if ($posted)
			{
				if (!check_form_key($key) || !$this->request->is_set_post('complete')) { throw new \DomainException('FORM_INVALID'); }
				$state = $this->request->variable('state','',false,request_interface::POST);
				if ($this->request->variable('confirm','',false,request_interface::POST) === '1')
				{
					$count = $manager->save($action,$ids,$state,true);
					redirect($this->u_action . '&amp;done=' . $count);
				}
				$error = $this->lang->lang('ACP_PLUS_DL_FILE_CONFIRM_REQUIRED');
			}
			$view = $manager->review($action,$ids);
			$this->display_rows($view['rows']);
			$n = $view['counts'];
			$this->template->assign_vars(array('DL_TITLE'=>$this->lang->lang('ACP_PLUS_DL_FILES_' . strtoupper($action)),
				'DL_STATE'=>$view['state'],'DL_ERROR'=>$error,'DL_EFFECT'=>$this->lang->lang('ACP_PLUS_DL_FILES_EFFECT',count($view['changed']),count($ids)),
				'DL_REMOVAL'=>$n ? $this->lang->lang('ACP_PLUS_DL_FILES_REMOVAL',$n['mirrors'],$n['comments'],$n['votes'],$n['field_values']) : '',
				'U_DL_SAVE'=>$this->selection_url($action,$ids),'U_BACK'=>$this->u_action));
			add_form_key($key);
		}
		catch (\DomainException $e) { trigger_error($this->lang->lang($e->getMessage()) . adm_back_link($this->u_action),E_USER_WARNING); }
	}
}
