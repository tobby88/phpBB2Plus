<?php
namespace phpbb2plus\arcade\service;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class tournament_management
{
	private $db;
	private $catalog;
	private $tours;
	private $prefix;
	private $log;
	private $user;

	public function __construct($db, $catalog, $tours, $prefix, $log, $user)
	{
		$this->db = $db;
		$this->catalog = $catalog;
		$this->tours = $tours;
		$this->prefix = $prefix;
		$this->log = $log;
		$this->user = $user;
	}

	public function limit($name)
	{
		$bounds = settings::GROUPS['tournaments'][$name];
		$value = (string) $this->catalog->setting($name, $bounds[0]);
		return preg_match('/^(?:0|[1-9][0-9]{0,4})$/D', $value) && (int) $value >= $bounds[1] && (int) $value <= $bounds[2] ? (int) $value : 0;
	}

	public function proposals_enabled()
	{
		return $this->tours->may_participate() && $this->limit('games_tournament_user') && $this->limit('games_tournament_players')
			&& $this->limit('games_tournament_games');
	}

	public function room() { return $this->tours->total(false) < $this->limit('games_tournament_max'); }

	public function propose(array $input, $nonce)
	{
		$actor = $this->tours->current_participant();
		if (!$this->proposals_enabled()) { throw new NotFoundHttpException(); }
		return $this->create($this->terms($input, false), $actor, $nonce, true);
	}

	private function terms(array $input, $admin)
	{
		$name = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $input['name']));
		$description = $admin ? trim(str_replace(array("\r\n", "\r"), "\n", $input['description'])) :
			trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $input['description']));
		if ($name === '' || utf8_strlen($name) > 25 || strlen($description) > 65535 ||
			(!$admin && (utf8_strlen($description) < 3 || utf8_strlen($description) > 255)) ||
			!preg_match('/^[1-9][0-9]{0,4}$/D', $input['players']) || !preg_match('/^[1-9][0-9]{0,4}$/D', $input['turns']) ||
			(int) $input['players'] < 2 || (int) $input['turns'] > 10000 || !in_array($input['reserve'], array('0','1'), true))
		{ throw new \DomainException('PLUS_ARC_PROPOSAL_INVALID'); }
		return array('tour_name'=>$name, 'tour_desc'=>$description, 'tour_max_players'=>(int) $input['players'],
			'tour_player_turns'=>(int) $input['turns'], 'block_plays'=>(int) $input['reserve']);
	}

	/** A member's proposal reserves its first seat. Staff may create an event without joining. */
	private function create(array $values, array $actor, $nonce, $register)
	{
		if (!preg_match('/^[a-f0-9]{32}$/D', $nonce)) { throw new \DomainException('FORM_INVALID'); }
		// Staff forms also include tour_active in the digest. Keep the original proposal receipt format.
		$hash = hash('sha256', serialize($values));
		$p = $this->prefix;
		$owner = $actor['owner_id'];
		$result = $this->db->sql_query("SELECT r.form_hash,t.tour_id FROM {$p}proposals r LEFT JOIN {$p}tours t ON t.tour_id=r.tour_id
			WHERE r.owner_id=$owner AND r.submit_key='$nonce'");
		$old = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		if ($old)
		{
			if (!$old['tour_id'] || !hash_equals($old['form_hash'], $hash)) { throw new \DomainException('PLUS_ARC_PROPOSAL_CHANGED'); }
			return (int) $old['tour_id'];
		}
		if ($values['tour_max_players'] > $this->limit('games_tournament_players')) { throw new \DomainException('PLUS_ARC_PROPOSAL_INVALID'); }
		if (!$this->room()) { throw new \DomainException('PLUS_ARC_PROPOSAL_LIMIT'); }
		$values += array('start_id'=>$actor['user_id'], 'founder_id'=>$owner, 'start_date'=>time(), 'tour_active'=>0);
		$this->db->sql_query("INSERT INTO {$p}tours " . $this->db->sql_build_array('INSERT', $values));
		$id = (int) $this->db->sql_nextid();
		if ($register)
		{
			$this->db->sql_query("INSERT INTO {$p}tour_plays " . $this->db->sql_build_array('INSERT', $actor + array('tour_id'=>$id, 'turns_state'=>1)));
		}
		$receipt = array('owner_id'=>$owner, 'submit_key'=>$nonce, 'form_hash'=>$hash, 'tour_id'=>$id);
		$this->db->sql_query("INSERT INTO {$p}proposals " . $this->db->sql_build_array('INSERT', $receipt));
		$this->activity($actor, 'create', $id, !$register);
		return $id;
	}

	public function activity($actor, $action, $id, $admin = true)
	{
		if ($admin)
		{
			$key = 'LOG_PLUS_ARC_TOUR_' . strtoupper(str_replace('-', '_', $action));
			$this->log->add('admin', $actor['owner_id'], $this->user->ip, $key, false, array((int) $id));
		}
		if ((int) $this->catalog->setting('games_use_log', 0))
		{
			$log = $actor + array('name'=>'GAME', 'log_value'=>'Tournament ' . $action . ' id=' . $id, 'recorded_at'=>time());
			$this->db->sql_query('INSERT INTO ' . $this->prefix . 'log ' . $this->db->sql_build_array('INSERT', $log));
		}
	}

	public static function revision(array $entry)
	{
		$fields = array();
		foreach (array('tour_id','tour_name','tour_desc','tour_max_players','tour_player_turns','block_plays','tour_active','end_date','champion') as $key)
		{
			$fields[$key] = $entry[$key] === null ? null : (string) $entry[$key];
		}
		return hash('sha256', serialize($fields));
	}

	public function edit($id, array $input, $revision, $nonce)
	{
		if (!$this->catalog->admin()) { throw new AccessDeniedHttpException(); }
		$values = $this->terms($input, true);
		if (!in_array($input['state'], array('0','1','2'), true)) { throw new \DomainException('PLUS_ARC_PROPOSAL_INVALID'); }
		$values['tour_active'] = (int) $input['state'];
		$actor = $this->tours->identity();
		if (!$id) { return $this->create($values, $actor, $nonce, false); }
		$entry = $this->tours->entry($id);
		if ((int) $entry['tour_active'] === 3 || !hash_equals(self::revision($entry), $revision))
		{ throw new \DomainException('PLUS_ARC_EVENT_CHANGED'); }
		if ($values['tour_max_players'] < (int) $entry['players'] ||
			($values['tour_max_players'] !== (int) $entry['tour_max_players'] && $values['tour_max_players'] > $this->limit('games_tournament_players')))
		{ throw new \DomainException('PLUS_ARC_EVENT_CAPACITY'); }
		$changed = false;
		foreach ($values as $key=>$value) { if ((string) $value !== (string) $entry[$key]) { $changed = true; break; } }
		if ($changed)
		{
			$this->db->sql_query('UPDATE ' . $this->prefix . 'tours SET ' . $this->db->sql_build_array('UPDATE', $values) . ' WHERE tour_id=' . (int) $id);
			$this->activity($actor, 'edit', $id);
		}
		return $id;
	}
}
