<?php
// Exercise the controller's real request parsing and early cancellation path.
// No forum bootstrap, account, database or post is created or changed.
$root = dirname(dirname(__DIR__));
$source = file_get_contents($root . '/phpBB2/posting.php');
function cancel_fragment($source, $start, $end)
{
    $offset = strpos($source, $start);
    $finish = ($offset === false) ? false : strpos($source, $end, $offset);
    if ($finish === false) { throw new RuntimeException('Actual posting fragment unavailable'); }
    return substr($source, $offset, $finish - $offset);
}
$scalar = cancel_fragment($source, '$request_scalar = function', 'function posting_post_session_is_valid');
$parameters = cancel_fragment($source, '$params = array(\'forum_id\'', '$refresh =');
$cancel = cancel_fragment($source, "if ( isset(\$_POST['cancel']) )", "//\n// What auth type");
define('POST_POST_URL', 'p');
define('POST_TOPIC_URL', 't');
define('POST_FORUM_URL', 'f');
class PostingCancelRedirect extends RuntimeException {}
function append_sid($url, $non_html)
{
    if ($non_html !== true) { throw new RuntimeException('Redirect must retain a non-HTML session URL'); }
    return $url . (strpos($url, '?') === false ? '?' : '&') . 'sid=fixture';
}
function redirect($url) { throw new PostingCancelRedirect($url); }
set_error_handler(function ($number, $message) { throw new RuntimeException($message); });
$cases = array(
    array(array(), array('cancel' => '1'), 'index.php?sid=fixture'),
    array(array('p' => '42'), array('cancel' => '1'), 'viewtopic.php?p=42&sid=fixture#42'),
    array(array('t' => '13'), array('cancel' => '1'), 'viewtopic.php?t=13&sid=fixture'),
    array(array('f' => '6'), array('cancel' => '1'), 'viewforum.php?f=6&sid=fixture'),
    array(array('p' => '42', 't' => '13', 'f' => '6'), array('cancel' => '1'), 'viewtopic.php?p=42&sid=fixture#42'),
    array(array('t' => '13', 'f' => '6'), array('cancel' => '1'), 'viewtopic.php?t=13&sid=fixture'),
    array(array('p' => '42'), array('cancel' => '1', 'p' => '43'), 'viewtopic.php?p=43&sid=fixture#43'),
    array(array('postreport' => '47'), array('cancel' => '1'), 'viewtopic.php?p=47&sid=fixture'),
    array(array('postreport' => '47', 'p' => '42', 't' => '13'), array('cancel' => '1'), 'viewtopic.php?p=47&sid=fixture'),
    array(array('postreport' => '47'), array('cancel' => '1', 'postreport' => '99'), 'viewtopic.php?p=47&sid=fixture'),
    array(array('p' => array('42'), 't' => '13'), array('cancel' => array()), 'viewtopic.php?t=13&sid=fixture'),
    array(array(), array('cancel' => null), null),
    array(array(), array(), null),
    array(array('postreport' => '47'), array('preview' => '1'), null),
    array(array(), array('post' => '1', 'lock_subject' => '47'), null),
    array(array(), array('cancel' => '1', 'lock_subject' => '47'), 'index.php?sid=fixture'),
    array(array(), array('cancel' => '1', 'postreport' => '47'), 'index.php?sid=fixture'),
);
foreach (array('', '0', '-7', array('47'), null, new stdClass()) as $malformed) {
    $cases[] = array(array('postreport' => $malformed, 't' => '13'), array('cancel' => '1'), 'viewtopic.php?t=13&sid=fixture');
}
$count = 0;
foreach (array('php', 'phtml') as $extension) {
    foreach ($cases as $case) {
        // Function scope also detects a missing early initialization on every request.
        $exercise = function () use ($scalar, $parameters, $cancel, $case, $extension) {
            $_GET = $case[0]; $_POST = $case[1]; $phpEx = $extension;
            eval($scalar);
            eval($parameters);
            $actual = null;
            try { eval($cancel); } catch (PostingCancelRedirect $redirect) { $actual = $redirect->getMessage(); }
            $expected = ($case[2] === null) ? null : str_replace('.php', '.' . $extension, $case[2]);
            if ($actual !== $expected) { throw new RuntimeException('Wrong cancellation destination: ' . (string) $actual); }
            if (!isset($postreport) || !is_int($postreport) || $postreport < 0) {
                throw new RuntimeException('Report target must be initialized before cancellation/preview/submit');
            }
        };
        $exercise(); $count++;
    }
}
restore_error_handler();
echo 'Posting cancellation: ', $count, " actual-controller cases PASS; warning-free normal/report redirects and malformed request values.\n";
