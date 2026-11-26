<?php
namespace phpbb2plus\weblinks\acp;

use phpbb\request\request_interface;
use phpbb2plus\weblinks\service\directory;
use phpbb2plus\weblinks\service\management;
use phpbb2plus\weblinks\service\settings;

class management_module
{
	public $u_action;
	public $tpl_name;
	public $page_title;
	private $request;
	private $language;
	private $manager;
	private $template;
	private $auth;
	private $container;

	private function post($name, $default)
	{
		$value = $this->request->variable($name,$default,is_string($default),request_interface::POST);
		return is_string($value) ? htmlspecialchars_decode($value,ENT_QUOTES) : $value;
	}

	private function removal_target($scope)
	{
		$value = $this->request->variable('target','',false,$scope);
		if ($value === '') { return -1; }
		if (!preg_match('/^(0|[1-9][0-9]{0,9})$/D',$value) || (float) $value>4294967295) { throw new \DomainException('WL_BAD_CATEGORY'); }
		return (int) $value;
	}

	public function main($id, $mode)
	{
		global $phpbb_container,$template,$request,$auth;
		$this->container = $phpbb_container;
		$this->template = $template;
		$this->request = $request;
		$this->auth = $auth;
		$this->manager = $phpbb_container->get('phpbb2plus.weblinks.management');
		$this->language = $phpbb_container->get('language');
		$this->language->add_lang('admin','phpbb2plus/weblinks');
		$this->language->add_lang('directory','phpbb2plus/weblinks');
		$this->tpl_name = 'acp_wl_management';
		$this->page_title = 'ACP_PLUS_WL_' . strtoupper($mode);
		parse_str((string) parse_url(html_entity_decode($this->u_action,ENT_QUOTES),PHP_URL_QUERY),$location);
		$this->template->assign_vars(array('WL_HEADING'=>$this->language->lang($this->page_title),'U_WL_ACTION'=>$this->u_action,
			'WL_LOCATION'=>build_hidden_fields(array_intersect_key($location,array_flip(array('i','mode','sid'))))));
		try
		{
			if ($request->is_set_post('operation'))
			{
				if (!check_form_key('plus_wl_' . $mode)) { throw new \DomainException('FORM_INVALID'); }
				$this->mutate($mode,$this->post('operation',''));
				trigger_error($this->language->lang('WL_ADMIN_SAVED') . adm_back_link($this->u_action));
			}
			switch ($mode)
			{
				case 'create': $this->editor(0); break;
				case 'entries': $this->entries(); break;
				case 'categories': $this->categories(); break;
				case 'settings': $this->settings(); break;
				default: throw new \DomainException('NOT_AUTHORISED');
			}
			add_form_key('plus_wl_' . $mode);
		}
		catch (\DomainException $e)
		{
			trigger_error($this->language->lang($e->getMessage()) . adm_back_link($this->u_action),E_USER_WARNING);
		}
	}

	private function mutate($mode, $operation)
	{
		$id = $this->post('entry',0);
		if (($mode === 'create' && !$id && $operation === 'create') || ($mode === 'entries' && $id>0 && $operation === 'edit'))
		{
			$input = array('cat_id'=>$this->post('cat_id',0));
			foreach (array('title','description','destination','logo') as $key) { $input[$key] = $this->post($key,''); }
			$status = null;
			if ($this->request->is_set_post('status'))
			{
				$choice = $this->post('status','');
				if (!in_array($choice,array('0','1','2'),true)) { throw new \DomainException('FORM_INVALID'); }
				$status = (int) $choice;
			}
			$this->manager->save_entry($id,$this->post('revision',0),$input,$status);
		}
		elseif ($mode === 'entries' && $operation === 'remove' && $id>0 && $this->post('confirmed',0) === 1)
		{ $this->manager->delete_entry($id,$this->post('revision',0)); }
		elseif ($mode === 'categories' && in_array($operation,array('create','rename','up','down','remove'),true))
		{
			if ($operation === 'remove' && $this->post('confirmed',0) !== 1) { throw new \DomainException('FORM_INVALID'); }
			$target = $operation === 'remove' ? $this->removal_target(request_interface::POST) : -1;
			$this->manager->save_category($operation,$this->post('cat_id',0),$this->post('name',''),$target,
				$this->post('version',''),$this->post('members',''));
		}
		elseif ($mode === 'settings' && $operation === 'settings')
		{
			$values = array();
			foreach (directory::DEFAULTS as $key=>$unused)
			{
				if ($this->request->is_set_post($key)) { $values[$key] = $this->post($key,''); }
			}
			$this->container->get('phpbb2plus.weblinks.settings')->save($values,$this->post('version',''));
		}
		else { throw new \DomainException('FORM_INVALID'); }
	}

	private function editor($id)
	{
		$right = $id ? 'a_plus_wl_edit' : 'a_plus_wl_create';
		if (!$this->auth->acl_get($right)) { throw new \DomainException('NOT_AUTHORISED'); }
		$row = $id ? $this->manager->entry($id) : array('link_id'=>0,'cat_id'=>0,'title'=>'','description'=>'',
			'destination'=>'','logo'=>'','revision'=>0,'status'=>1);
		foreach (array('title','description','destination','logo') as $key) { $row[$key] = directory::plain($row[$key]); }
		$this->template->assign_vars(array('WL_EDITOR'=>true,'WL_RECORD'=>$row,'WL_EDIT_STATUS'=>!$id || $this->auth->acl_get('a_plus_wl_review')));
		$categories = $this->manager->categories();
		$this->template->assign_var('WL_MISSING_CATEGORY',$id && !isset(array_column($categories,'title','cat_id')[$row['cat_id']]));
		foreach ($categories as $cat)
		{
			$this->template->assign_block_vars('categories',array('ID'=>(int) $cat['cat_id'],'TITLE'=>directory::plain($cat['title'])));
		}
	}

