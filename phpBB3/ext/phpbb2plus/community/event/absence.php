<?php
namespace phpbb2plus\community\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class absence implements EventSubscriberInterface
{
	private $state;
	private $config;
	private $user;
	private $auth;
	private $template;
	private $language;
	private $helper;
	private $root;
	private $ext;
	private $contact;
	private $profile;

	public function __construct($state,$config,$user,$auth,$template,$language,$helper,$root,$ext)
	{
		$this->state=$state; $this->config=$config; $this->user=$user; $this->auth=$auth; $this->template=$template;
		$this->language=$language; $this->helper=$helper; $this->root=$root; $this->ext=$ext;
	}

	public static function getSubscribedEvents()
	{
		return array('core.page_header'=>'header','core.ucp_profile_modify_profile_info'=>'own','core.acp_users_modify_profile'=>'manage',
			'core.memberlist_view_profile'=>'profile','core.memberlist_modify_view_profile_template_vars'=>array('profile_links',-20),
			'core.memberlist_modify_memberrow'=>'member','core.viewtopic_post_rowset_data'=>'snapshot','core.viewtopic_modify_post_row'=>'post',
			'core.acp_board_config_edit_add'=>'board','core.delete_user_after'=>'deleted','core.permissions'=>'permissions');
	}
	private function lang()
	{
		$this->language->add_lang('absence','phpbb2plus/community');
	}
	private function contact()
	{
		if ($this->contact===null) { $this->contact=$this->state->may_contact(); }
		return $this->contact;
	}
	private function label($row)
	{
		$this->lang();
		return $this->language->lang($this->state->member() ? $this->state::label($row['plus_away_mode']) : 'PLUS_AWAY');
	}
	public function header()
	{
		if (empty($this->user->data['plus_away']) || !$this->state->member() || !empty($this->user->data['user_perm_from'])) { return; }
		$this->lang();
		$this->template->assign_var('U_PLUS_AWAY_RETURN',$this->helper->route('phpbb2plus_community_absence'));
	}
	public function own()
	{
		if (empty($this->user->data['user_perm_from']) && ($this->user->data['plus_away'] || $this->state->can_set($this->user->data)))
		{
			$this->lang();
			$this->template->assign_var('U_PLUS_AWAY_EDIT',$this->helper->route('phpbb2plus_community_absence'));
		}
	}
	public function manage($event)
	{
		$row=$event['user_row'];
		if (!empty($this->user->data['session_admin']) && empty($this->user->data['user_perm_from']) && $this->auth->acl_get('a_user') &&
			($row['plus_away'] || $this->state->can_set($row)))
		{
			$this->lang();
			$this->template->assign_var('U_PLUS_AWAY_EDIT',append_sid($this->root . 'adm/index.' . $this->ext,
				'i=-phpbb2plus-community-acp-absence_module&amp;mode=edit&amp;u=' . (int)$row['user_id'],true,$this->user->session_id));
		}
	}
	public function profile($event)
	{
		$row=$event['member'];
		$this->profile=null;
		if (!empty($row['plus_away']) && $this->state::account($row))
		{
			$this->profile=$row;
			$this->template->assign_vars(array('PLUS_AWAY_LABEL'=>$this->label($row),
				'PLUS_AWAY_NOTE'=>$this->state->member() ? $this->state->note($row['user_id']) : ''));
		}
	}
	public function profile_links($event)
	{
		if ($this->profile && !$this->contact())
		{
			$vars=$event['template_ary']; $vars['U_PM']=$vars['U_EMAIL']=''; $event['template_ary']=$vars;
		}
	}
	public function member($event)
	{
		$row=$event['row'];
		if (empty($row['plus_away']) || !$this->state::account($row)) { return; }
		$vars=$event['memberrow']; $vars['PLUS_AWAY_LABEL']=$this->label($row);
		if (!$this->contact()) { $vars['U_PM']=$vars['U_EMAIL']=''; }
		$event['memberrow']=$vars;
	}
	public function snapshot($event)
	{
		$row=$event['row'];
		if (empty($row['plus_away']) || !$this->state::account($row)) { return; }
		$data=$event['rowset_data']; $data['plus_away']=1; $data['plus_away_mode']=$row['plus_away_mode']; $event['rowset_data']=$data;
	}
	public function post($event)
	{
		$row=$event['row'];
		if (empty($row['plus_away'])) { return; }
		$vars=$event['post_row'];
		$vars['PLUS_AWAY_LABEL']=$this->label($row); $vars['S_AWAY_BY_NAME']=(bool)$this->config['plus_away_by_name'];
		if (!$this->contact()) { $vars['U_PM']=$vars['U_EMAIL']=''; }
		$event['post_row']=$vars;
	}
	public function board($event)
	{
		if ($event['mode']!=='features') { return; }
		$this->lang();
		$fields=array('legend_plus_away'=>'PLUS_AWAY');
		foreach (array('members'=>'PLUS_AWAY_MEMBERS','mod_contact'=>'PLUS_AWAY_MOD_CONTACT','by_name'=>'PLUS_AWAY_BY_NAME') as $key=>$label)
		{ $fields['plus_away_' . $key]=array('lang'=>$label,'validate'=>'bool','type'=>'radio:yes_no','explain'=>true); }
		$display=$event['display_vars']; $ordered=array();
		foreach ($display['vars'] as $key=>$item)
		{
			if ($item==='ACP_SUBMIT_CHANGES') { $ordered+=$fields; }
			$ordered[$key]=$item;
		}
		$display['vars']=$ordered+$fields; $event['display_vars']=$display;
	}
	public function deleted($event) { $this->state->delete_accounts((array)$event['user_ids']); }
	public function permissions($event)
	{
		$this->lang(); $rights=$event['permissions']; $rights['u_plus_away_mod']=array('lang'=>'ACL_U_PLUS_AWAY_MOD','cat'=>'profile');
		$event['permissions']=$rights;
	}
}
