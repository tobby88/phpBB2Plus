<?php
if (!defined('IN_PHPBB')) { exit; }
$lang = array_merge($lang ?? array(), array(
	'ACP_PLUS_POST_REFERENCES'=>'Check post and topic references',
	'PLUS_REFS_HELP'=>'Check the current forum, topic, author and move links in the database. This report only reads records. Each page checks up to 200 IDs. Automatic checking pauses at the next page with findings.',
	'PLUS_REFS_SUMMARY'=>array(1=>'%1$d record checked on this page, %2$d with findings.', 2=>'%1$d records checked on this page, %2$d with findings.'),
	'PLUS_REFS_RANGE'=>'IDs %1$d–%2$d.', 'PLUS_REFS_PROBLEM'=>'Finding',
	'PLUS_REFS_NEXT'=>'Check the next page', 'PLUS_REFS_AUTO'=>'Continue until the next finding',
	'PLUS_REFS_STOP'=>'Pause on this page', 'PLUS_REFS_RESTART'=>'Start this section again',
	'PLUS_REFS_DONE'=>'Reached the end of this section.',
	'PLUS_REFS_FORUM'=>'Forum ID %d does not identify a posting forum.',
	'PLUS_REFS_AUTHOR'=>'Author ID %d has no matching account.',
	'PLUS_REFS_TOPIC'=>'Topic ID %d has no matching topic.',
	'PLUS_REFS_POST_SHADOW'=>'The post belongs to topic ID %d, which has a move link.',
	'PLUS_REFS_ROUTE'=>'The post points to forum ID %1$d. Its topic (ID %2$d) belongs to forum ID %3$d.',
	'PLUS_REFS_MISSING_TARGET'=>'The move link points to topic ID %d, which has no matching topic.',
	'PLUS_REFS_SELF_TARGET'=>'The move link points back to this topic.',
	'PLUS_REFS_CHAINED_TARGET'=>'The move link points to topic ID %d, which has another move link.',
	'PLUS_REFS_MOVE_FLAG'=>'The topic points to destination ID %d but is not marked as a move link.',
	'PLUS_REFS_EMPTY_TOPIC'=>'No post refers to this topic.',
));
