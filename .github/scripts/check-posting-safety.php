<?php

$root = dirname(dirname(__DIR__));
$posting = file_get_contents($root . '/phpBB2/posting.php');
$functions = file_get_contents($root . '/phpBB2/includes/functions_post.php');
$portal = file_get_contents($root . '/phpBB2/portal.php');
$ajax = file_get_contents($root . '/phpBB2/ajax.php');

if ($posting === false || $functions === false || $portal === false || $ajax === false) {
    fwrite(STDERR, "Unable to read posting sources.\n");
    exit(1);
}

$checks = array(
    'posting helper requires POST' => strpos($posting, "strtoupper((string) \$_SERVER['REQUEST_METHOD']) === 'POST'") !== false,
    'posting helper uses timing-safe SID comparison' => strpos($posting, "hash_equals((string) \$session_id, \$submitted_sid)") !== false,
    'poll voting checks the posting session' => substr_count($posting, "posting_post_session_is_valid(\$sid, \$userdata['session_id'])") >= 2,
    'unauthorized poll voting has a defined login redirect' => strpos($posting, "case 'vote':\n\t\t\t\$redirect = POST_TOPIC_URL") !== false,
    'announcement duration is assigned rather than compared' => strpos($posting, 'if ($topic_announce_duration < -1) $topic_announce_duration = 0;') !== false,
    'legacy manual post escaping is gone' => strpos($posting, 'str_replace("\\\'", "\'\'", $subject)') === false,
    'post storage uses the database driver' => strpos($functions, '$post_message_sql = $db->sql_escape(stripslashes((string) $post_message));') !== false,
    'poll option normalization updates the caller array' => strpos($functions, '$poll_options = $temp_option_text;') !== false,
    'poll options use database-driver escaping' => strpos($functions, '$option_text = $db->sql_escape(stripslashes((string) $option_text));') !== false,
    'poll voters are recorded before their result is counted' => strpos($posting, 'INSERT INTO " . VOTE_USERS_TABLE') < strpos($posting, 'SET vote_result = vote_result + 1'),
    'portal poll form carries the session id' => strpos($portal, 'name="sid" value="\' . htmlspecialchars($userdata[\'session_id\']') !== false,
    'AJAX-rendered poll form carries the session id' => strpos($ajax, 'name="sid" value="\' . htmlspecialchars($userdata[\'session_id\']') !== false,
);

$failed = array();
foreach ($checks as $label => $passed) {
    if (!$passed) {
        $failed[] = $label;
    }
}

if ($failed) {
    fwrite(STDERR, "Posting safety checks failed:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "Posting safety checks passed.\n";