	private function entries()
	{
		global $user;
		$id = $this->request->variable('entry',0);
		$action = $this->request->variable('action','list');
		$may_edit = $this->auth->acl_get('a_plus_wl_edit');
		$may_delete = $this->auth->acl_get('a_plus_wl_delete');
		if ($id>0 && $action === 'edit') { $this->editor($id); return; }
		if ($id>0 && $action === 'remove' && $may_delete)
		{
			$row = $this->manager->entry($id);
			$this->template->assign_vars(array('WL_REMOVE'=>true,'WL_ENTRY'=>$id,'WL_REVISION'=>(int) $row['revision'],
				'WL_QUESTION'=>$this->language->lang('WL_ADMIN_DELETE_ENTRY',directory::plain($row['title']))));
			return;
		}
		if ($action !== 'list' || (!$may_edit && !$may_delete)) { throw new \DomainException('NOT_AUTHORISED'); }
		$query = utf8_substr(htmlspecialchars_decode($this->request->variable('q','',true),ENT_QUOTES),0,100);
		list($rows,$total,$start) = $this->manager->entries($query,$this->request->variable('start',0));
		foreach ($rows as $row)
		{
			$url = $this->u_action . '&amp;entry=' . (int) $row['link_id'];
			$owner = (int) $row['user_id']>ANONYMOUS && $row['username'] !== null ? directory::plain($row['username']) :
				$this->language->lang((int) $row['source_user']>0 ? 'WL_FORMER' : 'GUEST');
			$this->template->assign_block_vars('entries',array('ID'=>(int) $row['link_id'],'TITLE'=>directory::plain($row['title']),
				'DESCRIPTION'=>directory::plain($row['description']),'CATEGORY'=>directory::plain($row['category']),
				'DEST'=>directory::plain($row['destination']),'OWNER'=>$may_edit ? $owner : '','IP'=>$may_edit ? $row['submitter_ip'] : '',
				'DATE'=>$user->format_date((int) $row['joined']),
				'HITS'=>(int) $row['hits'],'STATUS'=>$this->language->lang(array(0=>'WL_PENDING',1=>'WL_APPROVED',2=>'WL_DECLINED')[(int) $row['status']]),
				'EDIT'=>$may_edit ? $url . '&amp;action=edit' : '','REMOVE'=>$may_delete ? $url . '&amp;action=remove' : ''));
		}
		$this->template->assign_vars(array('WL_LIST'=>true,'WL_QUERY'=>$query));
		$this->container->get('pagination')->generate_template_pagination($this->u_action . '&amp;q=' . rawurlencode($query),
			'pagination','start',$total,10,$start);
	}

	private function categories()
	{
		$rows = $this->manager->categories();
		$id = $this->request->variable('cat_id',0);
		$action = $this->request->variable('action','list');
		$names = array_column($rows,'title','cat_id');
		if ($action !== 'list' && !isset($names[$id])) { throw new \DomainException('WL_BAD_CATEGORY'); }
		$this->template->assign_vars(array('WL_VERSION'=>management::category_version($rows),'WL_CAT'=>$id));
		if ($action === 'remove')
		{
			list($count,$members) = $this->manager->membership($id);
			$target = $this->removal_target(request_interface::GET);
			$title = directory::plain($names[$id]);
			if ($target === -1)
			{
				foreach ($names as $key=>$name)
				{
					if ($id !== (int) $key) { $this->template->assign_block_vars('targets',array('ID'=>(int) $key,'TITLE'=>directory::plain($name))); }
				}
				$this->template->assign_vars(array('WL_TARGET_FORM'=>true,'WL_CAT_TITLE'=>$title,'WL_COUNT'=>$count));
			}
			else
			{
				if ($target === $id || ($target !== 0 && !isset($names[$target]))) { throw new \DomainException('WL_BAD_CATEGORY'); }
				$question = $target ? $this->language->lang('WL_ADMIN_MOVE_CATEGORY',$title,$count,directory::plain($names[$target])) :
					$this->language->lang('WL_ADMIN_DELETE_CATEGORY',$title,$count);
				$this->template->assign_vars(array('WL_REMOVE_CAT'=>true,'WL_QUESTION'=>$question,'WL_TARGET'=>$target,'WL_MEMBERS'=>$members));
			}
			return;
		}
		if ($action !== 'list') { throw new \DomainException('FORM_INVALID'); }
		foreach ($names as $key=>$name)
		{
			$this->template->assign_block_vars('categories',array('ID'=>(int) $key,'TITLE'=>directory::plain($name),
				'REMOVE'=>$this->u_action . '&amp;action=remove&amp;cat_id=' . (int) $key));
		}
		$this->template->assign_var('WL_CATEGORIES',true);
	}

	private function settings()
	{
		$values = $this->container->get('phpbb2plus.weblinks.settings')->read();
		$this->template->assign_vars(array('WL_SETTINGS'=>true,'WL_VERSION'=>settings::version($values),'WL_REPAIR'=>!$values['inputs_valid']));
		foreach (directory::DEFAULTS as $key=>$default)
		{
			$range = settings::RANGES[$key] ?? null;
			$this->template->assign_block_vars('options',array('KEY'=>$key,'LABEL'=>$this->language->lang('WL_PREF_' . strtoupper($key)),
				'VALUE'=>directory::plain($values[$key]),'BOOLEAN'=>!$range && is_int($default),'NUMBER'=>(bool) $range,
				'MIN'=>$range[0] ?? 0,'MAX'=>$range[1] ?? 0));
		}
	}
}
