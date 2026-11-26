<?php
// Called only for disposable source and destination schemas.
function seed_forum_recovery($db)
{
	$db->query("INSERT INTO phpbb_categories (cat_id,cat_title,cat_order,cat_main_type,cat_main) VALUES
		(71,'Loop A',1,'c',72),(72,'Loop B',2,'c',71),(73,'Detached category',3,'f',60001)");
	$db->query("INSERT INTO phpbb_forums (forum_id,forum_name,cat_id,main_type,auth_view,auth_read) VALUES
		(51,'Recovered public room',800,'c',0,0),(52,'Private child fixture',51,'f',5,5),(53,'Private loop fixture',72,'c',5,5)");
}

function verify_forum_recovery($db, $damaged)
{
	$rows = $db->query('SELECT * FROM p3_forums WHERE plus_recovery_role=1')->fetch_all(MYSQLI_ASSOC);
	if (!$damaged) { check_fixture(!$rows, 'An intact board received a recovery category'); return; }
	check_fixture(count($rows) === 1 && $rows[0]['forum_name'] === 'Wiederhergestellte Foren' && (int) $rows[0]['forum_id'] > 65535,
		'Unexpected recovery category ' . json_encode(array_column($rows, 'forum_name', 'forum_id')));
	$root = (int) $rows[0]['forum_id'];
	$layout = $db->query("SELECT c.forum_name,p.forum_name,c.plus_recovery_reason,c.plus_recovery_parent
		FROM p3_forums c JOIN p3_forums p ON p.forum_id=c.parent_id AND c.left_id>p.left_id AND c.right_id<p.right_id
		WHERE c.forum_id IN (51,52,53) OR c.plus_legacy_category IN (71,72,73) ORDER BY c.forum_name")->fetch_all();
	check_fixture($layout === array(
		array('Detached category','Wiederhergestellte Foren','missing-parent','f60001'),
		array('Loop A','Wiederhergestellte Foren','cycle','c72'), array('Loop B','Loop A','',''),
		array('Private child fixture','Recovered public room','',''), array('Private loop fixture','Loop B','',''),
		array('Recovered public room','Wiederhergestellte Foren','missing-parent','c800')),
		'The tree repair lost a valid edge, its bounds or the original damaged relationship');
	check_fixture((int) $db->query("SELECT COUNT(*) FROM p3_forums WHERE parent_id=$root AND plus_recovery_reason<>''")->fetch_row()[0] === 3,
		'Each damaged branch needs exactly one recovered root');
}
