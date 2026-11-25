<?php
namespace phpbb2plus\colours\acp;

use phpbb\request\request_interface;
use phpbb2plus\colours\service\palette;

class palette_module
{
	public $u_action;
	public $tpl_name = 'acp_plus_colours';
	public $page_title = 'ACP_PLUS_COLOURS';
	private $admin;
	private $request;
	private $template;
	private $language;
	private $user;
	private $log;
	private $founder;
	private $edit_users;

	public function main($module,$mode)
	{
		global $phpbb_container,$table_prefix;
		$this->admin = new \phpbb2plus\colours\service\administration($phpbb_container->get('dbal.conn'),
			$phpbb_container->get('phpbb2plus.colours.palette'),$table_prefix . 'plus_colours');
		$this->request = $phpbb_container->get('request');
		$this->template = $phpbb_container->get('template');
		$this->language = $phpbb_container->get('language');
		$this->user = $phpbb_container->get('user');
		$this->log = $phpbb_container->get('log');
		$this->language->add_lang('info_acp_colours','phpbb2plus/colours');
		$this->founder = (int) $this->user->data['user_type'] === USER_FOUNDER;
		$this->edit_users = $phpbb_container->get('auth')->acl_get('a_user');
		$this->template->assign_var('U_LIST',$this->u_action);
		$section = $this->request->variable('section','',false,request_interface::GET);
		$id = $this->request->variable('id',0,false,request_interface::GET);
		try
		{
			if ($section === 'entry') { $this->edit_entry($id); return; }
			if ($section === 'member' || $section === 'group') { $this->assignment($section,$id,$phpbb_container->get('group_helper')); return; }
			if ($this->request->is_set_post('lookup'))
			{
				if (!check_form_key('plus_colour_lookup')) { throw new \DomainException('FORM_INVALID'); }
				$member = $this->request->is_set_post('member_name') && $this->request->variable('member_name','',true,request_interface::POST) !== '';
				$id = $member ? $this->admin->member_id($this->request->variable('member_name','',true,request_interface::POST)) :
					$this->request->variable('group_id',0,false,request_interface::POST);
				if (!$id) { throw new \DomainException($member ? 'NO_USER' : 'NO_GROUP'); }
				$this->admin->require_owner(!$member,$id,$this->founder,$this->edit_users);
				redirect($this->u_action . '&amp;section=' . ($member ? 'member' : 'group') . '&amp;id=' . $id);
			}
			add_form_key('plus_colour_lookup');
			$this->template->assign_vars(array('U_NEW'=>$this->u_action . '&amp;section=entry','S_CAN_USERS'=>$this->edit_users,
				'GROUP_OPTIONS'=>group_select_options(false,false,$this->founder ? false : 0)));
			foreach ($this->admin->entries() as $row)
			{
				$this->template->assign_block_vars('entries',array('NAME'=>$row['name'],'COLOUR'=>$row['colour'],
					'POSITION'=>$row['position'],'VISIBLE'=>(bool) $row['in_legend'],'U_EDIT'=>$this->u_action . '&amp;section=entry&amp;id=' . (int) $row['colour_id']));
			}
		}
		catch (\DomainException $e) { trigger_error($this->language->lang($e->getMessage()) . adm_back_link($this->u_action),E_USER_WARNING); }
	}

	private function saved($changed,$label,$message)
	{
		if ($changed) { $this->log->add('admin',$this->user->data['user_id'],$this->user->ip,$message,false,array($label)); }
		trigger_error($this->language->lang('CONFIG_UPDATED') . adm_back_link($this->u_action));
	}

	private function edit_entry($id)
	{
		$row = $id ? $this->admin->entry($id) : array('colour_id'=>0,'name'=>'','colour'=>'','in_legend'=>1,'position'=>0);
		if (!$row) { throw new \DomainException('PLUS_COLOURS_UNKNOWN'); }
		$url = $this->u_action . '&amp;section=entry&amp;id=' . (int) $id;
		$form = 'plus_colour_entry_' . $id;
		add_form_key($form);
		$remove = $id && $this->request->is_set_post('remove');
		if ($remove || $this->request->is_set_post('save'))
		{
			$revision = $this->request->variable('revision','',false,request_interface::POST);
			if (!$remove || !confirm_box(true))
			{
				if (!check_form_key($form)) { throw new \DomainException('FORM_INVALID'); }
				if ($remove)
				{
					confirm_box(false,'PLUS_COLOURS_DELETE',build_hidden_fields(array('remove'=>1,'revision'=>$revision)));
					return;
				}
			}
			$values = array();
			if (!$remove)
			{
				$name = trim($this->request->variable('label','',true,request_interface::POST));
				$input = trim($this->request->variable('colour','',false,request_interface::POST));
				$colour = palette::hex($input);
				$position = $this->request->variable('position',0,false,request_interface::POST);
				if ($name === '' || utf8_strlen(html_entity_decode($name,ENT_QUOTES,'UTF-8')) > 191 || ($input !== '' && $colour === '') ||
					$position < -8388608 || $position > 8388607) { throw new \DomainException('PLUS_COLOURS_INVALID'); }
				$values = array('name'=>$name,'colour'=>$colour,'position'=>$position,'in_legend'=>(int) $this->request->is_set_post('in_legend'));
			}
			$changed = $this->admin->save($id,$revision,$values,$remove);
			$this->saved($changed,$remove ? $row['name'] : $values['name'],$remove ? 'LOG_PLUS_COLOUR_DELETE' : 'LOG_PLUS_COLOUR_EDIT');
		}
		$this->template->assign_vars(array('S_EDITOR'=>true,'S_EXISTING'=>(bool) $id,'U_SAVE'=>$url,'NAME'=>$row['name'],
			'COLOUR'=>$row['colour'] === '' ? '' : '#' . $row['colour'],'POSITION'=>$row['position'],'VISIBLE'=>(bool) $row['in_legend'],
			'REVISION'=>$this->admin->revision($row)));
	}

	private function assignment($section,$id,$groups)
	{
		$group = $section === 'group';
		$row = $this->admin->require_owner($group,$id,$this->founder,$this->edit_users);
		$label = $group ? $groups->get_name($row['name']) : $row['name'];
		$form = 'plus_colour_' . $section . '_' . $id;
		add_form_key($form);
		if ($this->request->is_set_post('save'))
		{
			if (!check_form_key($form)) { throw new \DomainException('FORM_INVALID'); }
			$changed = $this->admin->assign($group,$id,$this->founder,$this->edit_users,
				$this->request->variable('previous',-1,false,request_interface::POST),$this->request->variable('selection',-1,false,request_interface::POST));
			$this->saved($changed,$label,'LOG_PLUS_COLOUR_ASSIGN');
		}
		$this->template->assign_vars(array('S_ASSIGN'=>true,'OWNER'=>$label,'PREVIOUS'=>(int) $row['plus_colour_id'],
			'U_SAVE'=>$this->u_action . '&amp;section=' . $section . '&amp;id=' . (int) $id));
		foreach ($this->admin->entries() as $entry)
		{
			$this->template->assign_block_vars('choices',array('ID'=>$entry['colour_id'],'NAME'=>$entry['name'],
				'SELECTED'=>(int) $row['plus_colour_id']===(int) $entry['colour_id']));
		}
	}
}
