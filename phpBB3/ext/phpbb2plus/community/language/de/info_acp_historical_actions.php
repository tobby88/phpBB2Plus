<?php
if (!defined('IN_PHPBB')) { exit; }
$actions = array('DELETE' => 'Thema #%1$d gelöscht', 'MOVE' => 'Thema #%1$d verschoben', 'LOCK' => 'Thema #%1$d gesperrt',
	'UNLOCK' => 'Thema #%1$d entsperrt', 'SPLIT' => 'Thema #%1$d geteilt', 'MERGE' => 'Thema #%1$d zusammengeführt',
	'EDIT' => 'Beitrag in Thema #%1$d bearbeitet', 'ANNOUNCE' => 'Thema #%1$d als Ankündigung markiert',
	'STICKY' => 'Thema #%1$d als wichtig markiert', 'NORMAL' => 'Thema #%1$d als normales Thema markiert',
	'OTHER' => 'Aktion „%5$s“ zu Thema #%1$d');
foreach ($actions as $key => $action)
{
	$lang['LOG_PLUS_ARCHIVE_' . $key] = $action . '. In phpBB2 unter „%2$s“ aufgezeichnet (Konto %3$d, Eintrag %4$d).';
}
