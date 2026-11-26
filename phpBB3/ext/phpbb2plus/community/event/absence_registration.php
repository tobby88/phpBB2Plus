<?php
namespace phpbb2plus\community\event;

use phpbb\request\request_interface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class absence_registration implements EventSubscriberInterface
{
	private $state;
	private $db;
	private $config;
	private $request;
	private $language;
	private $template;
	private $values;
	private $binding;

	public function __construct($state,$db,$config,$request,$language,$template)
	{
		$this->state=$state; $this->db=$db; $this->config=$config; $this->request=$request; $this->language=$language; $this->template=$template;
	}

	public static function getSubscribedEvents()
	{
		return array('core.ucp_register_data_before'=>'form','core.ucp_register_data_after'=>'validate',
			'core.ucp_register_user_row_after'=>'prepare','core.user_add_after'=>array('created',100),'core.garbage_collection'=>'abort');
	}

	public function form()
	{
		if (!(int)$this->config['plus_away_members']) { return; }
		$this->language->add_lang('absence','phpbb2plus/community');
		// The Plus registration form started as available, with holiday as the first selectable reason.
		$active=$this->request->variable('plus_signup_away','0',false,request_interface::POST);
		$kind=$this->request->variable('plus_signup_kind','1',false,request_interface::POST);
		$note=$this->request->raw_variable('plus_signup_note','',request_interface::POST);
		if ($this->request->is_set_post('plus_signup_away') || $this->request->is_set_post('plus_signup_kind') || $this->request->is_set_post('plus_signup_note'))
		{
			$this->values=array('active'=>$active,'kind'=>$kind,'note'=>$note);
		}
		$this->template->assign_vars(array('S_PLUS_AWAY_SIGNUP'=>true,'PLUS_SIGNUP_AWAY'=>$active,'PLUS_SIGNUP_KIND'=>$kind,
			'PLUS_SIGNUP_NOTE'=>$this->state::valid_note($note) ? $note : ''));
	}

	public function validate($event)
	{
		if ($this->values===null) { return; }
		if (!in_array($this->values['active'],array('0','1'),true) || !in_array($this->values['kind'],array('1','2','3'),true) ||
			!$this->state::valid_note($this->values['note']))
		{
			$errors=$event['error']; $errors[]=$this->language->lang('PLUS_AWAY_INVALID'); $event['error']=$errors;
			$this->values=null;
		}
	}

	public function prepare($event)
	{
		if ($this->values===null) { return; }
		$binding=bin2hex(random_bytes(16));
		$this->db->sql_transaction('begin'); $this->binding=$binding;
		try
		{
			// Recheck the setting at account creation. Ordinary registration can continue when the optional fields have been switched off.
			$r=$this->db->sql_query('SELECT config_value FROM ' . CONFIG_TABLE . " WHERE config_name='plus_away_members' LOCK IN SHARE MODE");
			$enabled=(int)$this->db->sql_fetchfield('config_value'); $this->db->sql_freeresult($r);
			if (!$enabled) { $this->finish(); return; }
			$row=$event['user_row']; $row['plus_away']=(int)$this->values['active']; $row['plus_away_mode']=(int)$this->values['kind'];
			$row['plus_away_revision']=$binding; $event['user_row']=$row;
		}
		catch (\Throwable $e) { $this->abort(); throw $e; }
	}
	public function created($event)
	{
		if ($this->binding===null || $this->binding!==($event['user_row']['plus_away_revision'] ?? null)) { return; }
		try
		{
			// The private note never enters the registration data handed to welcome mail or administrative notifications.
			if ($this->values['note']!=='') { $this->state->store_note((int)$event['user_id'],$this->values['note']); }
			$this->finish();
		}
		catch (\Throwable $e) { $this->abort(); throw $e; }
	}
	private function finish()
	{
		if (!$this->db->sql_transaction('commit')) { throw new \RuntimeException('Registration absence did not commit'); }
		$this->binding=$this->values=null;
	}
	public function abort()
	{
		if ($this->binding!==null && $this->db->get_transaction()) { $this->db->sql_transaction('rollback'); }
		$this->binding=$this->values=null;
	}
}
