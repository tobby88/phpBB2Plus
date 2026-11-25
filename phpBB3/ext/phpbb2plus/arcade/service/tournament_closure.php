<?php
namespace phpbb2plus\arcade\service;

use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class tournament_closure
{
	private $db;
	private $catalog;
	private $tours;
	private $manager;
	private $prefix;
	private $notices;

	public function __construct($db, $catalog, $tours, $manager, $prefix, $notices)
	{
		$this->db = $db;
		$this->catalog = $catalog;
		$this->tours = $tours;
		$this->manager = $manager;
		$this->prefix = $prefix;
		$this->notices = $notices;
	}

	/** Build the confirmation from the same event, scores and registrations that will be acted on. */
	public function review($id, $operation, $revision)
	{
		if (!$this->catalog->admin()) { throw new AccessDeniedHttpException(); }
		if (!in_array($operation, array('end','delete'), true)) { throw new \DomainException('FORM_INVALID'); }
		$id = (int) $id;
		$event = $this->tours->entry($id);
		if (!hash_equals(tournament_management::revision($event), $revision) || ($operation === 'end' && (int) $event['tour_active'] === 3))
		{ throw new \DomainException('PLUS_ARC_EVENT_CHANGED'); }
		$p = $this->prefix;
		$hash = hash_init('sha256');
		hash_update($hash, serialize(array($operation,$revision,(int) $event['players'])));
		$wins = array();
		$result = $this->db->sql_query("SELECT row_id,game_name,score_units,top_player,winner_id FROM {$p}tour_games WHERE tour_id=$id ORDER BY row_id");
		while ($row = $this->db->sql_fetchrow($result))
		{
			hash_update($hash, serialize($row));
			$source = (int) $row['top_player'];
			if ($row['score_units'] === null || $source <= 0) { continue; }
			$owner = (int) $row['winner_id'];
			$key = $source . ':' . $owner;
			if (!isset($wins[$key])) { $wins[$key] = array('source'=>$source, 'owner'=>$owner, 'count'=>0); }
			$wins[$key]['count']++;
		}
		$this->db->sql_freeresult($result);
		$winner = null;
		$draw = false;
		if ($operation === 'end')
		{
			foreach ($wins as $record)
			{
				if (!$winner || $record['count'] > $winner['count']) { $winner = $record; $draw = false; }
				elseif ($record['count'] === $winner['count']) { $draw = true; }
			}
			if (!$winner) { throw new \DomainException('PLUS_ARC_EVENT_UNSCORED'); }
			if ($draw) { throw new \DomainException('PLUS_ARC_EVENT_DRAW'); }
			$result = $this->db->sql_query('SELECT username FROM ' . USERS_TABLE . ' WHERE user_id=' . (int) $winner['owner'] . ' AND user_id>' . ANONYMOUS);
			$winner['name'] = $this->db->sql_fetchfield('username');
			$this->db->sql_freeresult($result);
		}
		else
		{
			// Compare personal progress on permanent removal, even when the leader has not changed.
			$result = $this->db->sql_query("SELECT p.row_id,p.owner_id,p.last_played_time,s.name_key,s.played,s.score_units
				FROM {$p}tour_plays p LEFT JOIN {$p}turns s ON s.participant_id=p.row_id WHERE p.tour_id=$id ORDER BY p.row_id,s.name_key");
			while ($row = $this->db->sql_fetchrow($result)) { hash_update($hash, serialize($row)); }
			$this->db->sql_freeresult($result);
		}
		return array('event'=>$event, 'winner'=>$winner, 'snapshot'=>hash_final($hash));
	}

	/** The shared Arcade transaction also serialises submissions from an open game window. */
	public function apply($id, $operation, $revision, $snapshot)
	{
		$id = (int) $id;
		$review = $this->review($id, $operation, $revision);
		if (!hash_equals($review['snapshot'], $snapshot)) { throw new \DomainException('PLUS_ARC_EVENT_CHANGED'); }
		$p = $this->prefix;
		$this->db->sql_query("DELETE FROM {$p}launches WHERE tour_id=$id");
		$this->db->sql_query("DELETE FROM {$p}invites WHERE tour_id=$id");
		if ($operation === 'end')
		{
			$set = array('tour_active'=>3, 'end_date'=>time(), 'champion'=>$review['winner']['source'], 'winner_id'=>$review['winner']['owner']);
			$this->db->sql_query("UPDATE {$p}tours SET " . $this->db->sql_build_array('UPDATE', $set) . " WHERE tour_id=$id");
			$this->notices->queue($id, $this->tours->identity()['owner_id']);
		}
		else
		{
			$this->db->sql_query("DELETE FROM {$p}turns WHERE participant_id IN (SELECT row_id FROM {$p}tour_plays WHERE tour_id=$id)");
			foreach (array('tour_notices','tour_plays','tour_games','tours') as $table) { $this->db->sql_query("DELETE FROM $p$table WHERE tour_id=$id"); }
			// The receipt prevents a browser retry from re-creating a removed event.
		}
		$this->manager->activity($this->tours->identity(), $operation, $id);
	}
}
