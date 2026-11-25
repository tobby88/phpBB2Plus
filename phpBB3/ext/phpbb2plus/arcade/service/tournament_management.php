<?php
namespace phpbb2plus\arcade\service;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class tournament_management
{
	private $db;
	private $catalog;
	private $tours;
	private $prefix;

	public function __construct($db, $catalog, $tours, $prefix)
	{
		$this->db = $db;
		$this->catalog = $catalog;
		$this->tours = $tours;
		$this->prefix = $prefix;
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
		if (!preg_match('/^[a-f0-9]{32}$/D', $nonce)) { throw new \DomainException('FORM_INVALID'); }
		$name = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $input['name']));
		$description = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $input['description']));
		if ($name === '' || utf8_strlen($name) > 25 || utf8_strlen($description) < 3 || utf8_strlen($description) > 255 ||
			!preg_match('/^[1-9][0-9]{0,4}$/D', $input['players']) || !preg_match('/^[1-9][0-9]{0,4}$/D', $input['turns']) ||
			(int) $input['players'] < 2 || (int) $input['turns'] > 10000 || !in_array($input['reserve'], array('0','1'), true))
		{ throw new \DomainException('PLUS_ARC_PROPOSAL_INVALID'); }
		$values = array('tour_name'=>$name, 'tour_desc'=>$description, 'tour_max_players'=>(int) $input['players'],
			'tour_player_turns'=>(int) $input['turns'], 'block_plays'=>(int) $input['reserve']);
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
		$this->db->sql_query("INSERT INTO {$p}tour_plays " . $this->db->sql_build_array('INSERT', $actor + array('tour_id'=>$id, 'turns_state'=>1)));
		$receipt = array('owner_id'=>$owner, 'submit_key'=>$nonce, 'form_hash'=>$hash, 'tour_id'=>$id);
		$this->db->sql_query("INSERT INTO {$p}proposals " . $this->db->sql_build_array('INSERT', $receipt));
		if ((int) $this->catalog->setting('games_use_log', 0))
		{
			$log = $actor + array('name'=>'GAME', 'log_value'=>'Tournament create id=' . $id, 'recorded_at'=>time());
			$this->db->sql_query("INSERT INTO {$p}log " . $this->db->sql_build_array('INSERT', $log));
		}
		return $id;
	}
}
