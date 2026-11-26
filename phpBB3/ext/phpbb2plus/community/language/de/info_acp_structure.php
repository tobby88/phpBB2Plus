<?php
if (!defined('IN_PHPBB')) { exit; }
$lang = array_merge($lang ?? array(), array(
	'ACP_PLUS_STRUCTURE'=>'Reparierte Forenstruktur',
	'ACP_PLUS_STRUCTURE_HELP'=>'Diese Liste zeigt, welche Einträge der Konverter neu zugeordnet hat. Vergleiche die ursprüngliche Zuordnung mit der heutigen Position im Forum.',
	'ACP_PLUS_STRUCTURE_FORUMS'=>'Foren und Kategorien', 'ACP_PLUS_STRUCTURE_TOPICS'=>'Themen', 'ACP_PLUS_STRUCTURE_POSTS'=>'Beiträge',
	'ACP_PLUS_STRUCTURE_ITEM'=>'Eintrag', 'ACP_PLUS_STRUCTURE_PREVIOUS'=>'In phpBB2 Plus', 'ACP_PLUS_STRUCTURE_CURRENT'=>'Aktuelle Position',
	'ACP_PLUS_STRUCTURE_REASON'=>'Grund', 'ACP_PLUS_STRUCTURE_EMPTY'=>'Für diesen Bereich sind keine Reparaturen verzeichnet.',
	'ACP_PLUS_STRUCTURE_CATEGORY'=>'Kategorie %d', 'ACP_PLUS_STRUCTURE_FORUM'=>'Forum %d', 'ACP_PLUS_STRUCTURE_TOPIC'=>'Thema %d',
	'ACP_PLUS_STRUCTURE_POST'=>'Beitrag %d', 'ACP_PLUS_STRUCTURE_PARENT'=>'Unterhalb von Forum oder Kategorie %d',
	'ACP_PLUS_STRUCTURE_POSITION'=>'Forum %1$d, Thema %2$d', 'ACP_PLUS_STRUCTURE_GLOBAL'=>'Globale Bekanntmachung',
	'ACP_PLUS_STRUCTURE_MISSING_PARENT'=>'Übergeordneter Eintrag fehlt', 'ACP_PLUS_STRUCTURE_CYCLE'=>'Kreis in der Forenstruktur',
	'ACP_PLUS_STRUCTURE_MISSING_FORUM'=>'Ursprüngliches Forum fehlt', 'ACP_PLUS_STRUCTURE_MISSING_TOPIC'=>'Ursprüngliches Thema fehlt',
	'ACP_PLUS_STRUCTURE_MOVED_TOPIC'=>'Das ursprüngliche Thema war ein Verschiebeverweis.',
	'ACP_PLUS_STRUCTURE_POST_FORUM'=>'Der Beitrag stand in einem anderen Forum als sein Thema.',
	'ACP_PLUS_STRUCTURE_POST_TOPIC'=>'Das ursprüngliche Thema fehlte oder war ein Verschiebeverweis.',
));
