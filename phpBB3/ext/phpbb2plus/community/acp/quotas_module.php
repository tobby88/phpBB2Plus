<?php
namespace phpbb2plus\community\acp;

use phpbb\request\request_interface;
use phpbb2plus\community\attachment\management;

class quotas_module
{
	public $u_action;
	public $tpl_name;
	public $page_title;

	private $request;
	private $template;
	private $language;

	private function url($section,$id = 0)
	{
		return $this->u_action . '&amp;section=' . $section . '&amp;quota_id=' . (int) $id;
	}

	private function number($key)
	{
		$value = $this->request->variable($key,'',false,request_interface::POST);
		if (!preg_match('/^[0-9]{1,8}$/D',$value) || (int) $value > 16777215) { throw new \DomainException('PLUS_QUOTA_INVALID'); }
		return (int) $value;
	}

	private function values($section)
	{
		if ($section === 'level')
		{
			$label = trim($this->request->variable('label','',true,request_interface::POST));
			$plain = htmlspecialchars_decode($label,ENT_QUOTES);
			if ($plain === '' || utf8_strlen($plain) > 100 || utf8_strlen($label) > 255 || preg_match('/[\x00-\x1F\x7F]/',$plain))
			{ throw new \DomainException('PLUS_QUOTA_INVALID'); }
			return array('label'=>$label,'bytes'=>management::bytes($this->request->variable('bytes','',false,request_interface::POST)));
		}
		$values = array(1=>$this->number('post_level'),2=>$this->number('pm_level'));
		if ($section === 'settings')
		{
			$values['enabled'] = (int) $this->request->is_set_post('enabled');
			$values['bytes'] = management::bytes($this->request->variable('base_pm','',false,request_interface::POST));
		}
		return $values;
	}

	public function main($module,$mode)
	{
		global $phpbb_container;
		$this->request = $phpbb_container->get('request');
		$this->template = $phpbb_container->get('template');
		$this->language = $phpbb_container->get('language');
		$this->language->add_lang('info_acp_quotas','phpbb2plus/community');
		$auth = $phpbb_container->get('auth');
		$store = $phpbb_container->get('phpbb2plus.community.quota_management');
		$group_helper = $phpbb_container->get('group_helper');
		$this->tpl_name = 'acp_plus_quotas';
		$this->page_title = 'ACP_PLUS_ATTACH_QUOTAS';
		$section = $this->request->variable('section','settings',false,request_interface::GET);
		$id = max(0,$this->request->variable('quota_id',0,false,request_interface::GET));
		try
		{
			if (!in_array($section,array('settings','level','member','group'),true)) { throw new \DomainException('PLUS_QUOTA_INVALID'); }
			$lookup = $section === 'settings' && ($this->request->is_set_post('find_member') || $this->request->is_set_post('find_group'));
			if ($lookup) { $section = $this->request->is_set_post('find_member') ? 'member' : 'group'; }
			$principal = in_array($section,array('member','group'),true);
			if ($principal && !$auth->acl_get($section === 'member' ? 'a_user' : 'a_group')) { throw new \DomainException('NO_AUTH_OPERATION'); }
			if ($lookup)
			{
				$id = $section === 'member' ? $store->find_member($this->request->variable('username','',true,request_interface::POST)) :
					$this->request->variable('group_id',0,false,request_interface::POST);
			}
			$action = $this->url($section,$id);
			$form = 'plus_attachment_quota_' . $section . '_' . $id;
			add_form_key($form);
			$settings = $store->settings();
			$levels = $store->levels();
			$selected = array(1=>(int) $settings['plus_attach_default_post'],2=>(int) $settings['plus_attach_default_pm']);
			$target = '';
			if ($section === 'level' && $id)
			{
				if (!isset($levels[$id])) { throw new \DomainException('PLUS_QUOTA_STALE'); }
				$target = htmlspecialchars_decode($levels[$id]['label'],ENT_QUOTES);
			}
			if ($principal)
			{
				$row = $store->principal($section,$id);
				$target = htmlspecialchars_decode(isset($row['group_type']) && (int) $row['group_type'] === GROUP_SPECIAL ?
					$group_helper->get_name($row['name']) : $row['name'],ENT_QUOTES);
				$selected = $store->assignments($section,$id);
			}
			$remove = !$lookup && $section === 'level' && $id && $this->request->is_set_post('remove');
			if (!$lookup && ($remove || $this->request->is_set_post('save')))
			{
				$revision = $this->request->variable('revision','',false,request_interface::POST);
				if (!$remove || !confirm_box(true))
				{
					if (!check_form_key($form)) { throw new \DomainException('FORM_INVALID'); }
					if ($remove)
					{
						confirm_box(false,'PLUS_QUOTA_DELETE',build_hidden_fields(array('remove'=>1,'revision'=>$revision)));
						return;
					}
				}
				$values = $remove ? array() : $this->values($section);
				$store->save($section,$id,$revision,$values,$remove);
				$user = $phpbb_container->get('user');
				$log = 'LOG_PLUS_QUOTA_' . ($remove ? 'REMOVE' : strtoupper($section));
				$name = $section === 'level' && !$remove ? $values['label'] : utf8_htmlspecialchars($target);
				$phpbb_container->get('log')->add('admin',$user->data['user_id'],$user->ip,$log,false,$name === '' ? array() : array($name));
				trigger_error($this->language->lang('CONFIG_UPDATED') . adm_back_link($principal ? $action : $this->u_action));
				return;
			}
			foreach ($levels as $key=>$level)
			{
				$this->template->assign_block_vars('levels',array('ID'=>$key,'NAME'=>htmlspecialchars_decode($level['label'],ENT_QUOTES),
					'BYTES'=>$level['bytes'],'URL'=>$this->url('level',$key)));
			}
			if ($section === 'settings' && $auth->acl_get('a_group'))
			{
				foreach ($store->groups() as $group)
				{
					$name = (int) $group['group_type'] === GROUP_SPECIAL ? $group_helper->get_name($group['group_name']) : $group['group_name'];
					$this->template->assign_block_vars('groups',array('ID'=>(int) $group['group_id'],'NAME'=>htmlspecialchars_decode($name,ENT_QUOTES)));
				}
			}
			$this->template->assign_vars(array('SECTION'=>$section,'QUOTA_ID'=>$id,'TARGET'=>$target,'REVISION'=>$settings['plus_attach_revision'],
				'ENABLED'=>(bool) $settings['plus_attach_quotas'],'BASE_PM'=>$settings['plus_attach_base_pm'],
				'BYTES'=>$section === 'level' && $id ? $levels[$id]['bytes'] : '0',
				'POST_LEVEL'=>$selected[1],'PM_LEVEL'=>$selected[2],
				'MISSING_POST'=>$selected[1] && !isset($levels[$selected[1]]),'MISSING_PM'=>$selected[2] && !isset($levels[$selected[2]]),
				'S_USERS'=>$auth->acl_get('a_user'),'S_GROUPS'=>$auth->acl_get('a_group'),
				'U_LIST'=>$this->u_action,'U_NEW'=>$this->url('level'),'U_SAVE'=>$action));
		}
		catch (\DomainException $e) { trigger_error($e->getMessage(),E_USER_WARNING); }
	}
}
