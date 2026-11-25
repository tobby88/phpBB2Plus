<?php
namespace phpbb2plus\community\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class polls implements EventSubscriberInterface
{
	private $db;
	private $user;

	public function __construct($db, $user)
	{
		$this->db = $db;
		$this->user = $user;
	}

	public static function getSubscribedEvents()
	{
		return array('core.viewtopic_modify_poll_data'=>'converted_guest_vote');
	}

	public function converted_guest_vote($event)
	{
		if (!empty($this->user->data['is_registered']) || !$event['s_can_vote'] || $event['cur_voted_id']) { return; }
		$packed = @inet_pton($this->user->ip);
		if ($packed === false) { return; }
		$addresses = array(inet_ntop($packed));
		if (strlen($packed) === 16)
		{
			// The Plus voter table kept only the first four bytes of the hash for IPv6.
			$addresses[] = inet_ntop(substr(hash('sha256', $packed, true), 0, 4));
		}
		$result = $this->db->sql_query_limit('SELECT topic_id FROM ' . POLL_VOTES_TABLE . ' WHERE topic_id=' . (int) $event['topic_id']
			. ' AND vote_user_id=' . ANONYMOUS . ' AND poll_option_id=' . VOTE_CONVERTED
			. ' AND ' . $this->db->sql_in_set('vote_user_ip', $addresses), 1);
		$voted = $this->db->sql_fetchfield('topic_id') !== false;
		$this->db->sql_freeresult($result);
		if ($voted)
		{
			$event['cur_voted_id'] = array(VOTE_CONVERTED);
			$event['s_can_vote'] = false;
			$event['s_display_results'] = true;
		}
	}
}
