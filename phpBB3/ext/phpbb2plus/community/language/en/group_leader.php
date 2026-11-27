<?php
if (!defined('IN_PHPBB')) { exit; }
$lang = array_merge($lang ?? array(), array(
	'PLUS_LEADER_EXPLAIN'=>'You are a designated leader of this group. You can manage memberships and change the group type here.',
	'PLUS_LEADER_MEMBERS'=>'Members and applications',
	'PLUS_LEADER_PENDING'=>'Application pending',
	'PLUS_LEADER_APPROVED'=>'Member',
	'PLUS_LEADER_ACCEPT'=>'Accept applications', 'PLUS_LEADER_DENY'=>'Decline applications', 'PLUS_LEADER_REMOVE'=>'Remove members',
	'PLUS_LEADER_EMPTY'=>'There are currently no other members or applications.',
	'PLUS_LEADER_SELECTION'=>'Check your selection and the current membership status.',
));
