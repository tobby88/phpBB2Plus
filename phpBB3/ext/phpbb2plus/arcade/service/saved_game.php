<?php
namespace phpbb2plus\arcade\service;

class saved_game
{
	public static function from_score($db, $prefix, $game, $owner)
	{
		$result = $db->sql_query_limit('SELECT game_data,save_encoded,player_id,recorded_at FROM ' . $prefix . 'scores WHERE owner_id=' . (int) $owner .
			" AND game_name='" . $db->sql_escape($game['game_name']) . "' AND game_data IS NOT NULL ORDER BY score_units " .
			($game['reverse_list'] ? 'ASC' : 'DESC') . ',recorded_at DESC,row_id', 1);
		$row = $db->sql_fetchrow($result);
		$db->sql_freeresult($result);
		return $row;
	}

	// phpBB 3.3 uses three-byte MySQL text. Game data must also preserve literal HTML entities.
	static public function pack($text)
	{
		if (!is_string($text) || strlen($text) > 65535 || preg_match('//u', $text) !== 1) { throw new \DomainException('PLUS_ARC_PROGRESS'); }
		$encode = preg_match('/[\xF0-\xF4]/', $text) === 1;
		return array('game_data' => $encode ? base64_encode($text) : $text, 'save_encoded' => (int) $encode);
	}

	static public function unpack($row)
	{
		$data = $row['save_encoded'] ? base64_decode($row['game_data'], true) : $row['game_data'];
		if (!is_string($data) || strlen($data) > 65535 || preg_match('//u', $data) !== 1) { throw new \DomainException('PLUS_ARC_PROGRESS'); }
		return $data;
	}
}
