<?php
if (!defined('IN_PHPBB')) { exit; }
$lang = array_merge($lang ?? array(), array(
	'PLUS_PW_RULES' => 'Passwortregeln von phpBB2 Plus anwenden',
	'PLUS_PW_RULES_EXPLAIN' => 'Prüft neue Passwörter anhand der folgenden Plus-Regeln. Die Mindestzeichenzahl und die Komplexitätsregeln von phpBB gelten zusätzlich.',
	'PLUS_PW_MIN_BYTES' => 'Mindestlänge des Passworts in Bytes',
	'PLUS_PW_MIN_BYTES_EXPLAIN' => 'Zulässig sind Werte von 0 bis 72. Ein neues Passwort muss mindestens ein Zeichen enthalten. Die Plus-Obergrenze beträgt 72 UTF-8-Bytes. Anführungszeichen und umgekehrte Schrägstriche zählen doppelt.',
	'PLUS_PW_NO_NAME' => 'Kontonamen als Passwort ausschließen',
	'PLUS_PW_NO_NAME_EXPLAIN' => 'Schließt Passwörter aus, die einschließlich Groß- und Kleinschreibung genau dem Kontonamen entsprechen.',
	'PLUS_PW_LETTERS_DIGITS' => 'Einen Buchstaben und eine Dezimalziffer verlangen',
	'PLUS_PW_LETTERS_DIGITS_EXPLAIN' => 'Buchstaben und Dezimalziffern aller Schriftsysteme sind zulässig.',
	'PLUS_PW_BYTES_HINT' => 'Verwende %d bis 72 UTF-8-Bytes. Einfache und doppelte Anführungszeichen sowie umgekehrte Schrägstriche zählen doppelt.',
	'PLUS_PW_LENGTH_ERROR' => 'Das Passwort liegt außerhalb der zulässigen Länge in UTF-8-Bytes.',
	'PLUS_PW_NAME_ERROR' => 'Das Passwort muss sich vom Kontonamen unterscheiden.',
	'PLUS_PW_LETTER_DIGIT_ERROR' => 'Verwende mindestens einen Buchstaben und eine Dezimalziffer.',
	'PLUS_PW_INVALID' => 'Das Passwort enthält ein unzulässiges Zeichen.',
));
