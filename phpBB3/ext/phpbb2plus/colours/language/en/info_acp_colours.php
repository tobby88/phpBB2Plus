<?php
if (!defined('IN_PHPBB')) { exit; }
$lang = array_merge($lang ?? array(),array(
	'ACP_PLUS_COLOURS'=>'Member colours',
	'PLUS_COLOURS_ENTRY'=>'Colour assignment',
	'PLUS_COLOURS_LABEL'=>'Name',
	'PLUS_COLOURS_COLOUR'=>'Colour',
	'PLUS_COLOURS_MEMBER'=>'Choose a member',
	'PLUS_COLOURS_FORMAT'=>'Use a hexadecimal colour such as #006699, a classic CSS colour name, or leave the colour empty.',
	'PLUS_COLOURS_POSITION'=>'Position',
	'PLUS_COLOURS_PRIORITY'=>'A personal assignment takes precedence. Among approved groups the lowest position wins. Equal positions follow the original colour ID.',
	'PLUS_COLOURS_LEGEND'=>'Show in the board index legend',
	'PLUS_COLOURS_ADD'=>'Add a colour',
	'PLUS_COLOURS_INHERIT'=>'Use the inherited colour',
	'PLUS_COLOURS_CONFLICT'=>'This assignment or colour changed while the form was open. Open it again before saving.',
	'PLUS_COLOURS_DUPLICATE'=>'A colour with this name already exists.',
	'PLUS_COLOURS_UNKNOWN'=>'This colour no longer exists.',
	'PLUS_COLOURS_INVALID'=>'Enter a name, a valid colour and a position within the displayed range.',
	'PLUS_COLOURS_DELETE'=>'Remove this colour? Existing assignments will use their next eligible colour.',
	'LOG_PLUS_COLOUR_DELETE'=>'Removed member colour <strong>%s</strong>',
	'LOG_PLUS_COLOUR_EDIT'=>'Saved member colour <strong>%s</strong>',
	'LOG_PLUS_COLOUR_ASSIGN'=>'Changed the colour assignment for <strong>%s</strong>',
));
