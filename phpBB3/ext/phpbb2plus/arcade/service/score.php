<?php
namespace phpbb2plus\arcade\service;

class score
{
	// phpBB's portable decimal column has two fractional digits. Signed fixed-point
	// units preserve all four historical digits and support native numeric sorting.
	static public function units($score)
	{
		if ($score === null || $score === '') { return null; }
		$value = is_float($score) ? sprintf('%.4F', $score) : (string) $score;
		if (!preg_match('/^(-?)([0-9]{1,10})(?:\.([0-9]{1,4}))?$/D', $value, $parts))
		{ throw new \UnexpectedValueException('Invalid four-decimal Arcade score.'); }
		$digits = ltrim($parts[2] . str_pad($parts[3] ?? '', 4, '0'), '0');
		return $digits === '' ? '0' : $parts[1] . $digits;
	}

	// Games also send scientific notation and unrounded decimals. Round the
	// digits themselves so that large scores retain the same fourth decimal on every host.
	static public function from_wire($value)
	{
		if (!is_string($value) || strlen($value) > 128 ||
			!preg_match('/^([+-]?)([0-9]*)(?:\.([0-9]*))?(?:[eE]([+-]?[0-9]+))?$/D', trim($value), $m) ||
			($m[2] . ($m[3] ?? '')) === '') { throw new \DomainException('PLUS_ARC_INVALID_SCORE'); }
		$digits = $m[2] . ($m[3] ?? '');
		$significant = ltrim($digits, '0');
		if ($significant === '') { return '0'; }
		$exp = max(-256, min(256, (int) ($m[4] ?? 0)));
		$point = strlen($m[2]) + $exp - (strlen($digits) - strlen($significant));
		if ($point > 10) { throw new \DomainException('PLUS_ARC_INVALID_SCORE'); }
		$places = $point + 4;
		if ($places < 0) { return '0'; }
		$padded = str_pad($significant, $places + 1, '0');
		// All 14-digit integers are exact even when PHP's native int is 32-bit.
		$units = (float) substr($padded, 0, $places);
		if ($padded[$places] >= '5') { $units++; }
		if ($units > 99999999999999) { throw new \DomainException('PLUS_ARC_INVALID_SCORE'); }
		return sprintf('%.0F', $units && $m[1] === '-' ? -$units : $units);
	}

	static public function display($units, $separator = '.')
	{
		$v = (string) $units;
		$digits = str_pad(ltrim($v, '-'), 5, '0', STR_PAD_LEFT);
		$fraction = rtrim(substr($digits, -4), '0');
		return (substr($v, 0, 1) === '-' ? '-' : '') . substr($digits, 0, -4) . ($fraction !== '' ? $separator . $fraction : '');
	}

	public static function refresh_winner($db, $prefix, $game, $all)
	{
		$table = $prefix . ($all ? 'all_scores' : 'scores');
		$result = $db->sql_query_limit("SELECT s.owner_id,s.player_id FROM $table s WHERE s.game_name='" . $db->sql_escape($game['game_name']) .
			"' AND ((s.owner_id=0 AND s.player_id=-1 AND s.author_removed=0) OR EXISTS (SELECT 1 FROM " . USERS_TABLE .
			' u WHERE u.user_id=s.owner_id AND s.owner_id>0)) ORDER BY s.score_units ' . ($game['reverse_list'] ? 'ASC' : 'DESC') .
			',s.recorded_at,s.player_id,s.row_id', 1);
		$winner = $db->sql_fetchrow($result);
		$db->sql_freeresult($result);
		$values = array(($all ? 'all_winner_id' : 'winner_id') => (int) ($winner['owner_id'] ?? 0),
			($all ? 'at_highscore_id' : 'highscore_id') => (int) ($winner['player_id'] ?? 0));
		$db->sql_query('UPDATE ' . $prefix . 'games SET ' . $db->sql_build_array('UPDATE', $values) . ' WHERE game_id=' . (int) $game['game_id']);
	}
}
