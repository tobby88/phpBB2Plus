<?php

$root = dirname(dirname(__DIR__));
$card = file_get_contents($root . '/phpBB2/card.php');

if ($card === false) {
    fwrite(STDERR, "Unable to read card.php.\n");
    exit(1);
}

$checks = array(
    'card mutations require POST' => strpos($card, "strtoupper((string) \$_SERVER['REQUEST_METHOD']) !== 'POST'") !== false,
    'card mutations use timing-safe SID comparison' => strpos($card, "hash_equals((string) \$userdata['session_id'], \$sid)") !== false,
    'exactly one card action is accepted' => strpos($card, 'count($submitted_actions) === 1') !== false,
    'post identifiers must be scalar' => strpos($card, "isset(\$_POST['post_id']) && is_scalar(\$_POST['post_id'])") !== false,
    'direct user identifiers must be scalar' => strpos($card, "isset(\$_POST[POST_USERS_URL]) && is_scalar(\$_POST[POST_USERS_URL])") !== false,
    'post reports cannot target direct user mode' => strpos($card, "(\$mode === 'report' || \$mode === 'report_reset') && \$post_id <= 0") !== false,
    'missing users are rejected before dereference' => substr_count($card, "if (!\$the_user)") >= 4,
    'report notification intervals cannot divide by zero' => strpos($card, "\$bluecard_limit = max(1,") !== false,
    'temporary blocks use the installed configuration key' => strpos($card, "intval(\$board_config['block_time'])") !== false,
    'obsolete temporary-block key is gone' => strpos($card, "RY_block_time") === false,
    'moderator notifications exclude pending memberships' => strpos($card, 'ug.user_pending = 0') !== false,
    'moderator notifications are deduplicated' => strpos($card, 'SELECT DISTINCT u.user_id') !== false,
    'email language paths are allowlisted' => substr_count($card, "preg_match('/^[a-z0-9_-]+$/i'") >= 2,
);

$failed = array();
foreach ($checks as $label => $passed) {
    if (!$passed) {
        $failed[] = $label;
    }
}

if ($failed) {
    fwrite(STDERR, "Card safety checks failed:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "Card safety checks passed.\n";
