<?php
if (!defined('IN_PHPBB')) { exit; }
$lang = array_merge($lang ?? array(), array(
	'PLUS_GROUP_STEP_DOWN'=>'Step down as group leader before leaving the group.',
	'PLUS_GROUP_STATE_CHANGED'=>'Your membership or the group settings have changed. Open your memberships again.',
	'PLUS_GROUP_CANCEL_NAMED'=>'Withdraw your application to join “%s”?',
	'PLUS_GROUP_LEAVE_NAMED'=>'Leave the group “%s”?',
	'PLUS_LEADER_EXPLAIN'=>'You are a designated leader of this group. You can manage memberships and change the group type here.',
	'PLUS_LEADER_MEMBERS'=>'Members and applications',
	'PLUS_LEADER_PENDING'=>'Application pending',
	'PLUS_LEADER_APPROVED'=>'Member',
	'PLUS_LEADER_ACCEPT'=>'Accept applications', 'PLUS_LEADER_DENY'=>'Decline applications', 'PLUS_LEADER_REMOVE'=>'Remove members',
	'PLUS_LEADER_EMPTY'=>'There are currently no other members or applications.',
	'PLUS_LEADER_SELECTION'=>'Check your selection and the current membership status.',
));
