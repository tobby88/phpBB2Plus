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

// An empty mask retains the original four trailing-prefix rules. Old storage
// cannot distinguish a literal 255 from '*'; never reinterpret that data.
// New masks have one ff (literal) or 00 (wildcard) byte per IPv4 octet.
function phpbb_ip_ban_parse($value)
{
    if (!is_string($value) || !preg_match('/^(\\*|[0-9]{1,3})\\.(\\*|[0-9]{1,3})\\.(\\*|[0-9]{1,3})\\.(\\*|[0-9]{1,3})$/D', $value, $parts)) { return false; }
    $ip = $mask = '';
    for ($i = 1; $i <= 4; $i++) {
        if ($parts[$i] === '*') { $ip .= '00'; $mask .= '00'; }
        elseif ((int)$parts[$i] <= 255) { $ip .= sprintf('%02x', (int)$parts[$i]); $mask .= 'ff'; }
        else { return false; }
    }
    return array('ip'=>$ip, 'mask'=>$mask);
}
function phpbb_ip_ban_valid($ip, $mask)
{
    if (!is_string($ip) || !is_string($mask)) { return false; }
    if ($mask === '') { return $ip === '' || preg_match('/^[a-f0-9]{8}$/iD', $ip) === 1; }
    if (!preg_match('/^[a-f0-9]{8}$/iD', $ip) || !preg_match('/^(?:00|ff){4}$/iD', $mask)) { return false; }
    for ($i = 0; $i < 8; $i += 2) {
        if (substr($mask, $i, 2) === '00' && substr($ip, $i, 2) !== '00') { return false; }
    }
    return true;
}
function phpbb_ip_ban_range($start, $end)
{
    $first = phpbb_ip_ban_parse($start); $last = phpbb_ip_ban_parse($end);
    if (!$first || !$last || $first['mask'] !== 'ffffffff' || $last['mask'] !== 'ffffffff' || strcmp($first['ip'],$last['ip']) > 0) { return false; }
    $rules = array(); $next = $first['ip'];
    while (count($rules) < 4096) {
        $rules[] = array('ip'=>$next,'mask'=>'ffffffff');
        if ($next === $last['ip']) { return $rules; }
        for ($i = 6; $i >= 0; $i -= 2) {
            $byte = hexdec(substr($next,$i,2)) + 1;
            $next = substr_replace($next,sprintf('%02x',$byte % 256),$i,2);
            if ($byte < 256) { break; }
        }
        if ($i < 0) { return false; }
    }
    return false;
}
function phpbb_ip_ban_matches($ip, $mask, $address)
{
    if (!phpbb_ip_ban_valid($ip, $mask) || !is_string($address) || !preg_match('/^[a-f0-9]{8}$/iD', $address)) { throw new UnexpectedValueException('Invalid IP ban data'); }
    $ip = strtolower($ip); $address = strtolower($address);
    if ($mask === '') { return $ip !== '' && in_array($ip, array($address, substr($address,0,6).'ff', substr($address,0,4).'ffff', substr($address,0,2).'ffffff'), true); }
    for ($i = 0; $i < 8; $i += 2) {
        if (strtolower(substr($mask,$i,2)) === 'ff' && substr($ip,$i,2) !== substr($address,$i,2)) { return false; }
    }
    return true;
}
function phpbb_ip_ban_format($ip, $mask)
{
    if (!phpbb_ip_ban_valid($ip, $mask) || $ip === '') { throw new UnexpectedValueException('Invalid IP ban data'); }
    $parts = array(); $legacy_stars = 0;
    if ($mask === '') {
        // Only the trailing ff bytes are wildcards in the historical reader.
        // A first-octet 255 is literal; even ffffffff means 255.*.*.*.
        for ($i = 6; $i >= 2 && strtolower(substr($ip,$i,2)) === 'ff'; $i -= 2) { $legacy_stars++; }
    }
    for ($i = 0; $i < 4; $i++) {
        $parts[] = ($mask === '' ? $i >= 4-$legacy_stars : substr($mask,$i*2,2) === '00') ? '*' : (string)hexdec(substr($ip,$i*2,2));
    }
    return implode('.', $parts);
}
function phpbb_ip_ban_sql_identifier($value)
{
    if (!is_string($value) || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(?:\\.[a-zA-Z_][a-zA-Z0-9_]*)?$/D', $value)) { throw new UnexpectedValueException('Invalid IP ban SQL identifier'); }
    return $value;
}
function phpbb_ip_ban_sql_operand($value)
{
    if (is_string($value) && preg_match("/^'[a-f0-9]{8}'$/iD", $value)) { return $value; }
    return phpbb_ip_ban_sql_identifier($value);
}
function phpbb_ip_ban_invalid_sql($ip = 'ban_ip', $mask = 'ban_ip_mask')
{
    $ip = phpbb_ip_ban_sql_identifier($ip); $mask = phpbb_ip_ban_sql_identifier($mask);
    $invalid = array("$ip IS NULL", "$mask IS NULL", "($ip<>'' AND $ip NOT REGEXP '^[a-fA-F0-9]{8}$')", "($mask<>'' AND ($ip='' OR $mask NOT REGEXP '^(00|[fF]{2}){4}$'))");
    for ($i = 1; $i <= 7; $i += 2) { $invalid[] = "(SUBSTRING($mask,$i,2)='00' AND SUBSTRING($ip,$i,2)<>'00')"; }
    return '(' . implode(' OR ', $invalid) . ')';
}
function phpbb_ip_ban_sql($address, $address_is_column = false, $ip = 'ban_ip', $mask = 'ban_ip_mask')
{
    $ip = phpbb_ip_ban_sql_operand($ip); $mask = phpbb_ip_ban_sql_operand($mask);
    if ($address_is_column) { $candidate = phpbb_ip_ban_sql_identifier($address); }
    else {
        if (!is_string($address) || !preg_match('/^[a-f0-9]{8}$/iD', $address)) { throw new UnexpectedValueException('Invalid IP ban address'); }
        $candidate = "'" . strtolower($address) . "'";
    }
    $parts = array();
    for ($i = 1; $i <= 7; $i += 2) { $parts[] = "(SUBSTRING($mask,$i,2)='00' OR LOWER(SUBSTRING($ip,$i,2))=LOWER(SUBSTRING($candidate,$i,2)))"; }
    // Compare bounded hex bytes rather than PHP signed/unsigned IPv4 integers.
    // SQL modes and the platform's integer width cannot change the meaning.
    $legacy = "LOWER($ip) IN (LOWER($candidate),CONCAT(LOWER(SUBSTRING($candidate,1,6)),'ff'),CONCAT(LOWER(SUBSTRING($candidate,1,4)),'ffff'),CONCAT(LOWER(SUBSTRING($candidate,1,2)),'ffffff'))";
    return "(($mask='' AND $legacy) OR ($mask<>'' AND " . implode(' AND ', $parts) . '))';
}
