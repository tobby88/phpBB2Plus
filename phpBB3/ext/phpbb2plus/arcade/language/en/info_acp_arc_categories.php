<?php
if (!defined('IN_PHPBB')) { exit; }
$lang = array_merge($lang ?? array(), array(
	'ACP_PLUS_ARC_CATEGORIES'=>'Arcade categories', 'ACL_A_PLUS_ARC_CATEGORIES'=>'Can manage Arcade categories',
	'PLUS_ARC_CAT_NAME'=>'Category name', 'PLUS_ARC_CAT_NEW'=>'Add category', 'PLUS_ARC_CAT_EDIT'=>'Edit category',
	'PLUS_ARC_CAT_RESYNC'=>'Resynchronise categories', 'PLUS_ARC_CAT_TYPE'=>'Category type',
	'PLUS_ARC_CAT_TYPE_P'=>'Main category', 'PLUS_ARC_CAT_TYPE_S'=>'Subcategory', 'PLUS_ARC_CAT_TYPE_L'=>'External link',
	'PLUS_ARC_CAT_TOPOLOGY'=>'Changing a main category to another type promotes its children to main categories. Changing a category to a link moves its games to All games.',
	'PLUS_ARC_CAT_PARENT_LABEL'=>'Parent for a subcategory', 'PLUS_ARC_CAT_DESC'=>'Description, or destination URL for a link',
	'PLUS_ARC_CAT_ICON'=>'Category picture', 'PLUS_ARC_CAT_ICON_HELP'=>'Optional path to a shared Arcade image. Existing category pictures are available immediately. New files can be imported from the offline source using the Arcade artwork command.',
	'PLUS_ARC_CAT_GROUP'=>'Required group', 'PLUS_ARC_CAT_ALL'=>'No group restriction', 'PLUS_ARC_CAT_KEEP_GROUP'=>'Keep current restriction (%s)',
	'PLUS_ARC_CAT_CLEAR_MOD'=>'Clear the reference to the former moderator', 'PLUS_ARC_CAT_SPECIAL'=>'Least-played games threshold',
	'PLUS_ARC_CAT_SPECIAL_HELP'=>'Enable the list when the category contains more than this number of games. Zero disables it.',
	'PLUS_ARC_CAT_GAMES'=>'Manage games and scores', 'PLUS_ARC_CAT_CONFIRM_DELETE'=>'Remove “%s”? Its subcategories become main categories and its games remain in All games. Access previously inherited from this category may change.',
	'PLUS_ARC_CAT_CONFIRM_RESYNC'=>'Rebuild the category order, game counts and last-play records? Invalid subcategories become main categories. Games without a valid category move to All games.',
	'PLUS_ARC_CAT_CHANGED'=>'The category or its position has changed. Reopen the form.',
	'PLUS_ARC_CAT_REPAIR'=>'The category hierarchy needs repair. Use Resynchronise categories before editing.',
	'PLUS_ARC_CAT_GLOBAL'=>'The Arcade catalogue is missing a valid All games category.',
	'PLUS_ARC_CAT_INVALID'=>'Check the category name, description and threshold.',
	'PLUS_ARC_CAT_LINK_INVALID'=>'Enter a complete HTTP or HTTPS destination without account credentials.',
	'PLUS_ARC_CAT_ICON_INVALID'=>'Enter a shared Arcade image path with a GIF, JPEG, PNG or WebP extension.',
	'PLUS_ARC_CAT_PARENT'=>'Select another main category as the parent.', 'PLUS_ARC_CAT_GROUP_INVALID'=>'Select an existing shared group.',
	'PLUS_ARC_CAT_USER_INVALID'=>'The named member could not be found.', 'PLUS_ARC_CAT_COUNT'=>'The game totals exceed the supported range.',
));
