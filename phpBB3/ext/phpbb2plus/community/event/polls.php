<?php
namespace phpbb2plus\community\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use phpbb2plus\community\service\poll_recovery;
use phpbb\request\request_interface;

class polls implements EventSubscriberInterface
{
	private $db;
	private $user;
	private $prefix;
	private $auth;
	private $request;

	public function __construct($db, $user, $prefix, $auth, $request)
	{
		$this->db = $db;
		$this->user = $user;
		$this->prefix = $prefix;
		$this->auth = $auth;
		$this->request = $request;
	}

	public static function getSubscribedEvents()
	{
		return array('core.viewtopic_modify_poll_data'=>array(array('moderator_vote', PHP_INT_MAX),
			array('converted_guest_vote', 0), array('legacy_results', 0)),
			'core.delete_user_before'=>'remove_members', 'core.delete_topics_before_query'=>'remove_topics');
	}

	public function legacy_results($event)
	{
		// Old forum, portal and AJAX templates all linked here to read the results.
		if ($this->request->variable('vote', '', false, request_interface::GET) === 'viewresult')
		{
			$event['s_display_results'] = true;
		}
	}

	public function moderator_vote($event)
	{
		$topic = $event['topic_data'];
		if ($event['s_can_vote'] || empty($this->user->data['is_registered']) ||
			($topic['topic_status'] != ITEM_LOCKED && $topic['forum_status'] != ITEM_LOCKED)) { return; }

		$forum = (int) $event['forum_id'];
		if (!$this->auth->acl_get('m_lock', $forum) || !$this->auth->acl_get('f_list', $forum) ||
			!$this->auth->acl_get('f_read', $forum) || !$this->auth->acl_get('f_vote', $forum)) { return; }
		if ($topic['poll_length'] && (int) $topic['poll_start'] + (int) $topic['poll_length'] <= time()) { return; }
		$voted = $event['cur_voted_id'];
		if ($voted && (in_array(VOTE_CONVERTED, $voted) || !$topic['poll_vote_change'] || !$this->auth->acl_get('f_votechg', $forum))) { return; }

		// Only lift the topic/forum lock for a qualifying voter. Other listeners
		// can still restrict the ballot, and phpBB handles the form and the vote.
		$event['s_can_vote'] = true;
		$event['s_display_results'] = (bool) $voted || $this->request->variable('view', '') === 'viewpoll';
	}

	public function remove_members($event)
	{
		$ids = array_filter(array_map('intval', array_keys($event['user_rows'])), static function ($id) { return $id > ANONYMOUS; });
		if (!$ids) { return; }
		$this->db->sql_query('UPDATE ' . POLL_VOTES_TABLE . ' SET vote_user_id=' . ANONYMOUS .
			' WHERE poll_option_id=' . VOTE_CONVERTED . ' AND ' . $this->db->sql_in_set('vote_user_id', $ids));
		$this->db->sql_query('UPDATE ' . $this->prefix . 'plus_poll_receipts SET user_id=' . ANONYMOUS .
			' WHERE ' . $this->db->sql_in_set('user_id', $ids));
	}

	public function remove_topics($event)
	{
		$ids = array_map('intval', $event['topic_ids']);
		if ($ids) { poll_recovery::discard($this->db, $this->prefix, $this->db->sql_in_set('h.topic_id', $ids)); }
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
