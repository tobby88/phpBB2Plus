<?php
namespace phpbb2plus\arcade\service;

class tournament_turns
{
	/** Convert the old per-player game array without ever executing source-board code. */
	public static function decode($raw)
	{
		if ($raw === null || $raw === '' || $raw === 'a:0:{}') { return array(); }
		if (!is_string($raw) || strlen($raw) > 65535) { return null; }
		$options = array('allowed_classes'=>false);
		if (PHP_VERSION_ID >= 70400) { $options['max_depth'] = 8; }
		$entries = @unserialize($raw, $options);
		if (!is_array($entries) && strpos($raw, '\\') !== false) { $entries = @unserialize(stripslashes($raw), $options); }
		if (!is_array($entries) || !$entries) { return null; }
		$games = array();
		foreach ($entries as $entry)
		{
			if (!is_array($entry) || !isset($entry['game_name']) || !is_string($entry['game_name'])) { return null; }
			$name = $entry['game_name'];
			if ($name === '' || !preg_match('//u', $name) || utf8_strlen($name) > 50) { return null; }
			$key = hash('sha256', $name);
			$played = $entry['played'] ?? 0;
			if (isset($games[$key]) || !(is_int($played) || is_string($played)) || !preg_match('/^\d{1,10}$/D', (string) $played)
				|| (float) $played > 2147483647) { return null; }
			$units = null;
			if (isset($entry['score']))
			{
				if (!is_numeric($entry['score'])) { return null; }
				try { $units = is_float($entry['score']) ? score::units($entry['score']) : score::from_wire((string) $entry['score']); }
				catch (\DomainException $e) { return null; }
				catch (\UnexpectedValueException $e) { return null; }
				// The old game launcher initialized an unplayed score to zero.
				if ($units === '0' && empty($entry['score_recorded'])) { $units = null; }
			}
			$games[$key] = array('name_key'=>$key, 'game_name'=>$name, 'played'=>(int) $played, 'score_units'=>$units);
		}
		return $games;
	}

	/** Caller owns the transaction. Source bytes remain available even if a record cannot be decoded. */
	public static function import_batch($db, $prefix, $tour = null)
	{
		$scope = $tour === null ? '' : ' AND tour_id=' . (int) $tour;
		$result = $db->sql_query("SELECT row_id,gamedata FROM {$prefix}tour_plays WHERE turns_state=0$scope ORDER BY row_id LIMIT 32 FOR UPDATE");
		$rows = $db->sql_fetchrowset($result);
		$db->sql_freeresult($result);
		foreach ($rows as $player)
		{
			$id = (int) $player['row_id'];
			$games = self::decode($player['gamedata']);
			if ($games)
			{
				$batch = array();
				foreach ($games as $row)
				{
					$batch[] = array('participant_id'=>$id) + $row;
					if (count($batch) === 64) { $db->sql_multi_insert($prefix . 'turns', $batch); $batch = array(); }
				}
				if ($batch) { $db->sql_multi_insert($prefix . 'turns', $batch); }
			}
			$state = $games === null ? 2 : 1;
			$db->sql_query("UPDATE {$prefix}tour_plays SET turns_state=$state WHERE row_id=$id");
		}
		return count($rows) < 32;
	}
}
