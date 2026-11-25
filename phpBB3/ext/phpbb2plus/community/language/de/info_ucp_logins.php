<?php
if (!defined('IN_PHPBB')) { exit; }
$lang = array_merge($lang ?? array(), array(
	'UCP_PLUS_LOGINS' => 'Meine Anmeldungen',
	'PLUS_LOGIN_SETTINGS' => 'Persönlicher Anmeldeverlauf',
	'PLUS_LOGIN_WHEN' => 'Zeitpunkt', 'PLUS_LOGIN_ADDRESS' => 'IP-Adresse',
	'PLUS_LOGIN_EMPTY' => 'Für dein Konto sind noch keine Anmeldungen gespeichert.',
	'PLUS_LOGIN_HISTORY_OFF' => 'Der Anmeldeverlauf ist für dieses Board ausgeschaltet.',
	'PLUS_LOGIN_UNKNOWN_IP' => 'Unbekannt',
	'PLUS_LOGIN_CHANGED' => 'Das Netz deiner letzten Anmeldung hat sich von %1$s auf %2$s geändert.',
	'PLUS_LOGIN_PREFERENCE' => 'Hinweis bei der Anmeldung aus einem anderen Netz anzeigen',
	'PLUS_LOGIN_ACKNOWLEDGE' => 'Hinweis als gelesen markieren',
	'PLUS_LOGIN_CHANGED_AGAIN' => 'Der Hinweis oder die Einstellung hat sich inzwischen geändert. Öffne „Meine Anmeldungen“ erneut.',
	'PLUS_LOGIN_RECORD' => 'Anmeldeverlauf speichern',
	'PLUS_LOGIN_RECORD_EXPLAIN' => 'Mitglieder können Zeitpunkt und IP-Adresse ihrer eigenen erfolgreichen Anmeldungen sehen.',
	'PLUS_LOGIN_LIMIT' => 'Gespeicherte Anmeldungen je Konto',
	'PLUS_LOGIN_LIMIT_EXPLAIN' => '1 bis 60 Einträge. Bei der nächsten Anmeldung des Mitglieds werden ältere Einträge über diesem Limit entfernt.',
	'PLUS_LOGIN_NETWORK' => 'Hinweis bei Netzwechsel ermöglichen',
	'PLUS_LOGIN_NETWORK_EXPLAIN' => 'Vergleicht die IP-Netze der letzten beiden Anmeldungen. Mitglieder können den Hinweis im persönlichen Bereich ein- oder ausschalten.',
));
