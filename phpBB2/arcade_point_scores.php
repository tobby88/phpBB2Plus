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

$page_title = 'The Best Players At ' . $board_config['sitename'];
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
	$month_conditions[] = '(highscore_year = ' . $range[0] . ' AND highscore_mon = ' . $range[1] . ')';
}

$sql = "SELECT highscore_player, COUNT(*) AS score_count
	FROM " . iNA_HIGHSCORES . "
	WHERE " . implode(' OR ', $month_conditions) . "
	GROUP BY highscore_player";
if (!($result = $db->sql_query($sql)))
{
	message_die(GENERAL_ERROR, 'Error retrieving monthly high scores', '', __LINE__, __FILE__, $sql);
}
while ($row = $db->sql_fetchrow($result))
{
	$player = (string) $row['highscore_player'];
	$totals[$player] = array(
		'player' => $player,
		'monthly' => (int) $row['score_count'],
		'all_time' => 0,
		'total' => 0
	);
}

// All-time score rows contain the full history. The first row per game after
// applying each game's direction is its current all-time winner.
foreach (array(0 => 'DESC', 1 => 'ASC') as $reverse_list => $sort_order)
{
	$sql = "SELECT s.game_name, u.username AS player
		FROM " . iNA_AT_SCORES . " s
		INNER JOIN " . iNA_GAMES . " g ON g.game_name = s.game_name
		INNER JOIN " . USERS_TABLE . " u ON u.user_id = s.player_id
		WHERE g.reverse_list = " . (int) $reverse_list . "
			AND g.game_avail = 1
			AND g.game_show_score = 1
		ORDER BY s.game_name ASC, s.score $sort_order, s.date ASC";
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
		if (!isset($totals[$player]))
		{
			$totals[$player] = array(
				'player' => $player,
				'monthly' => 0,
				'all_time' => 0,
				'total' => 0
			);
		}
		$totals[$player]['all_time']++;
	}
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
		'NAME' => htmlspecialchars($player_total['player'], ENT_QUOTES, 'UTF-8'),
		'MONTH_TOTAL' => $player_total['monthly'],
		'AT_TOTAL' => $player_total['all_time'],
		'TOTAL' => $player_total['total']
	));
}

$template->assign_vars(array(
	'TITLE' => htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8'),
	'C_MONTH' => $monthly_points,
	'C_ALL_TIME' => $all_time_points,
	'INFOTEXT' => 'List generated in ' . round(microtime(true) - $started_at, 4) . ' seconds'
));

$template->pparse('body');
include($phpbb_root_path . 'includes/page_tail.' . $phpEx);
?>
