<?php
namespace phpbb2plus\community\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/** Keep the compose form usable and enforce the allowance against the native delivery recipients. */
class pm_quotas implements EventSubscriberInterface
{
	private $policy;
	private $db;
	private $user;
	private $auth;
	private $language;
	private $context = array();
	private $sending;

	public function __construct($policy,$db,$user,$auth,$language)
	{
		$this->policy = $policy;
		$this->db = $db;
		$this->user = $user;
		$this->auth = $auth;
		$this->language = $language;
	}

	public static function getSubscribedEvents()
	{
		return array('core.ucp_pm_compose_modify_data'=>'compose',
			'core.message_list_actions'=>array('addresses',-100),
			'core.ucp_pm_compose_modify_parse_after'=>'check_form',
			'core.submit_pm_before'=>array('begin',-100),
			'core.notification_manager_add_notifications_before'=>array('before_notify',1000),
			'core.submit_pm_after'=>array('delivered',1000),
			'core.garbage_collection'=>'abort');
	}

	public function compose($event)
	{
		$this->context = array('mode'=>$event['action'],'message'=>(int) $event['msg_id'],'u'=>(int) $event['to_user_id'],'g'=>(int) $event['to_group_id']);
	}
	public function addresses($event) { $this->context['addresses'] = $event['address_list']; }

	private function drafts($attachments)
	{
		if (!$this->policy->enabled() || !$attachments) { return array(); }
		$ids = array_unique(array_filter(array_map('intval',array_column($attachments,'attach_id'))));
		if (!$ids) { return array(); }
		$r = $this->db->sql_query('SELECT attach_id,filesize FROM ' . ATTACHMENTS_TABLE . ' WHERE is_orphan=1 AND in_message=1 AND poster_id=' .
			(int) $this->user->data['user_id'] . ' AND ' . $this->db->sql_in_set('attach_id',$ids));
		$files = array_column($this->db->sql_fetchrowset($r),'filesize','attach_id');
		$this->db->sql_freeresult($r);
		return $files;
	}

	private function mailbox($message)
	{
		$r = $this->db->sql_query('SELECT DISTINCT user_id FROM ' . PRIVMSGS_TO_TABLE . ' WHERE msg_id=' . (int) $message .
			' AND folder_id NOT IN (' . PRIVMSGS_SENTBOX . ',' . PRIVMSGS_OUTBOX . ')');
		$members = array_column($this->db->sql_fetchrowset($r),'user_id');
		$this->db->sql_freeresult($r);
		return $members;
	}

	private function audience($address, $mode, $message)
	{
		if ($mode === 'edit') { return $this->mailbox($message); }
		$members = array_map('intval',array_keys($address['u'] ?? array()));
		$groups = array_filter(array_map('intval',array_keys($address['g'] ?? array())));
		if ($groups)
		{
			// Use the same approved memberships and PM preferences that native group delivery observes.
			$staff = $this->auth->acl_gets('a_','m_') || $this->auth->acl_getf_global('m_');
			$r = $this->db->sql_query('SELECT DISTINCT ug.user_id FROM ' . USER_GROUP_TABLE . ' ug JOIN ' . USERS_TABLE .
				' u ON u.user_id=ug.user_id WHERE ug.user_pending=0 AND u.user_type IN (' . USER_NORMAL . ',' . USER_FOUNDER . ') AND ' .
				$this->db->sql_in_set('ug.group_id',$groups) . ($staff ? '' : ' AND u.user_allow_pm=1'));
			$members = array_merge($members,array_column($this->db->sql_fetchrowset($r),'user_id'));
			$this->db->sql_freeresult($r);
		}
		return array_unique(array_diff($members,array(0,ANONYMOUS)));
	}

	private function error($sender, $files, $recipients)
	{
		$bytes = array_sum($files);
		$extra = $sender === (int) $this->user->data['user_id'] ? 0 : $bytes;
		if (!$this->policy->pm_fits(array($sender),false,$extra)) { return 'PLUS_ATTACH_SENT_QUOTA'; }
		return $this->policy->pm_fits($recipients,true,$bytes) ? '' : 'PLUS_ATTACH_RECEIVED_QUOTA';
	}

	public function check_form($event)
	{
		if (!$event['submit'] || $event['error'] || !$this->context) { return; }
		$files = $this->drafts($event['message_parser']->attachment_data);
		if (!$files) { return; }
		$address = $this->context['addresses'] ?? array();
		if (!isset($this->context['addresses']))
		{
			foreach (array('u','g') as $type) { if ($this->context[$type]) { $address[$type][$this->context[$type]] = 'to'; } }
		}
		$error = $this->error((int) $this->user->data['user_id'],$files,$this->audience($address,$this->context['mode'],$this->context['message']));
		if ($error)
		{
			$this->language->add_lang('attachment_quotas','phpbb2plus/community');
			$errors = $event['error']; $errors[] = $this->language->lang($error); $event['error'] = $errors;
		}
	}

	public function begin($event)
	{
		$data = $event['data'];
		$attachments = $data['attachment_data'] ?? array();
		if (!$attachments || !$this->drafts($attachments)) { return; }
		$this->db->sql_transaction('begin');
		$this->sending = array();
		try
		{
			// Serialise only deliveries that add PM files. Text-only messages use the native transaction unchanged.
			$r = $this->db->sql_query("SELECT config_value FROM " . CONFIG_TABLE . " WHERE config_name='plus_attach_quotas' FOR UPDATE");
			$this->db->sql_freeresult($r);
			$files = $this->drafts($attachments);
			if (!$files) { $this->finish(); return; }
			$sender = (int) $data['from_user_id'];
			$recipients = $this->audience($data['address_list'] ?? array(),$event['mode'],$data['msg_id'] ?? 0);
			$error = $this->error($sender,$files,$recipients);
			if ($error) { $this->reject($error); }
			$this->sending = array('sender'=>$sender,'ids'=>array_keys($files));
		}
		catch (\Throwable $e) { $this->abort(); throw $e; }
	}

	public function before_notify($event)
	{
		if ($event['notification_type_name'] === 'notification.type.pm') { $this->check_delivery($event['data']); }
	}

	public function delivered($event) { $this->check_delivery($event['pm_data']); }

	private function check_delivery($data)
	{
		if (!$this->sending || (int) $data['from_user_id'] !== $this->sending['sender'] ||
			!array_intersect($this->sending['ids'],array_column($data['attachment_data'] ?? array(),'attach_id'))) { return; }
		try
		{
			// The native transaction has now stored the actual group/BCC deliveries and attached the files.
			// Check those rows before releasing the outer transaction and sending any new notification.
			if (!$this->policy->pm_fits($this->mailbox($data['msg_id']),true,0)) { $this->reject('PLUS_ATTACH_RECEIVED_QUOTA'); }
			$this->finish();
		}
		catch (\Throwable $e) { $this->abort(); throw $e; }
	}

	private function finish()
	{
		if (!$this->db->sql_transaction('commit')) { throw new \RuntimeException('Attachment delivery did not commit'); }
		$this->sending = null;
	}

	private function reject($key)
	{
		$this->abort();
		$this->language->add_lang('attachment_quotas','phpbb2plus/community');
		trigger_error($this->language->lang($key),E_USER_NOTICE);
		// A custom PHP error handler may return to its caller.
		throw new \DomainException($key);
	}

	public function abort()
	{
		if ($this->sending !== null && $this->db->get_transaction()) { $this->db->sql_transaction('rollback'); }
		$this->sending = null;
	}
}
