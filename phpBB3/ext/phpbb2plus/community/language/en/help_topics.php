<?php
if (!defined('IN_PHPBB')) { exit; }
$lang = array_merge($lang ?? array(), array(
	'PLUS_HELP_POLL_VOTE' => 'Why can’t I vote in a poll?',
	'PLUS_HELP_POLL_VOTE_ANSWER' => 'You need permission to vote in the forum. Log in if guests cannot vote there. Check that the poll is still open and that neither the topic nor the forum is locked. If you have already voted, you can change your vote only when both the poll and your permissions allow it.',
));
