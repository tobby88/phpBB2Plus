<?php

$root = dirname(dirname(__DIR__));
$submission = file_get_contents($root . '/phpBB2/link_register.php');
$storage = file_get_contents($root . '/phpBB2/includes/functions_link_submission.php');
$notices = file_get_contents($root . '/phpBB2/includes/functions_link_notifications.php');
$listing = file_get_contents($root . '/phpBB2/links.php');

if ($submission === false || $listing === false || $storage === false || $notices === false) {
    fwrite(STDERR, "Unable to read link sources.\n");
    exit(1);
}

$checks = array(
    'controller uses the transactional link writer' => strpos($submission, 'phpbb_submit_link($db, $_POST, $userdata)') !== false && strpos($submission, 'INSERT INTO') === false,
    'notices run only after confirmed storage' => strpos($submission, 'if (isset($outcome))') !== false && strpos($submission, 'Link_notification_failed') !== false,
    'writer enforces POST and exact token' => strpos($storage, "$"."method!=='POST'") !== false && strpos($storage, 'hash_equals($sid,phpbb_request_raw_value($post[\'sid\']))') !== false,
    'writer checks actual session and current active role' => strpos($storage, 's.session_logged_in=1') !== false && strpos($storage, 'u.user_active<>0') !== false && strpos($storage, "HEX(s.session_id)=HEX('") !== false,
    'notice PMs have an owned transaction and cannot evict mail' => strpos($notices, 'new PhpbbLinkScope($database,phpbb_link_notification_tables(true))') !== false && strpos($notices, 'DELETE FROM') === false,
    'notice mail opts into recoverable delivery' => strpos($notices, "new emailer($"."board_config['smtp_delivery'],true)") !== false,
    'link form carries the SID' => strpos($listing, "'S_LINK_TOKEN' => '<input type=\"hidden\" name=\"sid\"") !== false,
	'link listing result batches are capped' => strpos($listing, '$linkspp = max(1, min(100, intval($link_config[\'linkspp\'])));') !== false,
	'link redirects require positive integer IDs' => strpos($listing, 'if($link_id > 0)') !== false,
	'link redirect IP values use database-driver escaping' => strpos($listing, '$user_ip_sql = $db->sql_escape($user_ip);') !== false,
	'link listing URLs use the shared external boundary' => strpos($listing, 'phpbb_normalize_external_url($value)') !== false,
	'link search uses database-driver escaping' => strpos($listing, '$search_keywords_sql = links_like_sql($search_keywords);') !== false,
	'link search data and totals use the same predicate' => substr_count($listing, "link_desc LIKE '%\$search_keywords_sql%'") === 2,
	'link configuration has runtime defaults' => strpos($listing, "'site_logo' => '',") !== false,
);

define('IN_PHPBB', true);
require $root . '/phpBB2/includes/functions_link_submission.php';
$input=array('link_title'=>str_repeat('ä',49).'€😀','link_desc'=>str_repeat('😀',255).'trailing','link_category'=>'1','link_url'=>'https://example.invalid/?a=1&amp;b=2','link_logo_src'=>'');
$normalized=phpbb_link_submission_input($input);
$checks['Unicode limits count whole characters']=$normalized['link_title']===$input['link_title']&&$normalized['link_desc']===str_repeat('😀',255);
$checks['URLs retain their canonical exact value']=$normalized['link_url']==='https://example.invalid/?a=1&b=2';
foreach(array('link_title'=>array('bad'),'link_desc'=>"bad\xc3",'link_category'=>'1e0','link_url'=>'javascript:alert(1)','link_logo_src'=>str_repeat('x',121))as$key=>$value){
    $bad=$input;$bad[$key]=$value;$refused=false;try{phpbb_link_submission_input($bad);}catch(PhpbbLinkException$error){$refused=true;}$checks['invalid input refused: '.$key]=$refused;
}
$input['link_title']="Literal \\path 'quote'";
$checks['raw input keeps literal slashes']=phpbb_link_submission_input($input)['link_title']===$input['link_title'];
define('PHPBB_LEGACY_REQUEST_ESCAPED', true);
foreach($input as$key=>$value){$input[$key]=addslashes($value);}
$checks['legacy request escaping removed exactly once']=phpbb_link_submission_input($input)['link_title']==="Literal \\path 'quote'";

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
