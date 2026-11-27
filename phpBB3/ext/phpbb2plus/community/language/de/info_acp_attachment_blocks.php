<?php
if (!defined('IN_PHPBB')) { exit; }
$lang = array_merge($lang ?? array(), array(
	'ACP_PLUS_ATTACH_BLOCKS'=>'Verbotene Dateiendungen für Anhänge',
	'ACL_A_PLUS_ATTACH_BLOCK'=>'Kann verbotene Dateiendungen für Anhänge verwalten',
	'PLUS_ATTACH_BLOCK_EXPLAIN'=>'Importierte und hier eingetragene Sperren gelten in allen Upload-Modi für neue Anhänge in Beiträgen und privaten Nachrichten. Ist der zusätzliche Plus-Schutz in den Anhang-Einstellungen aktiv, erscheinen auch seine festen Sperren. Sie erfassen unter anderem alle nummerierten PHP-Endungen. Vorhandene Anhänge behalten ihre Download-Einstellungen.',
	'PLUS_ATTACH_BLOCK_ADD'=>'Eine Dateiendung sperren',
	'PLUS_ATTACH_BLOCK_STATUS'=>'Regel',
	'PLUS_ATTACH_BLOCK_CUSTOM'=>'Benutzerdefinierte Sperre',
	'PLUS_ATTACH_BLOCK_FIXED'=>'Fester Schutz',
	'PLUS_ATTACH_BLOCK_HISTORICAL'=>'Historische Schreibweise außerhalb des unterstützten Formats',
	'PLUS_ATTACH_BLOCK_CONFLICT'=>'Auch in der nativen Dateiendungsliste eingetragen. Neue Uploads sind gesperrt.',
	'PLUS_ATTACH_BLOCK_INVALID'=>'Gib eine neue Dateiendung mit 1 bis 16 Buchstaben oder Ziffern ein.',
	'PLUS_ATTACH_BLOCK_ALLOWED'=>'Diese Endung ist bereits in der nativen Dateiendungsliste eingetragen. Entferne sie dort, bevor du sie sperrst.',
	'PLUS_ATTACH_BLOCK_REFUSED'=>'Diese Dateiendung ist für Anhänge gesperrt. Prüfe ihren Eintrag unter „Verbotene Dateiendungen für Anhänge“.',
	'PLUS_ATTACH_BLOCK_CHANGED'=>'Die Dateiendungen wurden inzwischen geändert. Lade die Liste neu und versuche es erneut.',
	'LOG_PLUS_ATTACH_BLOCK'=>'<strong>Dateiendung für Anhänge gesperrt</strong><br>» %s',
	'LOG_PLUS_ATTACH_UNBLOCK'=>'<strong>Benutzerdefinierte Endungssperre für Anhänge entfernt</strong><br>» %s',
));
