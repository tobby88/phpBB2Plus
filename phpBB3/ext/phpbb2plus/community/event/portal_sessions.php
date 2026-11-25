<?php
namespace phpbb2plus\community\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class portal_sessions implements EventSubscriberInterface
{
	private $db;
	public function __construct($db) { $this->db = $db; }
	public static function getSubscribedEvents() { return array('core.session_create_after'=>'remember'); }

	public function remember($event)
	{
		$session = $event['session_data'];
		$id = (int) $session['session_user_id'];
		if ($id <= ANONYMOUS) { return; }
		$stamp = (int) $session['session_start'];
		$hidden = empty($session['session_viewonline']) ? 1 : 0;
		$this->db->sql_query('UPDATE ' . USERS_TABLE . ' SET plus_last_login=' . $stamp . ',plus_login_hidden=' . $hidden .
			' WHERE user_id=' . $id . ' AND plus_last_login<=' . $stamp . ' AND user_type IN (' . USER_NORMAL . ',' . USER_FOUNDER . ')');
	}
}
