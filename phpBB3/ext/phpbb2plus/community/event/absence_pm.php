<?php
namespace phpbb2plus\community\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/** Apply personal absence only to a member's native compose workflow. System notifications retain their normal delivery. */
class absence_pm implements EventSubscriberInterface
{
	private $state;
	private $db;
	private $user;
	private $auth;
	private $lang;
	private $template;
	private $hide_pm_contact=false;
	private $mode;
	private $message;
	private $address=array();
	private $subject;
	private $sending=false;

	public function __construct($state,$db,$user,$auth,$language,$template)
	{
		$this->state=$state; $this->db=$db; $this->user=$user; $this->auth=$auth; $this->lang=$language; $this->template=$template;
	}
	public static function getSubscribedEvents()
	{
		return array('core.ucp_pm_compose_modify_data'=>'compose','core.message_list_actions'=>array('addresses',-110),
			'core.ucp_pm_compose_modify_parse_after'=>array('validate',-110),'core.submit_pm_before'=>array('begin',-90),
			'core.notification_manager_add_notifications_before'=>array('before_notify',1100),'core.submit_pm_after'=>array('delivered',1100),
			'core.garbage_collection'=>'abort','core.ucp_pm_view_message'=>'view','core.page_footer'=>'contacts');
	}

	public function compose($event)
	{
		$this->mode=$event['action']; $this->message=(int)$event['msg_id']; $this->address=array(); $this->subject=null;
	}

	private function absent($address,$message=0,$current=false)
	{
		$from=USERS_TABLE . ' u'; $filters=array();
		if ($message)
		{
			// An edit keeps the existing delivered audience. The sender's separate outbox row does not receive it.
			$from=PRIVMSGS_TO_TABLE . ' pt JOIN ' . USERS_TABLE . ' u ON u.user_id=pt.user_id';
			$filters[]='pt.msg_id=' . (int)$message . ' AND pt.folder_id NOT IN (' . PRIVMSGS_OUTBOX . ',' . PRIVMSGS_SENTBOX . ')';
		}
		else
		{
			if (!empty($address['u'])) { $filters[]=$this->db->sql_in_set('u.user_id',array_map('intval',array_keys($address['u']))); }
			if (!empty($address['g']))
			{
				$staff=$this->auth->acl_gets('a_','m_') || $this->auth->acl_getf_global('m_');
				$filters[]='(EXISTS (SELECT 1 FROM ' . USER_GROUP_TABLE . ' ug WHERE ug.user_id=u.user_id AND ug.user_pending=0 AND ' .
					$this->db->sql_in_set('ug.group_id',array_map('intval',array_keys($address['g']))) . ')' . ($staff ? '' : ' AND u.user_allow_pm=1') . ')';
			}
		}
		if (!$filters) { return false; }
		$sql="SELECT u.user_id,u.plus_away_mode FROM $from WHERE u.plus_away=1 AND u.user_id<>" . ANONYMOUS .
			' AND u.user_type IN (' . USER_NORMAL . ',' . USER_FOUNDER . ') AND (' . implode(' OR ',$filters) . ')';
		$r=$current ? $this->db->sql_query($sql . ' LIMIT 1 LOCK IN SHARE MODE') : $this->db->sql_query_limit($sql,1);
		$row=$this->db->sql_fetchrow($r); $this->db->sql_freeresult($r);
		return $row && !$this->state->may_contact($current) ? $row : false;
	}

	private function error($away,$direct)
	{
		$this->lang->add_lang('absence_contact','phpbb2plus/community');
		if (!$direct || !$this->state->member() || !$this->auth->acl_get('u_viewprofile')) { return $this->lang->lang('PLUS_AWAY_NO_PM'); }
		$this->lang->add_lang('absence','phpbb2plus/community');
		$message=$this->lang->lang('PLUS_AWAY_PM_DIRECT',$this->lang->lang($this->state::label($away['plus_away_mode'])));
		$note=$this->state->note($away['user_id']);
		return $message . ($note==='' ? '' : '<br />' . nl2br(htmlspecialchars($note,ENT_QUOTES | ENT_SUBSTITUTE,'UTF-8')));
	}

	private function direct()
	{
		return $this->mode!=='edit' && empty($this->address['g']) && count($this->address['u'] ?? array())===1;
	}

	public function addresses($event)
	{
		$this->address=$event['address_list'];
		// At this point native membership, reading, recipient preferences and bans have already filtered direct addresses.
		if ($this->mode && !$event['error'] && $this->direct() && ($away=$this->absent($this->address)))
		{
			$errors=$event['error']; $errors[]=$this->error($away,true); $event['error']=$errors;
		}
	}
	public function validate($event)
	{
		if (!$this->mode || !$event['submit'] || $event['error']) { return; }
		if (!$this->direct() && ($away=$this->absent($this->address,$this->mode==='edit' ? $this->message : 0)))
		{
			$errors=$event['error']; $errors[]=$this->error($away,$this->direct()); $event['error']=$errors; return;
		}
		$this->subject=$event['subject'];
	}
	public function begin($event)
	{
		if ($this->subject===null || $this->subject!==$event['subject'] || $this->mode!==$event['mode'] ||
			(int)$event['data']['from_user_id']!==(int)$this->user->data['user_id']) { return; }
		$this->subject=null;
		$this->db->sql_transaction('begin'); $this->sending=true;
	}
	public function before_notify($event)
	{
		if ($event['notification_type_name']==='notification.type.pm') { $this->finish($event['data']); }
	}
	public function delivered($event) { $this->finish($event['pm_data']); }
	private function finish($data)
	{
		if (!$this->sending || (int)$data['from_user_id']!==(int)$this->user->data['user_id']) { return; }
		try
		{
			// Check native delivery rows, including approved group and blind recipients, before any notification leaves the transaction.
			if ($away=$this->absent(array(),(int)$data['msg_id'],true)) { $this->reject($away); }
			if (!$this->db->sql_transaction('commit')) { throw new \RuntimeException('Private-message delivery did not commit'); }
			$this->sending=false;
		}
		catch (\Throwable $e) { $this->abort(); throw $e; }
	}
	private function reject($away)
	{
		$this->abort();
		trigger_error($this->error($away,false),E_USER_NOTICE);
		throw new \DomainException('PLUS_AWAY_NO_PM');
	}
	public function abort()
	{
		if ($this->sending && $this->db->get_transaction()) { $this->db->sql_transaction('rollback'); }
		$this->sending=false;
	}
	public function view($event)
	{
		$who=$event['user_info'];
		if (empty($who['plus_away']) || !$this->state::account($who)) { return; }
		$this->lang->add_lang('absence','phpbb2plus/community');
		$data=$event['msg_data']; $data['PLUS_AWAY_PM_LABEL']=$this->lang->lang($this->state->member() ? $this->state::label($who['plus_away_mode']) : 'PLUS_AWAY');
		if (!$this->state->may_contact())
		{
			$this->hide_pm_contact=true;
			$data['U_POST_REPLY_PM']=$data['U_POST_REPLY_ALL']=$data['U_QUOTE']=''; $who['email']=''; $event['user_info']=$who;
		}
		$event['msg_data']=$data;
	}
	public function contacts()
	{
		// phpBB assembles this separate contact list after the PM view event.
		if ($this->hide_pm_contact) { $this->template->alter_block_array('contact',array(),array('ID'=>'pm'),'delete'); }
	}
}
