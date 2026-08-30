<?php

$root = dirname(dirname(__DIR__));
$submission = file_get_contents($root . '/phpBB2/link_register.php');
$listing = file_get_contents($root . '/phpBB2/links.php');

if ($submission === false || $listing === false) {
    fwrite(STDERR, "Unable to read link sources.\n");
    exit(1);
}

$checks = array(
    'link submissions require POST' => strpos($submission, "strtoupper(\$_SERVER['REQUEST_METHOD']) !== 'POST'") !== false,
    'link submissions use timing-safe SID comparison' => strpos($submission, "hash_equals((string) \$userdata['session_id']") !== false,
    'link form carries the SID' => strpos($listing, "'S_LINK_TOKEN' => '<input type=\"hidden\" name=\"sid\"") !== false,
    'link values use database-driver escaping' => substr_count($submission, '$db->sql_escape($link_') >= 4,
    'submitter IP uses database-driver escaping' => strpos($submission, '$user_ip_sql = $db->sql_escape($user_ip);') !== false,
    'URLs reject embedded credentials' => strpos($submission, "isset(\$parts['user']) || isset(\$parts['pass'])") !== false,
    'overlong URLs are rejected rather than truncated' => strpos($submission, "strlen(\$link_url) <= 100") !== false,
    'only active administrators are notified' => strpos($submission, 'user_level = " . ADMIN . " AND user_active = 1') !== false,
    'mail languages are path-allowlisted' => strpos($submission, "preg_match('/^[a-z0-9_-]+$/i', (string) \$to_userdata['user_lang'])") !== false,
    'notification PM text uses database-driver escaping' => strpos($submission, '$privmsg_message_sql = $db->sql_escape($privmsg_message);') !== false,
    'notification PM subject uses database-driver escaping' => strpos($submission, '$privmsg_subject_sql = $db->sql_escape($privmsg_subject);') !== false,
    'notification PM identifies the submitter as sender' => strpos($submission, "'\$privmsg_subject_sql', \$user_id, \$admin_user_id") !== false,
);

$checks['failed inserts are not overwritten unconditionally'] = strpos($submission, "\t\t\t  \$message = \$lang['Link_update_success'];\n\t\t\t}") !== false;

$failed = array();
foreach ($checks as $label => $passed) {
    if (!$passed) {
        $failed[] = $label;
    }
}

if ($failed) {
    fwrite(STDERR, "Link submission safety checks failed:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "Link submission safety checks passed.\n";
