<?php
if (!defined('IN_PHPBB')) { exit; }
$lang = array_merge($lang ?? array(), array(
	'ACP_PLUS_KB' => 'Knowledge Base', 'ACP_PLUS_KB_ARTICLES' => 'Manage articles', 'ACP_PLUS_KB_ARTICLE' => 'Article',
	'ACP_PLUS_KB_ALL' => 'All articles', 'ACP_PLUS_KB_EDITED' => 'Edits awaiting approval', 'ACP_PLUS_KB_PENDING' => 'New submissions',
	'ACP_PLUS_KB_PUBLISHED' => 'Published', 'ACP_PLUS_KB_EMPTY' => 'No articles in this view.',
	'ACP_PLUS_KB_DELETE_EXPLAIN' => 'Deleting this article also removes its pages and ratings. The discussion settings and your forum permissions determine whether its linked discussion is removed.',
));
