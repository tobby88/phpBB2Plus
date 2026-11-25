<?php
namespace phpbb2plus\downloads\acp;

use phpbb\request\request_interface;
use phpbb2plus\downloads\service\access;
use phpbb2plus\downloads\service\catalogue_selection;
use phpbb2plus\downloads\service\mirrors;

class mirrors_module
{
	public $u_action;
	public $tpl_name = 'acp_plus_dl_mirrors';
	public $page_title = 'ACP_PLUS_DL_MIRRORS';
	private $manager;
	private $request;
	private $template;
	private $lang;

	private function link($action = '', $file = 0, $ids = array())
	{
		return $this->u_action . ($action === '' ? '' : '&amp;action=' . $action) . ($file ? '&amp;file=' . $file : '') .
			($ids ? '&amp;ids=' . implode(',',$ids) : '');
	}

	private function listing()
	{
		$query = htmlspecialchars_decode($this->request->variable('q','',true,request_interface::GET),ENT_QUOTES);
		$start = max(0,$this->request->variable('start',0,false,request_interface::GET));
		$rows = $this->manager->files($query,$start);
		foreach (array_slice($rows,0,mirrors::PAGE_SIZE) as $row)
		{
			$this->template->assign_block_vars('dl_files',array('ID'=>$row['file_id'],'TITLE'=>access::plain($row['title']),
				'CATEGORY'=>access::plain($row['category'] ?? ''),'APPROVED'=>(bool) $row['approved'],'COUNT'=>$row['copies'],
				'URL'=>$this->link('file',(int) $row['file_id'])));
		}
		$url = $this->u_action . '&amp;q=' . rawurlencode($query) . '&amp;start=';
		$this->template->assign_vars(array('DL_LIST'=>true,'DL_QUERY'=>$query,'U_DL_SEARCH'=>$this->link('find'),
			'U_DL_PREV'=>$start ? $url . max(0,$start-mirrors::PAGE_SIZE) : '',
			'U_DL_NEXT'=>count($rows)>mirrors::PAGE_SIZE ? $url . ($start+mirrors::PAGE_SIZE) : ''));
		add_form_key('plus_dl_mirror_search');
	}

	private function source($row)
	{
		$data = json_decode($row['source_json'],true) ?: array();
		$remote = mirrors::remote($data);
		return $row['asset_status'] === 'remote' || ($row['asset_status'] !== 'ready' && $remote !== '') ? $remote : mirrors::filename($data);
	}

	private function describe($key, $row)
	{
		$label = $row ? mirrors::label($row) : '';
		return array('ID'=>$key,'NAME'=>$label,'TITLE'=>trim($label) === '' ? $this->lang->lang('PLUS_DL_MIRROR_NUMBER',$key) : $label,
			'CURRENT'=>$row ? $this->source($row) : '', 'STATUS'=>$row ? $this->lang->lang('ACP_PLUS_DL_MIRROR_' .
				(in_array($row['asset_status'],array('ready','remote'),true) ? strtoupper($row['asset_status']) : 'UNAVAILABLE')) : '');
	}

	private function file_page($id)
	{
		$file = $this->manager->file($id);
		foreach ($this->manager->items($id) as $key=>$row)
		{
			$this->template->assign_block_vars('dl_mirrors',$this->describe($key,$row) + array(
				'U_EDIT'=>$file['eligible'] ? $this->link('edit',$id,array($key)) : '','U_DELETE'=>$this->link('delete',$id,array($key))));
		}
		$this->template->assign_vars(array('DL_FILE'=>true,'DL_TITLE'=>access::plain($file['title']),'DL_ELIGIBLE'=>$file['eligible'],
			'DL_SAVED'=>$this->request->variable('saved',0,false,request_interface::GET),'U_DL_NEW'=>$this->link('create',$id),
			'U_DL_SELECT'=>$this->link('select',$id),'U_BACK'=>$this->u_action));
		add_form_key('plus_dl_mirror_select_' . $id);
	}

