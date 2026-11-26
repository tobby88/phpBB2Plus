<?php
function seed_arcade($db)
{
	$db->query('UPDATE phpbb_users SET games_block_pm=0 WHERE user_id=1');
	$db->query("ALTER TABLE phpbb_users ADD user_points DECIMAL(30,8) DEFAULT 3, ADD user_cash BIGINT UNSIGNED DEFAULT 0");
	$db->query("UPDATE phpbb_users SET user_points=9007199254740993.12345678,user_cash=18446744073709551615 WHERE user_id=1");
	$db->query("INSERT INTO phpbb_config (config_name,config_value) VALUES ('points_name','Fixture credits') ON DUPLICATE KEY UPDATE config_value='Fixture credits'");
	$db->query("UPDATE phpbb_ina_data SET config_value='1' WHERE config_name IN ('use_rewards_mod','use_point_system')");
	$db->query("UPDATE phpbb_ina_data SET config_value='0' WHERE config_name IN ('games_offline','games_no_guests')");
	$db->query("UPDATE phpbb_ina_data SET config_value='1' WHERE config_name IN ('games_guest_highscore','games_comments','games_rate','games_at_highscore','games_show_mhm','games_tournament_mode')");
	$db->query("INSERT INTO phpbb_ina_cat (cat_id,cat_name,cat_desc,cat_type,cat_parent,cat_order,group_required,mod_id) VALUES
		(10,'Spielearchiv','Offene Spiele','p',0,1,0,2),(11,'Clubspiele','','p',0,2,71,1),(12,'Clubturniere','','s',11,1,0,1),(13,'Einzelspieler','','p',0,3,72,1)");
	$db->query("INSERT INTO phpbb_ina_games (game_id,game_name,game_path,game_desc,cat_id,allow_guest,game_avail,reverse_list,game_show_score,game_flash,
		highscore_limit,at_highscore_limit,highscore_id,at_highscore_id,instructions) VALUES
		(60,'puzzle','games/puzzle/','Öffentliches Denkspiel',10,1,1,0,1,1,'10',10,1,2,'Steine &amp; Farben'),
		(61,'timer','games/','Stoppuhr',10,1,1,1,1,1,'10',10,1,1,'Zeit messen'),
		(62,'private','games/puzzle/club','Vertrauliche Spielprobe',12,0,1,0,1,1,'10',10,0,0,''),
		(63,'closed','games/','Abgeschaltetes Spiel',10,0,0,0,1,1,'10',10,0,0,''),
		(64,'personal','games/','Persönliche Spielprobe',13,0,1,0,1,1,'10',10,0,0,'')");
	$db->query("UPDATE phpbb_ina_games SET game_reward=4,game_bonus=2 WHERE game_id=60");
	$db->query("UPDATE phpbb_ina_games SET image_path='.png' WHERE game_id IN (60,62)");
	$db->query("UPDATE phpbb_ina_games SET image_path='images/arcade/clock.png' WHERE game_id=61");
	$db->query("UPDATE phpbb_ina_games SET game_charge=8 WHERE game_id=62");
	$db->query("INSERT INTO phpbb_ina_scores (game_name,player,player_id,score,date,time_taken,gameData) VALUES
		('puzzle','AlterSpielname',1,95.1234,11001,20,'state:α'),('puzzle','ModernMember',2,5.5000,11002,25,''),
		('puzzle','EditorFixture',98,-12.3751,11003,30,''),('timer','HistoricMember',1,0.0001,11004,3,''),('timer','ModernMember',2,9.7500,11005,7,''),
		('personal','EditorFixture',98,40,100,9,''),('personal','ModernMember',2,40,101,12,''),('personal','HistoricMember',1,40,101,11,'')");
	$db->query("INSERT INTO phpbb_ina_at_scores (game_name,player,player_id,score,date,time_taken)
		SELECT game_name,player,player_id,score,date,time_taken FROM phpbb_ina_scores");
	$db->query("UPDATE phpbb_ina_at_scores SET score=9999999999.9999,date=UNIX_TIMESTAMP()-60 WHERE game_name='puzzle' AND player_id=2");
	$db->query("INSERT INTO phpbb_ina_highscore (highscore_id,highscore_year,highscore_mon,highscore_game,highscore_user_id,highscore_player,highscore_score,highscore_date)
		VALUES (7,2008,5,'puzzle',1,'AlterSpielname',96.0004,11006)");
	$db->query("INSERT INTO phpbb_ina_fav (user_id,fav_game_id,fav_game_name)
		VALUES (1,NULL,'puzzle'),(1,61,'puzzle'),(1,60,'withdrawn'),(87,62,'private')");
	$db->query("UPDATE phpbb_ina_data SET config_value='10' WHERE config_name='games_default_rate'");
	$db->query("INSERT INTO phpbb_ina_game_saves (user_id,game_id,game_data,saved_at) VALUES (1,60,'Spielstand ü=7 🎮',11007)");
	$db->query("INSERT INTO phpbb_ina_comment (comment_id,comment_game_name,comment_user_id,comment_username,comment_time,comment_text,comment_edit_user_id)
		VALUES (71,'puzzle',1,'AlterSpielname',11008,'Schöne Farben &amp; Formen 🎲',2)");
	$db->query("INSERT INTO phpbb_ina_rate (rate_game_name,rate_user_id,rate_user_ip,rate_point) VALUES ('puzzle',1,'7f000001',7)");
	$db->query("INSERT INTO phpbb_ina_tour (tour_id,tour_name,tour_desc,start_id,start_date,tour_max_players,tour_player_turns,tour_active)
		VALUES (31,'Frühlingsrunde','Spielprobe',1,11009,8,3,1),(32,'Offene Runde','Anmeldung',1,11020,2,2,2),
		(33,'Archivpokal','Abgeschlossen',98,11022,2,1,3),(34,'Abendrunde','Mehrfachanmeldung',2,11023,3,1,2)");
	$db->query('UPDATE phpbb_ina_tour SET champion=98,end_date=11080 WHERE tour_id=33');
	$db->query("INSERT INTO phpbb_ina_tour_data (tour_id,game_name,top_score,top_player) VALUES
		(31,'puzzle',95.1234,1),(31,'timer',NULL,NULL),(32,'puzzle',0,1),(32,'personal',-2.1250,98),
		(32,'private',NULL,NULL),(34,'timer',9.5001,2)");
	$db->query("INSERT INTO phpbb_ina_tour_invite VALUES (31,2),(32,2)");
	$db->query("INSERT INTO phpbb_ina_tour_play (tour_id,user_id,last_played_time,last_played_game,gamedata)
		VALUES (31,1,11010,'puzzle','a:0:{}'),(32,1,11021,'puzzle','a:0:{}'),(34,87,11022,NULL,'Incomplete historic record')");
	$turns = array(4=>array('game_name'=>'puzzle','played'=>1,'score'=>-3.1251,'original_note'=>'Frühlingsrunde'),
		8=>array('game_name'=>'personal','played'=>2,'score'=>0,'score_recorded'=>true));
	$stored = $db->real_escape_string(addslashes(serialize($turns)));
	$db->query("UPDATE phpbb_ina_tour_play SET gamedata='$stored' WHERE tour_id=32 AND user_id=1");
	$db->query("INSERT INTO phpbb_ina_user_data (user_id,first_places,first_list,user_game_time) VALUES (1,2,'puzzle|timer|',39)");
	$db->query("INSERT INTO phpbb_ina_log (record_no,user_id,name,value,date) VALUES (91,1,'Fixture','Historisches Ereignis',11011)");
	$db->query("INSERT INTO phpbb_ina_banned (username,user_id,game,score,date) VALUES ('Ehemaliger',98,'puzzle',3,'11012')");
}

function verify_arcade($db, $member)
{
	$p = 'p3_plus_arc_';
	$currencies = $db->query("SELECT currency_field,balance_text FROM {$p}wallets WHERE owner_id=$member ORDER BY currency_field")->fetch_all(MYSQLI_ASSOC);
	check_fixture(count($currencies) === 2 && $currencies[0]['balance_text'] === '18446744073709551615' &&
		$currencies[1]['balance_text'] === '9007199254740993.12345678', 'Balances lost precision during conversion');
	foreach (array('categories'=>4,'games'=>5,'scores'=>8,'all_scores'=>8,'monthly'=>1,'comments'=>1,'ratings'=>1,'favorites'=>4,'saves'=>1,
		'tours'=>4,'tour_games'=>6,'invites'=>2,'tour_plays'=>3,'turns'=>2,'players'=>1,'log'=>1,'bans'=>1,'grants'=>2) as $table => $count)
	{
		$actual = $db->query("SELECT COUNT(*) FROM $p$table")->fetch_row()[0];
		check_fixture((int) $actual === $count, 'Incomplete Arcade conversion for ' . $table);
	}
	$first = $db->query("SELECT owner_id,game_data,score_units FROM {$p}scores WHERE game_name='puzzle' AND player_id=1")->fetch_assoc();
	check_fixture((int) $first['owner_id'] === $member && $first['game_data'] === 'state:α' && $first['score_units'] === '951234', 'Arcade score precision, player or legacy state changed');
	$highest = $db->query("SELECT MAX(score_units) FROM {$p}all_scores")->fetch_row()[0];
	$missing = $db->query("SELECT score_units,winner_id FROM {$p}tour_games WHERE tour_id=31 AND game_name='timer'")->fetch_assoc();
	$turn = $db->query("SELECT s.played,s.score_units,p.turns_state,p.gamedata FROM {$p}turns s JOIN {$p}tour_plays p ON p.row_id=s.participant_id WHERE p.tour_id=32 AND p.owner_id=$member AND s.game_name='puzzle'")->fetch_assoc();
	check_fixture($turn && (int) $turn['played'] === 1 && $turn['score_units'] === '-31251' && (int) $turn['turns_state'] === 1 &&
		strpos($turn['gamedata'],'original_note') !== false, 'An escaped historical tournament score, turn, source record or player was lost');
	$saved = $db->query("SELECT owner_id,game_data,save_encoded FROM {$p}saves WHERE game_id=60")->fetch_assoc();
	check_fixture($highest === '99999999999999' && $missing['score_units'] === null && !(int) $missing['winner_id'] &&
		(int)$saved['owner_id'] === $member && $saved['save_encoded'] && base64_decode($saved['game_data'],true) === 'Spielstand ü=7 🎮', 'Lost score precision, missing tournament score, or saved game');
}
