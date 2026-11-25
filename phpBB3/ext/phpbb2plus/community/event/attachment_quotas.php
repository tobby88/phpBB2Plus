<?php
namespace phpbb2plus\community\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class attachment_quotas implements EventSubscriberInterface
{
	private $quotas;
	private $user;
	private $delete;
	public function __construct($quotas,$user,$delete)
	{
		$this->quotas = $quotas;
		$this->user = $user;
		$this->delete = $delete;
	}
	public static function getSubscribedEvents()
	{
		return array('core.modify_attachment_data_on_submit'=>'recorded','core.modify_attachment_data_on_upload'=>'recorded','core.session_gc_after'=>'expire',
			'core.delete_user_before'=>'accounts','core.delete_group_after'=>'group');
	}
	public function recorded($event)
	{
		$added = $event['attachment_data'][0] ?? array();
		if (!empty($added['attach_id'])) { $this->quotas->recorded($added['attach_id'],$this->user->data['user_id']); }
	}
	public function expire() { $this->quotas->cleanup($this->delete); }
	public function accounts($event) { $this->quotas->removed_members(array_keys($event['user_rows'])); }
	public function group($event) { $this->quotas->removed_group($event['group_id']); }
}
