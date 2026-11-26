<?php
if (!defined('IN_PHPBB')) { exit; }
$lang=array_merge($lang ?? array(),array(
	'ACL_U_PLUS_AWAY_MOD'=>'Can use moderator absence settings and contact absent members when enabled',
	'ACP_PLUS_ABSENCE'=>'Member absence','PLUS_AWAY'=>'Away','PLUS_AWAY_STATUS'=>'Availability','PLUS_AWAY_KIND'=>'Reason for absence',
	'PLUS_AWAY_AVAILABLE'=>'Available','PLUS_AWAY_UNSPECIFIED'=>'Unspecified','PLUS_AWAY_HOLIDAY'=>'On holiday',
	'PLUS_AWAY_ILL'=>'Off sick','PLUS_AWAY_LONG'=>'Away for an extended period','PLUS_AWAY_NOTE'=>'Absence message',
	'PLUS_AWAY_NOTE_EXPLAIN'=>'While you are away, signed-in members can read this message on your profile or when contacting you directly. Leave it empty to show only your status.',
	'PLUS_AWAY_EDIT'=>'Edit absence','PLUS_AWAY_RETURN'=>'Mark as returned','PLUS_AWAY_REMINDER'=>'Your account is currently marked as away.',
	'PLUS_AWAY_SAVED'=>'The absence settings have been saved.','PLUS_AWAY_RETURNED'=>'The absence has ended.',
	'PLUS_AWAY_CHANGED'=>'The absence settings have already changed. Review the current settings before saving.',
	'PLUS_AWAY_INVALID'=>'Select an available status and reason. The message must be valid text of up to 65,535 UTF-8 bytes.',
	'PLUS_AWAY_MEMBERS'=>'Allow members to set an absence','PLUS_AWAY_MEMBERS_EXPLAIN'=>'Administrators and moderators can always mark themselves as away.',
	'PLUS_AWAY_MOD_CONTACT'=>'Allow moderators to contact absent members','PLUS_AWAY_MOD_CONTACT_EXPLAIN'=>'This applies to personal messages and profile email. Administrators can always contact absent members.',
	'PLUS_AWAY_BY_NAME'=>'Show absence beside the author name','PLUS_AWAY_BY_NAME_EXPLAIN'=>'When disabled, posts show the status beside the contact details.',
));
