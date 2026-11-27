<?php
if (!defined('IN_PHPBB')) { exit; }
$lang = array_merge($lang ?? array(), array(
	'ACP_PLUS_POST_REFERENCES'=>'Beitrags- und Themenverweise prüfen',
	'PLUS_REFS_HELP'=>'Prüfe in der aktuellen Datenbank die Zuordnung von Beiträgen, Themen und Verfassern sowie die Verweise auf verschobene Themen. Der Bericht liest die Datensätze und zeigt pro Seite bis zu 200 geprüfte IDs. Die automatische Prüfung hält auf der nächsten Seite mit Auffälligkeiten.',
	'PLUS_REFS_SUMMARY'=>array(1=>'%1$d Datensatz auf dieser Seite geprüft, davon %2$d mit Auffälligkeiten.', 2=>'%1$d Datensätze auf dieser Seite geprüft, davon %2$d mit Auffälligkeiten.'),
	'PLUS_REFS_RANGE'=>'IDs %1$d–%2$d.', 'PLUS_REFS_PROBLEM'=>'Auffälligkeit',
	'PLUS_REFS_NEXT'=>'Nächste Seite prüfen', 'PLUS_REFS_AUTO'=>'Bis zur nächsten Auffälligkeit weiterprüfen',
	'PLUS_REFS_STOP'=>'Auf dieser Seite anhalten', 'PLUS_REFS_RESTART'=>'Diesen Bereich von vorn prüfen',
	'PLUS_REFS_DONE'=>'Das Ende dieses Bereichs ist erreicht.',
	'PLUS_REFS_FORUM'=>'Unter der Foren-ID %d gibt es kein Forum für Beiträge.',
	'PLUS_REFS_AUTHOR'=>'Zur Verfasser-ID %d gibt es kein Benutzerkonto.',
	'PLUS_REFS_TOPIC'=>'Zur Themen-ID %d gibt es kein Thema.',
	'PLUS_REFS_POST_SHADOW'=>'Der Beitrag gehört zum Thema mit ID %d, das einen Verschiebeverweis hat.',
	'PLUS_REFS_ROUTE'=>'Der Beitrag verweist auf Forum %1$d. Sein Thema (ID %2$d) gehört zu Forum %3$d.',
	'PLUS_REFS_MISSING_TARGET'=>'Der Verschiebeverweis zeigt auf die Themen-ID %d, zu der es kein Thema gibt.',
	'PLUS_REFS_SELF_TARGET'=>'Der Verschiebeverweis führt zurück zu diesem Thema.',
	'PLUS_REFS_CHAINED_TARGET'=>'Der Verschiebeverweis führt zu Thema %d, das selbst einen Verschiebeverweis hat.',
	'PLUS_REFS_MOVE_FLAG'=>'Das Thema verweist auf die Ziel-ID %d. Die Kennzeichnung als Verschiebeverweis fehlt.',
	'PLUS_REFS_EMPTY_TOPIC'=>'Zu diesem Thema gibt es keinen Beitrag.',
));
