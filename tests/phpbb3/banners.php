<?php
// Synthetic source rows for conversion and click-accounting assertions.
function seed_banners($db)
{
	$db->query("UPDATE phpbb_banner SET banner_type=2,banner_name='Archiv &amp; Freunde',banner_description='Zu den Partnern',
		banner_url='https://example.invalid/partner?a=1&amp;b=2',banner_filter=1,banner_filter_time=600,
		banner_click=18446744073709551613,banner_view=18446744073709551612 WHERE banner_id=5");
	$db->query("INSERT INTO phpbb_banner (banner_id,banner_name,banner_spot,banner_forum,banner_description,banner_url,banner_active,
		banner_timetype,time_begin,time_end,date_begin,date_end,banner_level,banner_level_type,banner_type,banner_width,banner_height)
		VALUES (20,'Clubpartner',13,7,'Club','https://example.invalid/club',1,0,0,0,0,0,-1,2,2,0,0),
		(21,'Abgelaufener Hinweis',2,0,'Termin','https://example.invalid/past',1,6,0,2359,19980101,19980102,-1,2,2,0,0),
		(22,'Mitgliederlink',4,0,'Mitglieder','https://example.invalid/members',1,0,0,0,0,0,0,2,2,0,0),
		(23,'games/puzzle/puzzle.swf',15,7,'Clubfilm','https://example.invalid/movie',1,0,0,0,0,0,-1,2,6,160,120)");
	$db->query("INSERT INTO phpbb_banner_stats VALUES (5,10000,'c0000205',1,19),(5,10000,'c0000205',1,19),(90,10000,'c0000205',1,3)");
	$db->query('ALTER TABLE phpbb_banner AUTO_INCREMENT=120');
}

function verify_banners($db, $member)
{
	require_once __DIR__ . '/../../phpBB3/ext/phpbb2plus/banners/service/catalog.php';
	$night = array('time_mode'=>4, 'time_begin'=>2230, 'time_end'=>115, 'date_begin'=>5, 'date_end'=>1);
	foreach (array('2024-03-01 22:30'=>true,'2024-03-03 01:15'=>true,'2024-03-04 01:16'=>false,'2024-03-05 23:00'=>false) as $when=>$expected)
	{
		check_fixture(\phpbb2plus\banners\service\catalog::scheduled($night, new DateTimeImmutable($when, new DateTimeZone('Europe/Berlin'))) === $expected,
			'Weekly banner hours or weekdays changed at ' . $when);
	}
	$date = array('time_mode'=>6,'time_begin'=>0,'time_end'=>2359,'date_begin'=>20240229,'date_end'=>20240301);
	foreach (array('2024-02-28 23:59'=>false,'2024-02-29 00:00'=>true,'2024-03-01 23:59'=>true,'2024-03-02 00:00'=>false) as $when=>$expected)
	{
		check_fixture(\phpbb2plus\banners\service\catalog::scheduled($date, new DateTimeImmutable($when, new DateTimeZone('UTC'))) === $expected,
			'Date-specific banner hours changed at ' . $when);
	}
	$banner = $db->query('SELECT clicks,asset_bytes FROM p3_plus_banners WHERE banner_id=5')->fetch_assoc();
	$picture = $db->query('SELECT asset_mime,asset_bytes FROM p3_plus_banners WHERE banner_id=2')->fetch_assoc();
	$movie = $db->query('SELECT asset_mime,asset_bytes FROM p3_plus_banners WHERE banner_id=23')->fetch_assoc();
	check_fixture($banner && $banner['clicks'] === '18446744073709551613.00' && (int) $banner['asset_bytes'] === 0 &&
		$picture['asset_mime'] === 'image/gif' && (int) $picture['asset_bytes'] > 0 &&
		$movie['asset_mime'] === 'application/x-shockwave-flash' && (int) $movie['asset_bytes'] > 0, 'Banner counters or protected artwork changed');
	check_fixture((int) $db->query("SELECT COUNT(*) FROM p3_plus_banner_hits WHERE banner_id=5 AND hit_time=10000
		AND user_id=" . (int) $member . " AND source_user=1 AND ip='192.0.2.5' AND duration=19")->fetch_row()[0] === 2,
		'A historical banner click disappeared or changed owner');
	$next = $db->query("SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='p3_plus_banners'")->fetch_row();
	check_fixture((int) $next[0] >= 120,'Conversion lost the banner allocation floor');
}
