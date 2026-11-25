<?php
if (!defined('IN_PHPBB')) { exit; }
$lang = array_merge($lang ?? array(),array(
	'PLUS_ATTACH_POST_QUOTA'=>'This file exceeds your storage allowance for post attachments.',
	'PLUS_ATTACH_SENT_QUOTA'=>'This file exceeds your storage allowance for private message attachments.',
	'PLUS_ATTACH_RECEIVED_QUOTA'=>'At least one recipient has insufficient storage for these attachments. You can edit the recipients or attachments and send the message again.',
));