	public function main($module, $mode)
	{
		global $phpbb_container;
		if (!$phpbb_container->get('phpbb2plus.downloads.access')->mirror_admin()) { trigger_error('NO_AUTH_OPERATION',E_USER_WARNING); }
		$this->manager = $phpbb_container->get('phpbb2plus.downloads.mirrors');
		$this->request = $phpbb_container->get('request');
		$this->template = $phpbb_container->get('template');
		$this->lang = $phpbb_container->get('language');
		$this->lang->add_lang(array('downloads','info_acp_downloads'),'phpbb2plus/downloads');
		$action = $this->request->variable('action','',false,request_interface::GET);
		$file = $this->request->variable('file',0,false,request_interface::GET);
		$posted = $this->request->server('REQUEST_METHOD') === 'POST';
		try
		{
			if ($action === '' && !$posted) { $this->listing(); return; }
			if ($action === 'find' && $posted && check_form_key('plus_dl_mirror_search'))
			{
				$q = htmlspecialchars_decode($this->request->variable('q','',true,request_interface::POST),ENT_QUOTES);
				redirect($this->u_action . '&amp;q=' . rawurlencode($q));
			}
			if ($file<1) { throw new \DomainException('FORM_INVALID'); }
			if ($action === 'file' && !$posted) { $this->file_page($file); return; }
			if ($action === 'select' && $posted)
			{
				if (!check_form_key('plus_dl_mirror_select_' . $file) || !$this->request->is_set_post('complete')) { throw new \DomainException('FORM_INVALID'); }
				$operation = $this->request->variable('operation','',false,request_interface::POST);
				if (!in_array($operation,array('edit','delete'),true)) { throw new \DomainException('FORM_INVALID'); }
				$ids = catalogue_selection::ids($this->request->variable('selected',array(0=>''),false,request_interface::POST),'ACP_PLUS_DL_MIRROR_SELECTION');
				redirect($this->link($operation,$file,$ids));
			}
			$raw = $this->request->variable('ids','',false,request_interface::GET);
			$ids = $raw === '' ? array() : catalogue_selection::ids(explode(',',$raw),'ACP_PLUS_DL_MIRROR_SELECTION');
			$key = 'plus_dl_mirror_' . $action . '_' . $file . '_' . hash('sha256',implode(',',$ids));
			$input = $uploads = array();
			$error = '';
			if ($posted)
			{
				if (!check_form_key($key) || !$this->request->is_set_post('complete')) { throw new \DomainException('FORM_INVALID'); }
				if ($action !== 'delete')
				{
					foreach ($action === 'create' ? array(0) : $ids as $id)
					{
						foreach (array('location','source','url') as $field)
						{
							$name = $field . '_' . $id;
							if (!$this->request->is_set_post($name)) { throw new \DomainException('FORM_INVALID'); }
							$input[$id][$field] = htmlspecialchars_decode($this->request->untrimmed_variable($name,'',true,request_interface::POST),ENT_QUOTES);
						}
						$uploads[$id] = $this->request->raw_variable('upload_' . $id,array(),request_interface::FILES);
					}
				}
				try
				{
					$this->manager->save($action,$file,$ids,$this->request->variable('state','',false,request_interface::POST),$input,$uploads,
						$this->request->variable('confirm','',false,request_interface::POST) === '1');
					redirect($this->link('file',$file) . '&amp;saved=1');
				}
				catch (\DomainException $e)
				{
					if (!in_array($e->getMessage(),array('ACP_PLUS_DL_MIRROR_LOCATION_INVALID','ACP_PLUS_DL_MIRROR_SOURCE_INVALID',
						'ACP_PLUS_DL_MIRROR_CONFIRM','PLUS_DL_BAD_UPLOAD','PLUS_DL_BAD_EXTENSION','PLUS_DL_BAD_SIZE','PLUS_DL_STORAGE_UNAVAILABLE'),true)) { throw $e; }
					$error = $this->lang->lang($e->getMessage());
				}
			}
			$view = $this->manager->review($action,$file,$ids);
			foreach ($view['rows'] as $id=>$row)
			{
				$details = $this->describe($id,$row);
				$values = $input[$id] ?? array('location'=>$details['NAME'],'source'=>$row ? 'keep' : 'local','url'=>'');
				$this->template->assign_block_vars('dl_selected',$details + array('LOCATION'=>$values['location'],'SOURCE'=>$values['source'],'URL'=>$values['url']));
			}
			$this->template->assign_vars(array('DL_ACTION'=>$action,'DL_LABEL'=>$this->lang->lang('ACP_PLUS_DL_MIRROR_' . strtoupper($action)),
				'DL_TITLE'=>access::plain($view['file']['title']),'DL_STATE'=>$view['state'],'DL_ERROR'=>$error,
				'U_DL_SAVE'=>$this->link($action,$file,$ids),'U_BACK'=>$this->link('file',$file)));
			add_form_key($key);
		}
		catch (\DomainException $e)
		{ trigger_error($this->lang->lang($e->getMessage()) . adm_back_link($file ? $this->link('file',$file) : $this->u_action),E_USER_WARNING); }
	}
}
