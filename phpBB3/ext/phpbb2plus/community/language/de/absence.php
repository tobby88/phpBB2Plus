<?php
if (!defined('IN_PHPBB')) { exit; }
$lang=array_merge($lang ?? array(),array(
	'ACL_U_PLUS_AWAY_MOD'=>'Kann Moderatoren-Abwesenheitsrechte nutzen und bei aktivierter Option abwesende Mitglieder kontaktieren',
	'ACP_PLUS_ABSENCE'=>'Abwesenheit eines Mitglieds','PLUS_AWAY'=>'Abwesend','PLUS_AWAY_STATUS'=>'Erreichbarkeit','PLUS_AWAY_KIND'=>'Grund der Abwesenheit',
	'PLUS_AWAY_AVAILABLE'=>'Erreichbar','PLUS_AWAY_UNSPECIFIED'=>'Ohne Angabe','PLUS_AWAY_HOLIDAY'=>'Im Urlaub',
	'PLUS_AWAY_ILL'=>'Erkrankt','PLUS_AWAY_LONG'=>'Länger abwesend','PLUS_AWAY_NOTE'=>'Abwesenheitsnachricht',
	'PLUS_AWAY_NOTE_EXPLAIN'=>'Während deiner Abwesenheit können angemeldete Mitglieder diese Nachricht in deinem Profil oder bei der direkten Kontaktaufnahme lesen. Lasse sie leer, um nur deinen Status anzuzeigen.',
	'PLUS_AWAY_EDIT'=>'Abwesenheit bearbeiten','PLUS_AWAY_RETURN'=>'Abwesenheit beenden','PLUS_AWAY_REMINDER'=>'Dein Konto ist als abwesend markiert.',
	'PLUS_AWAY_SAVED'=>'Die Abwesenheitseinstellungen wurden gespeichert.','PLUS_AWAY_RETURNED'=>'Die Abwesenheit wurde beendet.',
	'PLUS_AWAY_CHANGED'=>'Die Abwesenheitseinstellungen wurden inzwischen geändert. Prüfe die aktuellen Angaben vor dem Speichern.',
	'PLUS_AWAY_INVALID'=>'Wähle einen gültigen Status und Grund. Die Nachricht darf bis zu 65.535 UTF-8-Bytes Text enthalten.',
	'PLUS_AWAY_MEMBERS'=>'Mitgliedern erlauben, eine Abwesenheit einzustellen','PLUS_AWAY_MEMBERS_EXPLAIN'=>'Administratoren und Moderatoren können sich jederzeit selbst als abwesend markieren.',
	'PLUS_AWAY_MOD_CONTACT'=>'Moderatoren die Kontaktaufnahme mit Abwesenden erlauben','PLUS_AWAY_MOD_CONTACT_EXPLAIN'=>'Gilt für private Nachrichten und Profil-E-Mails. Administratoren können abwesende Mitglieder jederzeit kontaktieren.',
	'PLUS_AWAY_BY_NAME'=>'Abwesenheit neben dem Namen anzeigen','PLUS_AWAY_BY_NAME_EXPLAIN'=>'Bei deaktivierter Einstellung zeigen Beiträge den Status bei den Kontaktdaten.',
));
