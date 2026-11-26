<?php
if (!defined('IN_PHPBB')) { exit; }
$lang = array_merge($lang ?? array(), array(
	'ACP_PLUS_REG_NETWORK' => 'Registrierungsadresse nachschlagen',
	'ACL_A_PLUS_REG_NETWORK' => 'Kann archivierte Registrierungs-Hostnamen und Konten mit derselben Registrierungsadresse sehen',
	'PLUS_REG_ADDRESS' => 'IP-Adresse bei der Registrierung',
	'PLUS_REG_HOST' => 'Archivierter Hostname bei der Registrierung',
	'PLUS_REG_UNKNOWN_IP' => 'Keine Registrierungs-IP gespeichert',
	'PLUS_REG_UNKNOWN_HOST' => 'Kein Hostname überliefert',
	'PLUS_REG_MATCHES' => 'Konten mit dieser Registrierungs-IP',
	'PLUS_REG_NONE' => 'Keine passenden Konten auf dieser Seite.',
	'PLUS_REG_NO_USER' => 'Kein passendes Konto gefunden.',
));
