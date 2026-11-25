<?php
if (!defined('IN_PHPBB')) { exit; }
$lang = array_merge($lang ?? array(), array(
	'ACP_PLUS_KB' => 'Knowledge Base', 'ACP_PLUS_KB_ARTICLES' => 'Manage articles', 'ACP_PLUS_KB_ARTICLE' => 'Article',
	'ACP_PLUS_KB_ALL' => 'All articles', 'ACP_PLUS_KB_EDITED' => 'Edits awaiting approval', 'ACP_PLUS_KB_PENDING' => 'New submissions',
	'ACP_PLUS_KB_PUBLISHED' => 'Published', 'ACP_PLUS_KB_EMPTY' => 'No articles in this view.',
	'ACP_PLUS_KB_TYPES' => 'Manage article types', 'ACP_PLUS_KB_ARTICLES_TOTAL' => 'Articles',
	'ACP_PLUS_KB_TYPE_NAME' => 'Article type', 'ACP_PLUS_KB_TYPE_CREATE' => 'Create article type', 'ACP_PLUS_KB_TYPE_EDIT' => 'Rename article type',
	'ACP_PLUS_KB_TYPE_DELETE' => 'Delete article type', 'ACP_PLUS_KB_TYPES_EMPTY' => 'No article types have been created.',
	'ACP_PLUS_KB_TYPE_DEST' => 'Reassign articles to', 'ACP_PLUS_KB_TYPE_NONE' => 'Keep articles without a type',
	'ACP_PLUS_KB_TYPE_AFFECTED' => array(1 => '%d article uses this type.', 2 => '%d articles use this type.'),
	'ACP_PLUS_KB_TYPE_NAME_INVALID' => 'Enter an article type name of up to 255 characters.',
	'ACP_PLUS_KB_TYPE_DEST_INVALID' => 'Choose a different article type or keep the articles without a type.',
	'ACP_PLUS_KB_TYPE_CONFLICT' => 'The article types or their assignments have changed. Open the current list before submitting again.',
	'ACP_PLUS_KB_DELETE_EXPLAIN' => 'Deleting this article also removes its pages and ratings. The discussion settings and your forum permissions determine whether its linked discussion is removed.',
));
