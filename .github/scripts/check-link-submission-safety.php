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
	'link listing result batches are capped' => strpos($listing, '$linkspp = max(1, min(100, intval($link_config[\'linkspp\'])));') !== false,
	'link redirects require positive integer IDs' => strpos($listing, 'if($link_id > 0)') !== false,
	'link redirect IP values use database-driver escaping' => strpos($listing, '$user_ip_sql = $db->sql_escape($user_ip);') !== false,
	'link listing URLs reject embedded credentials' => strpos($listing, "isset(\$parts['user']) || isset(\$parts['pass'])") !== false,
	'link search uses database-driver escaping' => strpos($listing, '$search_keywords_sql = links_like_sql($search_keywords);') !== false,
	'link search data and totals use the same predicate' => substr_count($listing, "link_desc LIKE '%\$search_keywords_sql%'") === 2,
	'link configuration has runtime defaults' => strpos($listing, "'site_logo' => '',") !== false,
    'link values use database-driver escaping' => substr_count($submission, '$db->sql_escape($link_') >= 4,
    'submitter IP uses database-driver escaping' => strpos($submission, '$user_ip_sql = $db->sql_escape($user_ip);') !== false,
    'URLs reject embedded credentials' => strpos($submission, "isset(\$parts['user']) || isset(\$parts['pass'])") !== false,
	'URLs reject ambiguous backslashes' => strpos($submission, "strpos(\$value, '\\\\') !== false") !== false,
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
