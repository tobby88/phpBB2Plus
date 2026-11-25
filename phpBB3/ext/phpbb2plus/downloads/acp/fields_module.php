<?php
namespace phpbb2plus\downloads\acp;

use phpbb\request\request_interface;
use phpbb2plus\downloads\service\access;
use phpbb2plus\downloads\service\catalogue_selection;
use phpbb2plus\downloads\service\fields;

class fields_module
{
	public $u_action;
	public $tpl_name = 'acp_plus_dl_fields';
	public $page_title = 'ACP_PLUS_DL_FIELDS';

	private function link($action, $ids = array(), $type = -1)
	{
		return $this->u_action . '&amp;action=' . $action . ($ids ? '&amp;ids=' . implode(',',$ids) : '') .
			($type>=0 ? '&amp;type=' . $type : '');
	}

	private function input($request, $form, $editing)
	{
		$keys = array('name','description',$form['type']<2 ? 'pattern' : 'choices');
		if ($editing) { $keys[] = 'position'; }
		foreach ($keys as $key)
		{
			if (!$request->is_set_post($key)) { throw new \DomainException('FORM_INVALID'); }
			$form[$key] = htmlspecialchars_decode($request->untrimmed_variable($key,'',true,request_interface::POST),ENT_QUOTES);
		}
		return $form;
	}

	public function main($module, $mode)
	{
		global $phpbb_container,$request,$template;
		if (!$phpbb_container->get('phpbb2plus.downloads.access')->field_admin()) { trigger_error('NO_AUTH_OPERATION',E_USER_WARNING); }
		$manager = $phpbb_container->get('phpbb2plus.downloads.fields');
		$lang = $phpbb_container->get('language');
		$lang->add_lang('info_acp_downloads','phpbb2plus/downloads');
		$template->assign_var('U_BACK',$this->u_action);
		$action = $request->variable('action','',false,request_interface::GET);
		$posted = $request->server('REQUEST_METHOD') === 'POST';
		try
		{
			if ($action === '' && !$posted)
			{
				foreach (range(0,5) as $type)
				{
					$template->assign_block_vars('dl_types',array('TITLE'=>$lang->lang('ACP_PLUS_DL_FIELD_TYPE_' . $type),
						'U_ADD'=>$this->link('create',array(),$type)));
				}
				foreach ($manager->listing() as $r)
				{
					$id = (int) $r['field_id'];
					$type = (int) $r['field_type'];
					$template->assign_block_vars('dl_fields',array('ID'=>$id,'NAME'=>access::plain($r['title']),'ENTRIES'=>(int) $r['entries'],
						'ORDER'=>(int) $r['position'],'TYPE'=>$lang->lang($type<=5 ? 'ACP_PLUS_DL_FIELD_TYPE_' . $type : 'ACP_PLUS_DL_FIELD_UNKNOWN'),
						'U_EDIT'=>$type<=5 ? $this->link('edit',array($id)) : '', 'U_DELETE'=>$this->link('delete',array($id))));
				}
				$template->assign_vars(array('U_DL_SELECT'=>$this->link('select'),'DL_SAVED'=>$request->variable('saved',0,false,request_interface::GET)));
				add_form_key('plus_dl_fields_select');
				return;
			}
			if ($action === 'select' && $posted)
			{
				if (!check_form_key('plus_dl_fields_select') || !$request->is_set_post('complete')) { throw new \DomainException('FORM_INVALID'); }
				$ids = catalogue_selection::ids($request->variable('remove',array(0=>''),false,request_interface::POST),'ACP_PLUS_DL_FIELD_SELECTION');
				redirect($this->link('delete',$ids));
			}
			$raw = $request->variable('ids','',false,request_interface::GET);
			$ids = $raw === '' ? array() : catalogue_selection::ids(explode(',',$raw),'ACP_PLUS_DL_FIELD_SELECTION');
			$type = $action === 'create' ? $request->variable('type',-1,false,request_interface::GET) : -1;
			// A confirmed batch deletion reads its assignments under the catalogue
			// lock. Avoid reading the whole batch a second time on that POST.
			$view = $posted && $action === 'delete' ? null : $manager->review($action,$ids,$type);
			$form = fields::form($view['original'] ?? null,$type);
			$key = 'plus_dl_fields_' . $action . '_' . $type . '_' . hash('sha256',implode(',',$ids));
			$error = '';
			if ($posted)
			{
				if (!check_form_key($key)) { throw new \DomainException('FORM_INVALID'); }
				if ($action !== 'delete') { $form = $this->input($request,$form,$action === 'edit'); }
				try
				{
					$manager->save($action,$ids,$type,$request->variable('state','',false,request_interface::POST),$form,
						$request->variable('confirm','',false,request_interface::POST) === '1');
					redirect($this->u_action . '&amp;saved=1');
				}
				catch (\DomainException $e)
				{
					if (!in_array($e->getMessage(),array('ACP_PLUS_DL_FIELD_INVALID','ACP_PLUS_DL_FIELD_PATTERN_INVALID',
						'ACP_PLUS_DL_FIELD_OPTIONS_INVALID','ACP_PLUS_DL_FIELD_CONFIRM'),true)) { throw $e; }
					$error = $lang->lang($e->getMessage());
				}
			}
			if ($view === null) { $view = $manager->review($action,$ids,$type); }
			foreach ($view['selected'] as $row)
			{ $template->assign_block_vars('dl_selected',array('NAME'=>$row['title'],'ENTRIES'=>$row['entries'])); }
			$template->assign_vars(array('DL_ACTION'=>$action,'DL_LABEL'=>$lang->lang('ACP_PLUS_DL_FIELD_' . strtoupper($action)),
				'DL_TYPE'=>$action === 'delete' ? '' : $lang->lang('ACP_PLUS_DL_FIELD_TYPE_' . $form['type']),
				'DL_INPUT'=>$form,'DL_ERROR'=>$error,'DL_STATE'=>$view['state'],'U_DL_SAVE'=>$this->link($action,$ids,$type)));
			add_form_key($key);
		}
		catch (\DomainException $e)
		{ trigger_error($lang->lang($e->getMessage()) . adm_back_link($this->u_action),E_USER_WARNING); }
	}
}
