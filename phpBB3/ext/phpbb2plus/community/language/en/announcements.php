<?php
if (!defined('IN_PHPBB')) { exit; }
$lang = array_merge($lang ?? array(),array(
	'PLUS_ANNOUNCEMENTS'=>'Announcements',
	'PLUS_ANNOUNCE_DEFAULT'=>'Default duration in days',
	'PLUS_ANNOUNCE_DEFAULT_EXPLAIN'=>'New announcements can use this duration. A value of 0 or −1 pauses automatic expiry for all announcements.',
	'PLUS_ANNOUNCE_STICKY'=>'Keep expired announcements as sticky topics',
	'PLUS_ANNOUNCE_STICKY_EXPLAIN'=>'Choose Yes to keep these topics pinned. Choose No to convert them to normal topics.',
	'PLUS_ANNOUNCE_HINT'=>'For announcements, 0 uses the board default of %d days. Use −1 for a permanent announcement. Sticky topics remain permanent with 0.',
	'PLUS_ANNOUNCE_PAUSED'=>'Automatic announcement expiry is paused. Use 0 to inherit the default when expiry resumes, or −1 for a permanent announcement.',
	'PLUS_ANNOUNCE_RANGE'=>'Enter a whole number of days between −1 and %d for this topic type.',
	'PLUS_ANNOUNCE_CHANGED'=>'The topic type or duration has changed. Review your choice before saving again.',
));
