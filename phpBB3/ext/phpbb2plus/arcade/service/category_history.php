<?php
namespace phpbb2plus\arcade\service;

/** Rebuild the same stored counts and last-play snapshots as a source category change. */
class category_history
{
	private $db;
	private $prefix;
	private $events;

	public static function ensure_global($db, $prefix)
	{
		$table = $prefix . 'categories';
		$db->sql_transaction('begin');
		try
		{
			$db->sql_query("INSERT INTO $table (cat_id,cat_type,cat_parent,cat_order,cat_name)
				SELECT -1,'p',0,0,'All games' WHERE NOT EXISTS (SELECT 1 FROM $table WHERE cat_id=-1)");
			if ($db->sql_affectedrows())
			{
				$result = $db->sql_query("SELECT * FROM $table WHERE cat_id=-1");
				$global = $db->sql_fetchrow($result);
				$db->sql_freeresult($result);
				// Some old catalogues lack this bookkeeping row. Recover only its
				// counts and attributable last play, without rewriting the source categories.
				(new self($db, $prefix))->recalculate(array(-1 => $global), array());
			}
			$db->sql_transaction('commit');
		}
		catch (\Throwable $e)
		{
			if ($db->get_transaction()) { $db->sql_transaction('rollback'); }
			throw $e;
		}
	}

	public function __construct($db, $prefix)
	{
		$this->db = $db;
		$this->prefix = $prefix;
		// The source records names. Resolve with the database's actual collation,
		// before removing any category. Duplicate names cannot identify a game.
		$cats = $prefix . 'categories';
		$games = $prefix . 'games';
		$result = $db->sql_query("SELECT c.cat_id AS source,c.last_player,c.last_user_id,c.last_time,c.last_game,g.game_id
			FROM $cats c JOIN $games g ON g.game_id>0 AND g.game_name=c.last_game
			WHERE c.cat_type IN ('p','s') AND c.last_time>0 AND c.last_game<>'' AND (c.last_player=-1 OR c.last_player>0 OR c.last_user_id>" . ANONYMOUS . ")
			AND NOT EXISTS (SELECT 1 FROM $games other WHERE other.game_id>0 AND other.game_name=g.game_name AND other.game_id<>g.game_id)
			ORDER BY c.cat_id");
		$this->events = $db->sql_fetchrowset($result);
		$db->sql_freeresult($result);
	}

	private function counts($nodes)
	{
		$games = $this->prefix . 'games';
		$counts = array();
		foreach ($nodes as $id => $row) { $counts[$id] = array('total_games'=>0, 'total_played'=>'0'); }
		$result = $this->db->sql_query("SELECT cat_id,COUNT(CASE WHEN game_avail=1 THEN 1 END) AS active,COALESCE(SUM(played),0) AS played
			FROM $games WHERE game_id<>0 GROUP BY cat_id");
		while ($group = $this->db->sql_fetchrow($result))
		{
			$counts[-1]['total_games'] += (int) $group['active'];
			$counts[-1]['total_played'] = currency::add($counts[-1]['total_played'], (string) $group['played']);
			$id = (int) $group['cat_id'];
			if ($id <= 0 || !isset($nodes[$id]) || $nodes[$id]['cat_type'] === 'l') { continue; }
			$counts[$id]['total_games'] = (int) $group['active'];
			$counts[$id]['total_played'] = currency::add($counts[$id]['total_played'], (string) $group['played']);
			$parent = (int) $nodes[$id]['cat_parent'];
			if ($parent > 0) { $counts[$parent]['total_played'] = currency::add($counts[$parent]['total_played'], (string) $group['played']); }
		}
		$this->db->sql_freeresult($result);
		return $counts;
	}

	public function recalculate($nodes, $before, $game_counts = null, $moved_game = 0)
	{
		$games = $this->prefix . 'games';
		$counts = $game_counts ?? $this->counts($nodes);
		$history = array();
		if ($this->events)
		{
			$result = $this->db->sql_query("SELECT game_id,cat_id FROM $games WHERE " . $this->db->sql_in_set('game_id', array_unique(array_column($this->events,'game_id'))));
			$locations = array_column($this->db->sql_fetchrowset($result), 'cat_id', 'game_id');
			$this->db->sql_freeresult($result);
			foreach ($this->events as $event)
			{
				if ($moved_game && (int) $event['game_id']===$moved_game && isset($nodes[$event['source']]) && !isset($counts[$event['source']]))
				{
					// A stale historical snapshot outside the old and new branches may also point at this game.
					$counts[$event['source']] = array_intersect_key($nodes[$event['source']],array_flip(array('total_games','total_played')));
				}
				$direct = (int) ($locations[$event['game_id']] ?? 0);
				$targets = array(-1);
				if ($direct > 0 && isset($nodes[$direct]) && $nodes[$direct]['cat_type'] !== 'l')
				{
					$targets[] = $direct;
					if ($nodes[$direct]['cat_type'] === 's') { $targets[] = (int) $nodes[$direct]['cat_parent']; }
				}
				foreach ($targets as $target)
				{
					$time = (int) ($history[$target]['last_time'] ?? 0);
					if ((int) $event['last_time'] > $time || ((int) $event['last_time'] === $time && (int) $event['source'] === $target))
					{ $history[$target] = $event; }
				}
			}
		}
		foreach ($nodes as $id => $row)
		{
			if (!isset($counts[$id])) { continue; }
			$update = $counts[$id];
			if ($row['cat_type'] === 'l' && ($before[$id]['cat_type'] ?? '') === 'l')
			{
				// Existing link visits are independent of game starts.
				$update['total_played'] = $row['total_played'];
			}
			else
			{
				$event = $history[$id] ?? array();
				$update += array('last_game'=>$event['last_game'] ?? null, 'last_time'=>$event['last_time'] ?? null,
					'last_player'=>$event['last_player'] ?? null, 'last_user_id'=>(int) ($event['last_user_id'] ?? 0));
			}
			if ($update['total_games'] > 4294967295 || strlen((string) $update['total_played']) > 20 ||
				(strlen((string) $update['total_played']) === 20 && strcmp($update['total_played'], '18446744073709551615') > 0))
			{ throw new \DomainException('PLUS_ARC_CAT_COUNT'); }
			$changed = array_filter($update, static function ($value, $key) use ($row) {
				return $value === null || $row[$key] === null ? $value !== $row[$key] : (string) $value !== (string) $row[$key];
			}, ARRAY_FILTER_USE_BOTH);
			if ($changed) { $this->db->sql_query('UPDATE ' . $this->prefix . 'categories SET ' . $this->db->sql_build_array('UPDATE', $changed) . " WHERE cat_id=$id"); }
		}
	}
}
