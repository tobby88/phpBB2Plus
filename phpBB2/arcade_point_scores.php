<?php
/***************************************************************************
 *                            arcade_point_scores.php
 *                            -----------------------
 *      Version         : 2.0.0, modernized for phpBB2 Plus
 *      Original Author : Gumfuzi
 *      Mod Author      : Painkiller
 ***************************************************************************/

define('IN_PHPBB', true);

if (isset($_GET['phpbb_root_path']))
{
	die('Hacking attempt');
}

$phpbb_root_path = './';
include($phpbb_root_path . 'extension.inc');
include($phpbb_root_path . 'common.' . $phpEx);

$userdata = session_pagestart($user_ip, PAGE_INDEX);
init_userprefs($userdata);

if (!$userdata['session_logged_in'])
{
	redirect(append_sid("login.$phpEx?redirect=arcade_point_scores.$phpEx", true));
}

$page_title = sprintf($lang['arcade_points_title'], $board_config['sitename']);
include($phpbb_root_path . 'includes/page_header.' . $phpEx);

$template->set_filenames(array('body' => 'arcade_point_scores.tpl'));

// Points awarded for each current monthly and all-time high score.
$monthly_points = 1;
$all_time_points = 4;
$include_previous_month = true;
$started_at = microtime(true);
$totals = array();

$year = (int) date('Y');
$month = (int) date('n');
$monthly_ranges = array(array($year, $month));
if ($include_previous_month)
{
	$previous_month_time = mktime(12, 0, 0, $month - 1, 1, $year);
	$monthly_ranges[] = array((int) date('Y', $previous_month_time), (int) date('n', $previous_month_time));
}

$month_conditions = array();
foreach ($monthly_ranges as $range)
{
	$month_conditions[] = '(h.highscore_year = ' . $range[0] . ' AND h.highscore_mon = ' . $range[1] . ')';
}

// Scores refer to game names. Legacy duplicate names share one eligible game.
$eligible_game_ids = "SELECT MIN(game_id) AS game_id FROM " . iNA_GAMES . "
	WHERE game_name <> '' AND game_avail = 1 AND game_show_score = 1
	GROUP BY game_name";

// Older installations can have more than one monthly row for the same game.
// Pick the winner using the same ordering as score submission.
$sql = "SELECT h.highscore_user_id, COALESCE(u.username, h.highscore_player) AS player,
		COUNT(*) AS score_count
	FROM " . iNA_HIGHSCORES . " h
	INNER JOIN " . iNA_GAMES . " g ON g.game_name = h.highscore_game
	INNER JOIN (" . $eligible_game_ids . ") eligible ON eligible.game_id = g.game_id
	LEFT JOIN " . USERS_TABLE . " u ON u.user_id = h.highscore_user_id
	WHERE (" . implode(' OR ', $month_conditions) . ")
		AND h.highscore_id = (
			SELECT candidate.highscore_id
			FROM " . iNA_HIGHSCORES . " candidate
			WHERE candidate.highscore_game = h.highscore_game
				AND candidate.highscore_year = h.highscore_year
				AND candidate.highscore_mon = h.highscore_mon
			ORDER BY CASE WHEN g.reverse_list <> 0 THEN candidate.highscore_score ELSE -candidate.highscore_score END,
				candidate.highscore_date ASC, candidate.highscore_user_id ASC, candidate.highscore_id ASC
			LIMIT 1
		)
	GROUP BY h.highscore_user_id, COALESCE(u.username, h.highscore_player)";
if (!($result = $db->sql_query($sql)))
{
	message_die(GENERAL_ERROR, 'Error retrieving monthly high scores', '', __LINE__, __FILE__, $sql);
}
while ($row = $db->sql_fetchrow($result))
{
	$player = (string) $row['player'];
	$player_key = ((int) $row['highscore_user_id'] > 0) ? 'u:' . (int) $row['highscore_user_id'] : 'n:' . $player;
	if (!isset($totals[$player_key]))
	{
		$totals[$player_key] = array(
			'user_id' => (int) $row['highscore_user_id'],
			'player' => $player,
			'monthly' => 0,
			'all_time' => 0,
			'total' => 0
		);
	}
	$totals[$player_key]['player'] = $player;
	$totals[$player_key]['monthly'] += (int) $row['score_count'];
}
$db->sql_freeresult($result);

