<?php
namespace phpbb2plus\downloads\acp;

use phpbb\request\request_interface;
use phpbb2plus\downloads\service\access;
use phpbb2plus\downloads\service\permissions;

class permissions_module
{
	public $u_action;
	public $tpl_name = 'acp_plus_dl_permissions';
	public $page_title = 'ACP_PLUS_DL_PERMISSIONS';
	private $manager;
	private $template;
	private $request;
	private $language;
	private $group_helper;

	private function link($category, $kind = '', $id = 0)
	{
		return $this->u_action . '&amp;section=edit&amp;category=' . $category . ($kind ? '&amp;kind=' . $kind . '&amp;subject=' . $id : '');
	}

	private function label($row)
	{
		$name = isset($row['group_type']) && (int) $row['group_type'] === GROUP_SPECIAL ? $this->group_helper->get_name($row['name']) : $row['name'];
		return access::plain($name);
	}

	public function main($module, $mode)
	{
		global $phpbb_container;
		if (!$phpbb_container->get('phpbb2plus.downloads.access')->permissions_admin()) { trigger_error('NO_AUTH_OPERATION',E_USER_WARNING); }
		$this->manager = $phpbb_container->get('phpbb2plus.downloads.permissions');
		$this->template = $phpbb_container->get('template');
		$this->language = $phpbb_container->get('language');
		$this->request = $phpbb_container->get('request');
		$this->group_helper = $phpbb_container->get('group_helper');
		$this->language->add_lang('info_acp_downloads','phpbb2plus/downloads');
		$this->language->add_lang('groups');
		$this->template->assign_var('U_BACK',$this->u_action);
		$section = $this->request->variable('section','',false,request_interface::GET);
		$kind = $this->request->variable('kind','',false,request_interface::GET);
		$posted = $this->request->server('REQUEST_METHOD') === 'POST';
		try
		{
			if ($section === '' && !$posted) { $this->overview(); return; }
			if ($section === 'lookup' && $posted && in_array($kind,array('u','g'),true))
			{
				if (!check_form_key('plus_dl_perms_lookup')) { throw new \DomainException('FORM_INVALID'); }
				$id = $kind === 'u' ? $this->manager->find_user($this->request->variable('username','',true,request_interface::POST)) :
					$this->request->variable('subject',0,false,request_interface::POST);
				$category = $this->request->variable('category',-1,false,request_interface::POST);
				redirect($this->link($category,$kind,$id));
			}
			if ($section !== 'edit' || !in_array($kind,array('','u','g'),true)) { throw new \DomainException('FORM_INVALID'); }
			$category = $this->request->variable('category',-1,false,request_interface::GET);
			$id = $this->request->variable('subject',0,false,request_interface::GET);
			$key = 'plus_dl_perms_' . $category . '_' . $kind . '_' . $id;
			if ($posted)
			{
				if (!check_form_key($key)) { throw new \DomainException('FORM_INVALID'); }
				$values = $this->request->variable('values',array(''=>''),false,request_interface::POST);
				$this->manager->save($category,$kind,$id,$this->request->variable('state','',false,request_interface::POST),$values);
				redirect($this->u_action . '&amp;saved=1');
			}
			$this->editor($category,$kind,$id);
			add_form_key($key);
		}
		catch (\DomainException $e)
		{ trigger_error($this->language->lang($e->getMessage()) . adm_back_link($this->u_action),E_USER_WARNING); }
	}

	private function overview()
	{
		global $phpbb_root_path, $phpEx;
		foreach ($this->manager->categories() as $id=>$row)
		{
			$this->template->assign_block_vars('dl_scopes',array('ID'=>$id,'NAME'=>access::plain($row['title']),'DEPTH'=>$row['depth'],'U_LEVELS'=>$this->link($id)));
		}
		foreach ($this->manager->groups() as $row)
		{ $this->template->assign_block_vars('dl_groups',array('ID'=>$row['id'],'NAME'=>$this->label($row))); }
		$this->template->assign_vars(array('DL_SAVED'=>$this->request->variable('saved',0,false,request_interface::GET),
			'U_FIND_USERNAME'=>append_sid("{$phpbb_root_path}memberlist.$phpEx",'mode=searchuser&amp;form=dl-select-u&amp;field=username&amp;select_single=true'),
			'U_DL_USER'=>$this->u_action . '&amp;section=lookup&amp;kind=u','U_DL_GROUP'=>$this->u_action . '&amp;section=lookup&amp;kind=g'));
		add_form_key('plus_dl_perms_lookup');
	}

	private function editor($category, $kind, $id)
	{
		$view = $this->manager->review($category,$kind,$id);
		$actions = $view['levels'];
		if ($kind && $category) { $actions = array('mod'=>null) + $actions; }
		foreach ($actions as $action=>$level)
		{
			$this->template->assign_block_vars('dl_actions',array('KEY'=>$action,'LABEL'=>$this->language->lang('ACP_PLUS_DL_ACT_' . strtoupper($action)),
				'LEVEL'=>$level ?? -1,'LEVEL_NAME'=>$this->language->lang('ACP_PLUS_DL_LEVEL_' . ($level ?? 'UNKNOWN')),
				'EDITABLE'=>in_array($action,$view['editable'],true),'DIRECT'=>isset($view['direct'][$action]),'INHERITED'=>isset($view['inherited'][$action])));
		}
		foreach (access::LEVELS as $level)
		{ $this->template->assign_block_vars('dl_levels',array('ID'=>$level,'LABEL'=>$this->language->lang('ACP_PLUS_DL_LEVEL_' . $level))); }
		$link = $this->link($category,$kind,$id);
		if ($kind)
		{
			$start = max(0,$this->request->variable('start',0,false,request_interface::GET));
			list($related,$more) = $this->manager->related($kind,$id,$start);
			foreach ($related as $row)
			{
				$this->template->assign_block_vars('dl_related',array('NAME'=>$this->label($row),'PENDING'=>(bool) $row['user_pending'],
					'URL'=>$row['link'] ? $this->link($category,$kind === 'u' ? 'g' : 'u',$row['id']) : ''));
			}
			$this->template->assign_vars(array('DL_RELATED'=>$this->language->lang($kind === 'u' ? 'USERGROUPS' : 'GROUP_MEMBERS'),
				'U_DL_PREV'=>$start ? $link . '&amp;start=' . max(0,$start-permissions::PAGE_SIZE) : '',
				'U_DL_NEXT'=>$more ? $link . '&amp;start=' . ($start+permissions::PAGE_SIZE) : ''));
		}
		$this->template->assign_vars(array('DL_EDITOR'=>true,'DL_KIND'=>$kind,'DL_SAVE'=>(bool) $view['editable'],'DL_MODERATED'=>$view['moderated'],
			'DL_TARGET'=>$kind ? $this->label($view['target']) : '','DL_SCOPE'=>$category ? access::plain($view['name']) : $this->language->lang('ACP_PLUS_DL_GLOBAL'),
			'DL_STATE'=>$view['state'],'U_DL_SAVE'=>$link));
	}
}
