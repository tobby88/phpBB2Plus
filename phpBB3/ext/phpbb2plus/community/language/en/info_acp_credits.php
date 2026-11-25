<?php
if (!defined('IN_PHPBB')) { exit; }
$lang = array_merge(isset($lang) ? $lang : array(), array(
	'ACP_PLUS_CREDITS' => 'Historical MOD credits',
	'ACP_PLUS_CREDIT_EXPLAIN' => 'Manage credits from phpBB2 and add missing attributions here. The public page also lists active phpBB3 Plus extensions from their package metadata.',
	'ACP_PLUS_CREDIT_CREATE' => 'Add a credit', 'ACP_PLUS_CREDIT_EDIT' => 'Edit credit', 'ACP_PLUS_CREDIT_DELETE' => 'Delete credit',
	'ACP_PLUS_CREDIT_CONFIRM' => 'Permanently remove “%s” from the historical credits?',
	'ACP_PLUS_CREDIT_CHANGED' => 'The credits have changed. Please reopen the form.',
	'ACP_PLUS_CREDIT_DUPLICATE' => 'A credit with this name already exists.',
	'ACP_PLUS_CREDIT_INVALID' => 'Check the field lengths and enter a name, description and author.',
	'ACP_PLUS_CREDIT_LINK' => 'Enter a valid email address and use full HTTP or HTTPS addresses for web links.',
	'ACP_PLUS_CREDIT_VISIBLE' => 'Show publicly', 'ACP_PLUS_CREDIT_EMPTY' => 'There are no historical entries yet.',
	'ACP_PLUS_CREDIT_TITLE' => 'Name', 'ACP_PLUS_CREDIT_DESCRIPTION' => 'Description', 'ACP_PLUS_CREDIT_AUTHOR' => 'Author',
	'ACP_PLUS_CREDIT_CONTACT_EMAIL' => 'Author email address', 'ACP_PLUS_CREDIT_AUTHOR_WEBSITE' => 'Author website',
	'ACP_PLUS_CREDIT_VERSION_TEXT' => 'Version', 'ACP_PLUS_CREDIT_DOWNLOAD_URL' => 'Download address',
));
