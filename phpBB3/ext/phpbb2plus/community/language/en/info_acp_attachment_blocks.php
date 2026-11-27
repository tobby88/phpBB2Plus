<?php
if (!defined('IN_PHPBB')) { exit; }
$lang = array_merge($lang ?? array(), array(
	'ACP_PLUS_ATTACH_BLOCKS'=>'Forbidden attachment extensions',
	'ACL_A_PLUS_ATTACH_BLOCK'=>'Can manage forbidden attachment extensions',
	'PLUS_ATTACH_BLOCK_EXPLAIN'=>'These extensions are blocked for new post and private message attachments. Existing attachments keep their download settings. Built-in rules also cover every numbered PHP extension.',
	'PLUS_ATTACH_BLOCK_ADD'=>'Add a forbidden extension',
	'PLUS_ATTACH_BLOCK_STATUS'=>'Rule',
	'PLUS_ATTACH_BLOCK_CUSTOM'=>'Custom block',
	'PLUS_ATTACH_BLOCK_FIXED'=>'Built-in protection',
	'PLUS_ATTACH_BLOCK_HISTORICAL'=>'Historical spelling outside the supported extension format',
	'PLUS_ATTACH_BLOCK_CONFLICT'=>'Also configured in the native extension list. New uploads are blocked.',
	'PLUS_ATTACH_BLOCK_INVALID'=>'Enter a new extension using 1 to 16 letters or digits.',
	'PLUS_ATTACH_BLOCK_ALLOWED'=>'This extension is already configured in the native extension list. Remove it there before adding a block.',
	'PLUS_ATTACH_BLOCK_REFUSED'=>'This attachment extension is forbidden. Check its entry in the forbidden extensions list.',
	'PLUS_ATTACH_BLOCK_CHANGED'=>'The extension settings have changed. Reload the list and try again.',
	'LOG_PLUS_ATTACH_BLOCK'=>'<strong>Blocked attachment extension</strong><br>» %s',
	'LOG_PLUS_ATTACH_UNBLOCK'=>'<strong>Removed custom attachment extension block</strong><br>» %s',
));
