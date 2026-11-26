<?php
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 || !preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
define('IN_PHPBB', true);
$phpbb_root_path = './';
$phpEx = 'php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
$p = $table_prefix . 'plus_arc_';
$one = static function ($sql) use ($db) {
	$result = $db->sql_query($sql);
	$row = $db->sql_fetchrow($result);
	$db->sql_freeresult($result);
	return $row;
};
$member = (int) $one('SELECT user_id FROM ' . USERS_TABLE . " WHERE username_clean='historicmember'")['user_id'];
$founder = (int) $one('SELECT user_id FROM ' . USERS_TABLE . " WHERE username_clean='modernmember'")['user_id'];
if ($member <= ANONYMOUS || $member === $founder || !$founder) { throw new RuntimeException('Missing converted players'); }
if ($argv[2] === 'verify-lock')
{
	// A second connection models a simultaneous Arcade operation and an independently saved category assignment.
	mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
	$other = new mysqli('127.0.0.1', getenv('PLUS_TEST_DB_USER') ?: 'root', getenv('PLUS_TEST_DB_PASSWORD'),
		$argv[1], (int) (getenv('PLUS_TEST_DB_PORT') ?: 3306));
	try
	{
		$db->sql_query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
		$phpbb_container->get('phpbb2plus.arcade.sessions')->locked(static function () use ($other, $one, $p, $member) {
			$other->begin_transaction();
			try
			{
				$other->query("SELECT state_id FROM {$p}state WHERE state_id=1 FOR UPDATE NOWAIT");
				throw new RuntimeException('Two connections acquired the Arcade coordinator at once');
			}
			catch (mysqli_sql_exception $e) { if (!in_array($e->getCode(), array(1205,3572), true)) { throw $e; } }
			finally { $other->rollback(); }
			$other->query("UPDATE {$p}categories SET moderator_id=$member WHERE cat_id=901001");
			if ((int) $one("SELECT moderator_id FROM {$p}categories WHERE cat_id=901001")['moderator_id'] !== $member)
			{ throw new RuntimeException('The coordinator opened a stale permission snapshot'); }
		});
	}
	finally
	{
		$other->query("UPDATE {$p}categories SET moderator_id=0 WHERE cat_id=901001");
		$other->close();
	}
	return;
}
$db->sql_transaction('begin');
switch ($argv[2])
{
	case 'seed':
		$db->sql_query("INSERT INTO {$p}categories (cat_id,cat_name,cat_parent,cat_type,moderator_id) VALUES
			(901001,'Child without assigned moderator',12,'s',0),(901002,'External category',0,'l',$member)");
		$db->sql_query("INSERT INTO {$p}games (game_id,game_name,game_desc,cat_id,reverse_list,game_avail,highscore_id,winner_id,at_highscore_id,all_winner_id) VALUES
			(901000,'review-fixture','Review &amp; repair',12,1,0,1,$member,2,$founder),
			(901001,'no-category-review','Orphaned game',-1,0,0,0,0,0,0)");
		$db->sql_query("INSERT INTO {$p}scores (row_id,game_name,player,player_id,owner_id,author_removed,score_units,recorded_at,time_taken,player_ip,game_data,save_encoded) VALUES
			(901001,'review-fixture','Previous username',1,$member,0,-1250,100,75,'192.0.2.45','bGV2ZWwg8J+OryAmYW1wOw==',1),
			(901002,'review-fixture','Previous username',1,$member,0,15000,90,65,'192.0.2.45','later selection',0),
			(901003,'review-fixture','Stale founder',2,$founder,0,25000,91,0,'2001:db8:abcd:1234::6',NULL,0),
			(901004,'review-fixture','Departed',8,0,1,-15000,89,0,'',NULL,0),
			(901005,'review-fixture','Guest player',-1,0,0,20000,92,0,'',NULL,0)");
		$db->sql_query("INSERT INTO {$p}all_scores (row_id,game_name,player,player_id,owner_id,score_units,recorded_at) VALUES
			(901001,'review-fixture','Old founder',2,$founder,50000,101),(901002,'review-fixture','Old member',1,$member,60000,102)");
		break;
	case 'duplicate':
		$db->sql_query("INSERT INTO {$p}games (game_id,game_name,cat_id) VALUES (901003,'review-fixture',10)");
		break;
	case 'unique':
		$db->sql_query("DELETE FROM {$p}games WHERE game_id=901003");
		break;
	case 'revoke':
	case 'restore':
		$moderator = $argv[2] === 'restore' ? $member : 0;
		$db->sql_query("UPDATE {$p}categories SET moderator_id=$moderator WHERE cat_id=12");
		break;
	case 'moved':
	case 'returned':
		$category = $argv[2] === 'moved' ? 10 : 12;
		$db->sql_query("UPDATE {$p}games SET cat_id=$category WHERE game_id=901000");
		break;
	case 'verify':
		$game = $one("SELECT highscore_id,winner_id,at_highscore_id,all_winner_id FROM {$p}games WHERE game_id=901000");
		$save = $one("SELECT user_id,game_data,save_encoded FROM {$p}saves WHERE game_id=901000 AND owner_id=$member");
		$current = $one("SELECT COUNT(*) AS n, MIN(CASE WHEN row_id=901002 THEN score_units END) AS edited FROM {$p}scores WHERE game_name='review-fixture'");
		$all = $one("SELECT COUNT(*) AS n, MIN(CASE WHEN row_id=901002 THEN score_units END) AS edited FROM {$p}all_scores WHERE game_name='review-fixture'");
		if (array_map('intval', array_values($game)) !== array(-1,0,1,$member) ||
			(int) $current['n'] !== 4 || (int) $current['edited'] !== 32000 ||
			(int) $all['n'] !== 2 || (int) $all['edited'] !== -41234 ||
			!$save || (int) $save['user_id'] !== 1 || (int) $save['save_encoded'] !== 1 || $save['game_data'] !== 'bGV2ZWwg8J+OryAmYW1wOw==')
		{ throw new RuntimeException('Wrong individual score, remapped winner, board isolation or fallback progress'); }
		foreach (array('LOG_PLUS_ARC_SCORE_EDIT_CURRENT','LOG_PLUS_ARC_SCORE_EDIT_ALL','LOG_PLUS_ARC_SCORE_DELETE_CURRENT') as $event)
		{
			if ((int) $one("SELECT COUNT(*) AS n FROM " . LOG_TABLE . " WHERE log_operation='$event'")['n'] !== 1)
			{ throw new RuntimeException('Missing or repeated native log event: ' . $event); }
		}
		break;
	case 'suspend-delegate':
	case 'resume-delegate':
		$rights = $argv[2] === 'resume-delegate' ? '["a_plus_arc_scores"]' : '[]';
		$db->sql_query("UPDATE {$table_prefix}plus_jr_delegations SET rights_json='$rights' WHERE user_id=45");
		break;
	case 'grant-global':
	case 'revoke-global':
		require_once './includes/acp/auth.php';
		$ordinary = (int) $one('SELECT user_id FROM ' . USERS_TABLE . " WHERE username_clean='departingfixture'")['user_id'];
		if ($ordinary <= ANONYMOUS) { throw new RuntimeException('Missing ordinary player'); }
		$writer = new auth_admin();
		$writer->acl_set('user', 0, $ordinary, array('m_plus_arcade' => $argv[2] === 'grant-global' ? ACL_YES : ACL_NO));
		break;
	case 'cleanup':
		$db->sql_query("UPDATE {$p}categories SET moderator_id=$member WHERE cat_id=12");
		$db->sql_query("DELETE FROM {$p}saves WHERE game_id=901000");
		foreach (array('scores','all_scores') as $table) { $db->sql_query("DELETE FROM $p$table WHERE game_name='review-fixture'"); }
		$db->sql_query("DELETE FROM {$p}games WHERE game_id BETWEEN 901000 AND 901003");
		$db->sql_query("DELETE FROM {$p}categories WHERE cat_id IN (901001,901002)");
		break;
	default: throw new InvalidArgumentException('Unknown score moderation test phase');
}
$db->sql_transaction('commit');
