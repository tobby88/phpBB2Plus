<?php

function ae_catalog_read($db, $page_code, $filter, $sort, $start, $page_size)
{
	$arcade = (object) array('sort_order'=>'DESC', 'arcade_config'=>array('games_per_page'=>$page_size));
	$userdata = array('user_id'=>7);
	$favorite_games = 'EXISTS (SELECT 1 FROM '.iNA_FAV.' f WHERE f.fav_game_name=g.game_name AND f.user_id=7)';
	$sql_and = $filter;
	$order_by = $sort;
	eval($page_code);
	$result = $db->sql_query($sql);
	$error = $db->sql_error();
	as_check((bool) $result, 'Actual public Arcade listing query: '.$error['message']);
	$rows = $db->sql_fetchrowset($result);
	$db->sql_freeresult($result);
	return array($rows, isset($catalog_source, $catalog_filter) ? $catalog_source.$catalog_filter : null);
}

function ae_catalog_test($db, $canonical, $activity)
{
	foreach (array('ina_cat','ina_fav','ina_scores','ina_at_scores','ina_rate','ina_comment','users') as $name)
	{
		as_check(preg_match('/^CREATE TABLE phpbb_'.$name.' \(.*?^\\) ENGINE=InnoDB[^;]*;/ms', $canonical, $definition) === 1 &&
			$db->sql_query(str_replace('phpbb_'.$name, 'fixture_'.$name, $definition[0])), 'Exact native catalogue fixture table '.$name);
	}
	$fixtures = array(
		"DELETE FROM fixture_ina_games",
		"INSERT INTO fixture_ina_cat(cat_id,cat_name) VALUES(7,'Main category'),(8,'Other category')",
		"INSERT INTO fixture_ina_games(game_id,game_name,game_desc,cat_id,reverse_list,highscore_id,at_highscore_id,game_avail) VALUES
		(41,'alpha','Common A',7,0,7,7,1),(42,'beta','Common B',7,1,7,99,1),(43,'gamma','Secondary',8,0,0,0,1),
		(44,'orphan','Common orphan',9,0,0,0,1),(45,'offline','Common offline',7,0,0,0,0),(46,'fresh','Common F Grüße O''Hara & Spiele',7,0,0,0,1)",
		"INSERT INTO fixture_users(user_id,username,user_password,user_email,user_allow_viewonline) VALUES(7,'Current display name','','',0)",
		"INSERT INTO fixture_ina_scores(game_name,player_id,score,date) VALUES
		('alpha',7,70,1),('alpha',7,90,3),('alpha',8,500,3),('beta',7,50,1),('beta',7,12,3)",
		"INSERT INTO fixture_ina_at_scores(game_name,player_id,score,date,player_name) VALUES
		('alpha',7,80,1,'Old registered name'),('alpha',7,99,2,'Other old name'),('alpha',8,1,1,'Unrelated'),
		('beta',99,200,2,'Worse score'),('beta',99,11,3,'Later best'),('beta',99,11,1,'Earliest best')",
		"INSERT INTO fixture_ina_rate(rate_game_name,rate_user_id,rate_point) VALUES('alpha',7,1),('alpha',8,3),('alpha',9,5),('beta',7,2)",
		"INSERT INTO fixture_ina_comment(comment_game_name,comment_text) VALUES('alpha','First'),('alpha','Second'),('beta','First'),('beta','Second'),('beta','Third')",
		"INSERT INTO fixture_ina_fav(user_id,fav_game_name) VALUES(7,'alpha'),(7,'alpha'),(7,'orphan'),(8,'beta')",
	);
	foreach ($fixtures as $fixture) { as_check((bool) $db->sql_query($fixture), 'Apply own Arcade catalogue fixture'); }
	$start = strpos($activity, '$sql_order = " ');
	$end = strpos($activity, 'if( !$result = $db->sql_query($sql) )', $start);
	as_check($start !== false && $end > $start, 'Use actual public Arcade SQL assembly');
	$page_code = substr($activity, $start, $end-$start);
	$count_start = strpos($activity, '$sql = \'SELECT COUNT(*) AS total\'', $end);
	$count_end = strpos($activity, ';', $count_start);
	as_check($count_start !== false && $count_end > $count_start, 'Use actual total SQL assembly');
	$count_code = substr($activity, $count_start, $count_end-$count_start+1);
	$search_start = strpos($activity, '$search_term = isset($search_word)');
	$search_end = strpos($activity, '$page_title = ', $search_start);
	as_check($search_start !== false && $search_end > $search_start, 'Use actual Arcade search compilation');
	$search_code = substr($activity, $search_start, $search_end-$search_start);
	$cases = 0;
	foreach (array('STRICT_ALL_TABLES,ONLY_FULL_GROUP_BY,NO_BACKSLASH_ESCAPES','STRICT_ALL_TABLES') as $mode)
	{
		as_check((bool) $db->sql_query("SET SESSION sql_mode='".$mode."'"), 'Exact native Arcade SQL mode');
		list($rows, $source) = ae_catalog_read($db, $page_code, ' AND g.game_avail=1 AND g.cat_id=7', 'comment_count', 0, 0);
		as_check(array_map('intval', array_column($rows, 'game_id')) === array(42,41,46), 'Correct comment sort and no duplicate games');
		as_check((int) $rows[0]['comment_count'] === 3 && (int) $rows[1]['comment_count'] === 2 &&
			(float) $rows[1]['rating'] === 3.0 && $rows[2]['rating'] === null && (int) $rows[2]['comment_count'] === 0,
			'Each independent rating or comment contributes exactly once');
		as_check((float) $rows[0]['score'] === 12.0 && (float) $rows[0]['at_score'] === 11.0 &&
			$rows[0]['player_name'] === 'Earliest best' && $rows[0]['at_username'] === null,
			'Low-score games keep matching all-time historical name and tie order');
		as_check((float) $rows[1]['score'] === 90.0 && (float) $rows[1]['at_score'] === 99.0 &&
			$rows[1]['at_username'] === 'Current display name' && (int) $rows[1]['at_user_allow_viewonline'] === 0 &&
			$rows[1]['fav_game_name'] === 'alpha' && $rows[0]['fav_game_name'] === null,
			'High-score and current profile/favorite projections remain intact');
		list($page, $scope) = ae_catalog_read($db, $page_code, ' AND g.game_avail=1 AND g.cat_id=7', 'comment_count', 1, 1);
		as_check(count($page) === 1 && (int) $page[0]['game_id'] === 41 && $scope === $source,
			'Pagination limits the stable list after projecting games');
		list($favorites, $favorite_scope) = ae_catalog_read($db, $page_code,
			' AND EXISTS (SELECT 1 FROM '.iNA_FAV.' x WHERE x.fav_game_name=g.game_name AND x.user_id=7)', 'game_id', 0, 2);
		as_check(count($favorites) === 1 && (int) $favorites[0]['game_id'] === 41 && $favorite_scope !== null,
			'Favorite duplicates and games with missing categories cannot affect the count or result');
		$catalog_source = $favorite_scope;
		$catalog_filter = '';
		eval($count_code);
		$result = $db->sql_query($sql);
		as_check($result && (int) $db->sql_fetchrow($result)['total'] === 1, 'Shared filter gives exact favorite count');
		$db->sql_freeresult($result);

		$search_word = htmlspecialchars("Grüße O'Hara & Spiele", ENT_QUOTES, 'UTF-8');
		eval($search_code);
		list($found, $catalog_source) = ae_catalog_read($db, $page_code, $sql_and.' AND g.game_avail=1', 'game_id', 0, 2);
		as_check(count($found) === 1 && (int) $found[0]['game_id'] === 46, 'Actual Arcade search preserves apostrophe, ampersand and Unicode');
		eval($count_code);
		$result = $db->sql_query($sql);
		as_check($result && (int) $db->sql_fetchrow($result)['total'] === 1, 'Quoted search term gives the same native page total');
		$db->sql_freeresult($result);
		$cases++;
	}

	$page_start = strpos($activity, '$page_url = ', $count_end);
	$page_end = strpos($activity, '$page_number = ', $page_start);
	as_check($page_start !== false && $page_end > $page_start, 'Use actual Arcade search pagination');
	$page_code = substr($activity, $page_start, $page_end-$page_start);
	as_function($GLOBALS['as_app'].'includes/functions.php', 'generate_pagination');
	$filename = 'activity.php';
	$start = 0;
	$total_games = 2;
	$arcade = (object) array('cat_id'=>7, 'sort_order'=>'ASC', 'sort_mode'=>'alphabetical', 'arcade_config'=>array('games_per_page'=>1));
	foreach (array('search','fav') as $mode)
	{
		$search_term = 'Grüße & Spiele';
		eval($page_code);
		as_check(preg_match('/href="([^"]+)"/', $pagination, $link) === 1, 'Next Arcade page link');
		parse_str(parse_url(html_entity_decode($link[1], ENT_QUOTES, 'UTF-8'), PHP_URL_QUERY), $query);
		as_check($query['mode'] === $mode && $query['start'] === '1' && $query['cat_id'] === '7' && $query['order'] === 'ASC',
			'Saved Arcade page retains mode, category and order');
		as_check($mode === 'search' ? $query['search_word'] === $search_term : !isset($query['search_word']), 'Only search links carry the exact term');
		$cases++;
	}
	echo 'Native Arcade catalogue: '.$cases." strict/ordinary SQL mode and link cases passed; counts, scores, favorite scopes and pagination.\n";
}
