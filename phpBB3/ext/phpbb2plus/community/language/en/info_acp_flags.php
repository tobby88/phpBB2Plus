<?php
if (!defined('IN_PHPBB')) { exit; }
$lang = array_merge(isset($lang) ? $lang : array(), array(
	'ACP_PLUS_FLAGS'=>'Country flags', 'ACP_PLUS_FLAGS_EXPLAIN'=>'Manage the country choices available in member profiles.',
	'ACP_PLUS_FLAG_LABEL'=>'Country or region', 'ACP_PLUS_FLAG_CREATE'=>'Add country flag', 'ACP_PLUS_FLAG_EDIT'=>'Edit country flag',
	'ACP_PLUS_FLAG_DELETE'=>'Remove country flag', 'ACP_PLUS_FLAG_CONFIRM'=>'Remove “%s” from the profile choices? Members using it keep a remaining entry for the same image, if available.',
	'ACP_PLUS_FLAG_IMAGE_HELP'=>'Enter an existing flag filename, or place a GIF, PNG or JPEG in images/plusflags. Leave this empty for a text-only choice.',
	'ACP_PLUS_FLAG_IMAGE_INVALID'=>'Use an existing flag filename of up to 25 characters, or leave the filename empty.',
	'ACP_PLUS_FLAG_NAME_INVALID'=>'Enter a country or region name of up to 25 characters.',
	'ACP_PLUS_FLAG_CHANGED'=>'The catalogue changed. Open the flag form again.',
	'ACP_PLUS_FLAG_NATIVE'=>'A native profile field setting or translation was changed separately. Check the country dropdown in the custom profile field administration.',
));
