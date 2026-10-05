<?php

$root = dirname(dirname(__DIR__));
$card = file_get_contents($root . '/phpBB2/card.php');
$storage = file_get_contents($root . '/phpBB2/includes/functions_card_storage.php');
$notify = file_get_contents($root . '/phpBB2/includes/functions_card_notifications.php');
define('IN_PHPBB',true);
require $root . '/phpBB2/includes/functions_card_storage.php';

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
    'moderation is dispatched to an independent owner' => strpos($card,'phpbb_card_moderate($db,$_POST,$user_ip)') !== false && strpos($storage,'class PhpbbCardScope extends PhpbbLoginDatabase') !== false,
    'current role and exact session checked' => strpos($storage,'HEX(session_id)=HEX(') !== false && substr_count($storage,'$owner->actor()') >= 2,
    'missing users are rejected before dereference' => strpos($storage,"if (count(\$rows)!==1) { phpbb_card_error('No_such_user'); }") !== false,
    'report notification intervals cannot divide by zero' => strpos($card, "\$bluecard_limit = max(1,") !== false,
    'temporary blocks use the current installed configuration key' => strpos($storage, "isset(\$policy['block_time'])") !== false,
    'obsolete temporary-block key is gone' => strpos($card, "RY_block_time") === false,
    'moderator notifications exclude pending memberships' => strpos($card, 'ug.user_pending = 0') !== false,
    'moderator notifications are deduplicated' => strpos($card, 'SELECT DISTINCT u.user_id') !== false,
    'email language paths are allowlisted' => strpos($card,"preg_match('/^[a-z0-9_-]+$/i'") !== false && strpos($notify,"preg_match('/^[a-z0-9_-]+$/iD'") !== false,
    'optional moderation mail follows confirmed commit' => strpos($card,'phpbb_card_notify($outcome)') > strpos($card,'phpbb_card_moderate($db,$_POST,$user_ip)') && strpos($notify,"new emailer(\$board_config['smtp_delivery'],true)") !== false,
    'unowned old moderation branches removed' => strpos($card,'INSERT INTO ') === false && strpos($card,'DELETE FROM ') === false,
);

foreach(array('ban','unban','warn','block') as $action){
    $checks['actual parser '.$action]=phpbb_card_action(array($action.'_x'=>'0'))===$action;
}
foreach(array(array(),array('ban_x'=>'1','warn_x'=>'1'),array('ban_x'=>array('1')),array('ban_x'=>'1e3'),array('ban_x'=>1)) as $i=>$input){
    $rejected=false;try{phpbb_card_action($input);}catch(PhpbbCardException $e){$rejected=true;}$checks['invalid action '.$i]=$rejected;
}
foreach(array(null,0,-1,true,1.5,'01','1x',array(1),'8388608') as $i=>$input){
    $rejected=false;try{phpbb_card_id($input,8388607);}catch(PhpbbCardException $e){$rejected=true;}$checks['invalid canonical ID '.$i]=$rejected;
}
$checks['maximum canonical user ID']=phpbb_card_id('8388607',8388607)===8388607;

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
