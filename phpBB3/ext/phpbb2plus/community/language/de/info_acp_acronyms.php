<?php
if (!defined('IN_PHPBB')) { exit; }
$lang = array_merge(isset($lang) ? $lang : array(), array(
	'ACP_PLUS_ACRONYMS' => 'Abkürzungen',
	'ACL_A_PLUS_ACRONYMS' => 'Kann das Wörterbuch für Abkürzungen verwalten',
	'PLUS_ACRONYM_EXPLAIN' => 'Das Forum erläutert diese Abkürzungen in Beiträgen, Nachrichtenartikeln und der Wissensdatenbank. Die Groß- und Kleinschreibung muss übereinstimmen.',
	'PLUS_ACRONYM_TERM' => 'Abkürzung', 'PLUS_ACRONYM_MEANING' => 'Bedeutung', 'PLUS_ACRONYM_ADD' => 'Abkürzung hinzufügen',
	'PLUS_ACRONYM_DELETE' => 'Soll die Abkürzung „%s“ aus dem Wörterbuch entfernt werden?',
	'PLUS_ACRONYM_EMPTY' => 'Das Wörterbuch ist leer.',
	'PLUS_ACRONYM_STALE' => 'Der Eintrag hat sich geändert. Bitte öffne das Wörterbuch erneut.',
	'PLUS_ACRONYM_INVALID' => 'Gib eine Abkürzung mit höchstens 80 Zeichen und eine Bedeutung mit höchstens 255 Zeichen ein.',
	'PLUS_ACRONYM_EXISTS' => 'Diese Abkürzung ist bereits eingetragen.',
));
