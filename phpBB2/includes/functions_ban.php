<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }

// Email bans use only the ACP's '*' wildcard. SQL LIKE and regular-expression
// metacharacters in either the rule or the account address remain literal.
function phpbb_email_ban_matches($pattern, $email)
{
    if (!is_string($pattern) || !is_string($email) || $pattern === '' || $email === ''
        || strlen($pattern) > 255 || strlen($email) > 255
        || strpbrk($pattern . $email, "\0\r\n") !== false) { return false; }
    // Legacy domain-only bans were stored as '@domain', without the leading '*'.
    if ($pattern[0] === '@') { $pattern = '*' . $pattern; }
    // Email validation historically accepts ASCII addresses; avoid locale-
    // dependent case folding or normalizing the stored account identity.
    $upper = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'; $lower = 'abcdefghijklmnopqrstuvwxyz';
    $pattern = strtr($pattern, $upper, $lower); $email = strtr($email, $upper, $lower);
    $parts = explode('*', $pattern);
    if (count($parts) === 1) { return $pattern === $email; }
    $first = array_shift($parts); $last = array_pop($parts);
    $position = strlen($first); $limit = strlen($email) - strlen($last);
    if ($position > $limit || substr($email, 0, $position) !== $first
        || ($last !== '' && substr($email, $limit) !== $last)) { return false; }
    // Match ordered literal pieces without regex backtracking. Lengths are
    // bounded by the canonical email/ban columns, even for repeated wildcards.
    foreach ($parts as $part) {
        if ($part === '') { continue; }
        $found = strpos($email, $part, $position);
        if ($found === false || $found + strlen($part) > $limit) { return false; }
        $position = $found + strlen($part);
    }
    return true;
}
