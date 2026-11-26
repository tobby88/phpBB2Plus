<?php
if (!defined('IN_PHPBB')) { exit; }
$lang = array_merge($lang ?? array(), array(
	'PLUS_ATTACH_RULES_LINK' => 'Erlaubte Dateitypen und Größen',
	'PLUS_ATTACH_UPLOAD_POLICY' => 'Dateiendungen für neue Anhänge',
	'PLUS_ATTACH_UPLOAD_POLICY_EXPLAIN' => 'Plus erlaubt Endungen mit bis zu 16 Buchstaben oder Ziffern und schließt ausführbare Skripttypen aus. Die Beschränkung gilt für neue Anhänge in Beiträgen und privaten Nachrichten. Für vorhandene Dateien gelten weiterhin die bisherigen Downloadberechtigungen.',
	'PLUS_ATTACH_ON_IMPORT' => 'Native Dateigruppen verwenden, bis ein Plus-Forum importiert wird',
	'PLUS_ATTACH_GROUPS_ONLY' => 'Nur native Dateigruppen verwenden',
	'PLUS_ATTACH_INHERIT_TYPES' => 'Plus-Beschränkung auf native Dateigruppen anwenden',
	'PLUS_ATTACH_POST_RULES' => 'Anhangregeln für dieses Forum',
	'PLUS_ATTACH_PM_RULES' => 'Anhangregeln für private Nachrichten',
	'PLUS_ATTACH_GROUP' => 'Dateigruppe', 'PLUS_ATTACH_TYPES' => 'Dateiendungen', 'PLUS_ATTACH_FILE_LIMIT' => 'Höchstgröße je Datei',
	'PLUS_ATTACH_NO_LIMIT' => 'Kein Größenlimit des Forums', 'PLUS_ATTACH_NO_TYPES' => 'Hier sind derzeit keine Dateitypen für Anhänge freigegeben.',
	'PLUS_HELP_FILE_EDIT'=>'Wie füge ich einem Beitrag eine Datei hinzu oder ändere einen Anhang?',
	'PLUS_HELP_FILE_EDIT_ANSWER'=>'Öffne den vollständigen Beitragseditor und wähle im Reiter „Dateianhänge“ die Datei aus. Dort kannst du einen Dateikommentar eintragen. Solange du den Beitrag bearbeiten darfst, kannst du im selben Reiter weitere Dateien hinzufügen, Kommentare ändern oder einzelne Anhänge löschen. Sende den Beitrag anschließend ab.',
	'PLUS_HELP_FILE_INLINE'=>'Wie zeige ich einen Anhang an einer bestimmten Stelle im Beitrag?',
	'PLUS_HELP_FILE_INLINE_ANSWER'=>'Lade die Datei hoch, setze den Cursor an die gewünschte Textstelle und wähle beim Anhang „Im Beitrag anzeigen“. Lass die eingefügte Markierung im Text stehen. Weitere Anhänge erscheinen unter dem Beitrag. In der Vorschau kannst du die Platzierung überprüfen.',
	'PLUS_HELP_FILE_ACCESS'=>'Wer kann einen Dateianhang aus einem Beitrag herunterladen?',
	'PLUS_HELP_FILE_ACCESS_ANSWER'=>'Für den Download braucht der Leser Zugriff auf den Beitrag und die Downloadberechtigung im betreffenden Forum. Wenn du den Beitrag lesen kannst und der Download trotzdem gesperrt ist, wende dich mit dem Beitragslink an die Moderation.',
	'PLUS_HELP_FILE_PM'=>'Wie versende ich eine Datei in einer privaten Nachricht?',
	'PLUS_HELP_FILE_PM_ANSWER'=>'Nutze beim Verfassen der Nachricht den Reiter „Dateianhänge“, lade die Datei hoch und sende die Nachricht. Empfänger können die Datei in der Nachricht öffnen, wenn sie Dateianhänge in privaten Nachrichten herunterladen dürfen.',
	'PLUS_HELP_FILE_QUOTA'=>'Warum kann eine kleine Datei meinen verfügbaren Speicher überschreiten?',
	'PLUS_HELP_FILE_QUOTA_ANSWER'=>'Zusätzlich zur zulässigen Größe einer einzelnen Datei kann das Forum den Speicher für Beiträge und private Nachrichten getrennt begrenzen. Die Verwaltung kann deinem Konto oder deinen Gruppen ein Kontingent zuweisen. Bei privaten Nachrichten kann auch der verfügbare Speicher eines Empfängers eine Rolle spielen.',
));
