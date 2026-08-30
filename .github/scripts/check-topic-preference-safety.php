<?php

function topic_preference_test_assert($condition, $message)
{
	if (!$condition)
	{
		fwrite(STDERR, "Topic preference test failed: $message\n");
		exit(1);
	}
}

$root = dirname(dirname(__DIR__));
$viewtopic = file_get_contents($root . '/phpBB2/viewtopic.php');
$ajax = file_get_contents($root . '/phpBB2/ajax.php');

topic_preference_test_assert(strpos($viewtopic, "phpbb_session_action_token('topic-preference', \$bookmark_action") !== false, 'bookmark writes must verify an action-bound capability');
topic_preference_test_assert(strpos($viewtopic, "phpbb_session_action_token('topic-preference', \$preference_action") !== false, 'watch writes must verify an action-bound capability');
topic_preference_test_assert(strpos($viewtopic, "\$bookmark_token === '' || !hash_equals") !== false, 'bookmark requests without a capability must fail closed');
topic_preference_test_assert(strpos($viewtopic, "\$watch_token === '' || !hash_equals") !== false, 'watch requests without a capability must fail closed');
topic_preference_test_assert(substr_count($viewtopic, "phpbb_session_action_token('topic-preference'") >= 5, 'all rendered watch and bookmark controls must carry capabilities');
topic_preference_test_assert(substr_count($ajax, "phpbb_session_action_token('topic-preference'") >= 2, 'AJAX watch responses must preserve protected fallback links');

echo "Topic preference safety tests passed.\n";
