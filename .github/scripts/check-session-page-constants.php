<?php

function session_page_test_assert($condition, $message)
{
	if (!$condition)
	{
		fwrite(STDERR, "Session-page constant test failed: $message\n");
		exit(1);
	}
}

$root = dirname(dirname(__DIR__));
$constants = file_get_contents($root . '/phpBB2/includes/constants.php');
$viewonline = file_get_contents($root . '/phpBB2/viewonline.php');
$knowledge_base = file_get_contents($root . '/phpBB2/kb.php') . file_get_contents($root . '/phpBB2/kb_search.php');

session_page_test_assert(strpos($constants, "define('PAGE_KB', -500);") !== false, 'Knowledge Base page ID must be globally available');
session_page_test_assert(strpos($viewonline, 'case PAGE_KB:') !== false, 'online-user view must recognize Knowledge Base sessions');
session_page_test_assert(strpos($knowledge_base, "define('PAGE_KB'") === false, 'Knowledge Base entry points must not redefine the global page ID');

echo "Session-page constant tests passed.\n";
