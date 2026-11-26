<?php
if (!defined('IN_PHPBB')) { exit; }
$lang=array_merge($lang ?? array(), array(
	'PLUS_FRIEND_BOARD'=>'Recommend this board',
	'PLUS_FRIEND_NOTE'=>'I thought you might like this forum.',
	'PLUS_FRIEND_BOARD_EXPLAIN'=>'The email includes the board address and your note. The recipient can reply to the email address on your account.',
	'PLUS_FRIEND_USED'=>'This recommendation has already been processed. Open a new form to send another message.',
	'PLUS_FRIEND_TEXT'=>'Use a recipient name of up to 100 characters on one line and a message of up to 10,000 characters.',
	'PLUS_FRIEND_FAILURE'=>'The email could not be sent. Open a new form to try again.',
	'PLUS_FRIEND_COPY_FAILED'=>'The recommendation was sent, but your copy could not be delivered.',
));
