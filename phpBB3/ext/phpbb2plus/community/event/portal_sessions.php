<?php
namespace phpbb2plus\community\event;

use phpbb2plus\community\profile\visits;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class portal_sessions implements EventSubscriberInterface
{
	private $db;
	private $config;
	public function __construct($db, $config) { $this->db = $db; $this->config = $config; }
	public static function getSubscribedEvents() { return array('core.session_create_after'=>'remember'); }

	public function remember($event)
	{
		$session = $event['session_data'];
		$id = (int) $session['session_user_id'];
		if ($id <= ANONYMOUS || !empty($session['session_admin'])) { return; } // ACP reauthentication continues the same visit.
		$stamp = (int) $session['session_start'];
		$hidden = empty($session['session_viewonline']) ? 1 : 0;
		$count = !empty($this->config['plus_profile_visits_ready'])
			? 'plus_visit_logins=' . visits::increment('plus_visit_logins', '1') . ',' : '';
		$this->db->sql_query('UPDATE ' . USERS_TABLE . " SET $count
			plus_login_hidden=CASE WHEN plus_last_login<=$stamp THEN $hidden ELSE plus_login_hidden END,
			plus_last_login=CASE WHEN plus_last_login<$stamp THEN $stamp ELSE plus_last_login END
			WHERE user_id=$id AND user_type IN (" . USER_NORMAL . ',' . USER_FOUNDER . ')');
	}
}
