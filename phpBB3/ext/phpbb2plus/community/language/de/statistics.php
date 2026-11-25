<?php
if (!defined('IN_PHPBB')) { exit; }
$lang = array_merge($lang ?? array(), array(
	'PLUS_STATS_DECIMAL' => ',',
	'PLUS_STATS_FORUM_INDEX' => 'Zur Forenübersicht', 'PLUS_STATS_MOST_ACTIVE_TOPICS' => 'Themen mit den meisten Antworten',
	'PLUS_STATS_MOST_VIEWED_TOPICS' => 'Meistgelesene Themen', 'PLUS_STATS_TOP_POSTERS' => 'Mitglieder mit den meisten Beiträgen',
	'PLUS_STATS_SITE_HIST_WEEK_TOP_POSTERS' => 'Aktivste Mitglieder dieser Woche', 'PLUS_STATS_SITE_HIST_MONTH_TOP_POSTERS' => 'Aktivste Mitglieder dieses Monats',
	'PLUS_STATS_RANK' => 'Rang', 'PLUS_STATS_SHARE_TOTAL' => 'Anteil an den sichtbaren Mitgliedsbeiträgen', 'PLUS_STATS_SHARE_RANKED' => 'Anteil innerhalb dieser Rangliste',
	'PLUS_STATS_NO_ROWS' => 'Für diese Auswertung liegen keine sichtbaren Einträge vor.',
	'PLUS_STATS_NO_MODULES' => 'Für diesen Zugriff stehen keine Auswertungen zur Verfügung.', 'PLUS_STATS_VIEWS' => 'Aufrufe der Statistikseite: %d',
	'PLUS_STATS_INSTALLED' => 'Statistik seit %s', 'PLUS_STATS_VERSION' => 'Ursprüngliche Statistik-Modversion: %s',
));
