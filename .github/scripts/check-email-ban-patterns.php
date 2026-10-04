<?php
define('IN_PHPBB', true);
require dirname(dirname(__DIR__)) . '/phpBB2/includes/functions_ban.php';
$checks = 0;
function email_ban_check($ok, $label) {
    $GLOBALS['checks']++;
    if (!$ok) { throw new RuntimeException($label); }
}
// Independent dynamic-programming oracle, not the production piece matcher.
function email_ban_oracle($pattern, $subject) {
    $row = array_fill(0, strlen($subject) + 1, false); $row[0] = true;
    for ($i = 0; $i < strlen($pattern); $i++) {
        $next = array_fill(0, count($row), false);
        if ($pattern[$i] === '*') { $next[0] = $row[0]; }
        for ($j = 1; $j < count($row); $j++) {
            $next[$j] = $pattern[$i] === '*' ? ($row[$j] || $next[$j-1]) : ($row[$j-1] && $pattern[$i] === $subject[$j-1]);
        }
        $row = $next;
    }
    return $row[strlen($subject)];
}
function email_ban_words($alphabet, $maximum) {
    $all = $level = array('');
    for ($n = 0; $n < $maximum; $n++) {
        $next = array();
        foreach ($level as $word) { foreach ($alphabet as $letter) { $next[] = $word . $letter; } }
        $all = array_merge($all, $next); $level = $next;
    }
    return $all;
}
set_error_handler(function($severity, $message) { if (error_reporting() & $severity) { throw new RuntimeException($message); } });
foreach (email_ban_words(array('a','b','*'), 5) as $pattern) {
    foreach (email_ban_words(array('a','b'), 6) as $email) {
        email_ban_check(phpbb_email_ban_matches($pattern, $email) === ($pattern !== '' && $email !== '' && email_ban_oracle($pattern, $email)), 'Exhaustive wildcard equivalence');
    }
}
$cases = array(
    array('*@example.invalid', 'Blocked@EXAMPLE.INVALID', true),
    array('@example.invalid', 'member@example.invalid', true),
    array('@example.invalid', 'member@sub.example.invalid', false),
    array('*@example.invalid', 'member@otherexample.invalid', false),
    array('m*e*@*.invalid', 'member@example.invalid', true),
    array('a*a', 'a', false), array('a*a', 'aa', true),
    array('a*b*c', 'abbc', true), array('a*b*c', 'acb', false),
    array('***', 'member@example.invalid', true),
    array('axb@example.invalid', 'a_b@example.invalid', false),
    array('axxb@example.invalid', 'a%b@example.invalid', false),
    array('a_b@example.invalid', 'axb@example.invalid', false),
    array('a%b@example.invalid', 'axxb@example.invalid', false),
    array('a_b@example.invalid', 'a_b@example.invalid', true),
    array('a%b@example.invalid', 'a%b@example.invalid', true),
    array('a.b@example.invalid', 'axb@example.invalid', false),
    array('a+b@example.invalid', 'a+b@example.invalid', true),
    array('a+b@example.invalid', 'ab@example.invalid', false),
    array('a?b@example.invalid', 'axb@example.invalid', false),
    array('a[b]@example.invalid', 'ab@example.invalid', false),
    array('a(b)@example.invalid', 'ab@example.invalid', false),
    array('a/b@example.invalid', 'a/b@example.invalid', true),
    array("o'reilly@example.invalid", "O'REILLY@example.invalid", true),
    array('a\\b@example.invalid', 'a\\b@example.invalid', true),
    array('ü@example.invalid', 'ü@example.invalid', true),
    array(null, 'member@example.invalid', false), array(array(), 'member@example.invalid', false),
    array('*', null, false), array('*', array(), false), array('', 'member@example.invalid', false),
    array('*', '', false), array('*', "member@example.invalid\n", false),
    array("*\0", 'member@example.invalid', false),
    array(str_repeat('*', 255), str_repeat('a', 255), true),
    array(str_repeat('*', 256), 'member@example.invalid', false),
    array('*', str_repeat('a', 256), false),
    array(str_repeat('*a', 120) . '*b', str_repeat('a', 240), false)
);
foreach ($cases as $case) { email_ban_check(phpbb_email_ban_matches($case[0], $case[1]) === $case[2], 'Literal/legacy/bounded email-ban case'); }
restore_error_handler();
echo $checks . " email-ban pattern checks passed\n";