// All-time score rows contain the full history. The first row per game after
// applying each game's direction is its current all-time winner.
foreach (array(0 => 'DESC', 1 => 'ASC') as $reverse_list => $sort_order)
{
	$sql = "SELECT g.game_name, s.player_id, u.username AS player
		FROM " . iNA_AT_SCORES . " s
		INNER JOIN " . iNA_GAMES . " g ON g.game_name = s.game_name
		INNER JOIN (" . $eligible_game_ids . ") eligible ON eligible.game_id = g.game_id
		INNER JOIN " . USERS_TABLE . " u ON u.user_id = s.player_id
		WHERE g.reverse_list = " . (int) $reverse_list . "
		ORDER BY g.game_name ASC, s.score $sort_order, s.date ASC, s.player_id ASC";
	if (!($result = $db->sql_query($sql)))
	{
		message_die(GENERAL_ERROR, $lang['no_score_data'], '', __LINE__, __FILE__, $sql);
	}

	$last_game = null;
	while ($row = $db->sql_fetchrow($result))
	{
		if ($row['game_name'] === $last_game)
		{
			continue;
		}
		$last_game = $row['game_name'];
		$player = (string) $row['player'];
		$player_key = 'u:' . (int) $row['player_id'];
		if (!isset($totals[$player_key]))
		{
			$totals[$player_key] = array(
				'user_id' => (int) $row['player_id'],
				'player' => $player,
				'monthly' => 0,
				'all_time' => 0,
				'total' => 0
			);
		}
		$totals[$player_key]['all_time']++;
	}
	$db->sql_freeresult($result);
}

foreach ($totals as &$player_total)
{
	$player_total['total'] = ($player_total['monthly'] * $monthly_points) +
		($player_total['all_time'] * $all_time_points);
}
unset($player_total);

uasort($totals, function ($left, $right) {
	if ($left['total'] !== $right['total'])
	{
		return ($left['total'] > $right['total']) ? -1 : 1;
	}
	if ($left['all_time'] !== $right['all_time'])
	{
		return ($left['all_time'] > $right['all_time']) ? -1 : 1;
	}
	return strcasecmp($left['player'], $right['player']);
});

$rank = 0;
foreach ($totals as $player_total)
{
	$rank++;
	$template->assign_block_vars('total', array(
		'RANK' => $rank,
		'NAME' => phpbb_author_name($player_total['user_id'], $player_total['player']),
		'MONTH_TOTAL' => $player_total['monthly'],
		'AT_TOTAL' => $player_total['all_time'],
		'TOTAL' => $player_total['total']
	));
}

$template->assign_vars(array(
	'TITLE' => htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8'),
	'C_MONTH' => $monthly_points,
	'C_ALL_TIME' => $all_time_points,
	'INFOTEXT' => sprintf($lang['arcade_points_generated'], round(microtime(true) - $started_at, 4)),
	'L_RANK' => $lang['arcade_points_rank'],
	'L_NAME' => $lang['arcade_points_name'],
	'L_MONTHLY' => $lang['arcade_points_monthly'],
	'L_ALL_TIME' => $lang['arcade_points_all_time'],
	'L_TOTAL' => $lang['arcade_points_total'],
	'L_EXPLANATION_TITLE' => $lang['arcade_points_explanation_title'],
	'L_EXPLANATION' => $lang['arcade_points_explanation'],
	'L_CALCULATION' => $lang['arcade_points_calculation'],
	'L_MONTHLY_POINTS' => sprintf($lang['arcade_points_monthly_points'], $monthly_points),
	'L_ALL_TIME_POINTS' => sprintf($lang['arcade_points_all_time_points'], $all_time_points),
	'L_PERIOD_INFO' => $lang['arcade_points_period_info'],
	'L_STATS' => $lang['arcade_points_stats']
));

$template->pparse('body');
include($phpbb_root_path . 'includes/page_tail.' . $phpEx);
?>
