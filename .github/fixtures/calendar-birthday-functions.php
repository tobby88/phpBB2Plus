<?php
// Load only the production birthday collector, without calendar bootstrap.
function calendar_fixture_function($source, $name)
{
    $offset = strpos($source, 'function ' . $name . '(');
    if ($offset === false) { throw new RuntimeException('Actual calendar function missing'); }
    $body = ''; $depth = 0; $started = false;
    foreach (token_get_all('<?php ' . substr($source, $offset)) as $token)
    {
        if (is_array($token) && $token[0] === T_OPEN_TAG) { continue; }
        if ($token === '{') { $depth++; $started = true; }
        if ($token === '}') { $depth--; }
        $body .= is_array($token) ? $token[1] : $token;
        if ($started && $depth === 0) { break; }
    }
    return $body;
}
$calendar_fixture_root = dirname(dirname(__DIR__)) . '/phpBB2/';
require_once $calendar_fixture_root . 'includes/functions.php';
eval(calendar_fixture_function(file_get_contents($calendar_fixture_root . 'includes/functions_calendar.php'), 'get_birthday'));
