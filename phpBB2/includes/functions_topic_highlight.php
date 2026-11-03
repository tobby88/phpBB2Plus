<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/php_compat.php';

/** One raw UTF-8 query for both rendering and navigation, after legacy escaping. */
function phpbb_topic_highlight_query($value)
{
    $value = phpbb_request_raw_value($value);
    if (!is_string($value) || strlen($value) > 1024 || preg_match('//u', $value) !== 1 ||
        preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) { return ''; }
    return $value;
}

/** Search wildcards span word characters, with the same Unicode boundaries on all PHP versions. */
function phpbb_topic_highlight_pattern($query)
{
    if (!is_string($query) || $query === '' || strlen($query) > 1024 || preg_match('//u', $query) !== 1) { return ''; }
    $terms = preg_split('/\s+/u', trim($query)); $patterns = array();
    if (!is_array($terms)) { return ''; }
    $word = '[\p{L}\p{N}\p{M}_]';
    foreach ($terms as $term)
    {
        if ($term === '' || trim($term, '*') === '') { continue; }
        $term = preg_replace('/\*+/', '*', $term);
        if (!is_string($term)) { return ''; }
        $pattern = str_replace('\\*', $word . '*', preg_quote($term, '#'));
        $patterns[$pattern] = true;
        if (count($patterns) >= 32) { break; }
    }
    return $patterns ? '#(?<!' . $word . ')(?:' . implode('|', array_keys($patterns)) . ')(?!' . $word . ')#iu' : '';
}

/** Highlight decoded text nodes, retaining tags and protected raw-text elements. */
function phpbb_topic_highlight_apply($message, $pattern, $color)
{
    if (!is_string($message) || !is_string($pattern) || $pattern === '' || preg_match('//u', $message) !== 1) { return $message; }
    $color = is_string($color) && preg_match('/^(?:[a-f0-9]{3}|[a-f0-9]{6})$/iD', $color) ? $color : '000000';
    // Disjoint, atomic alternatives also keep quoted '>' attributes together.
    $parts = preg_split("~(<!--.*?-->|<(?:(?>[^<>\"']+)|\"[^\"]*\"|'[^']*')*>)~s", $message, -1, PREG_SPLIT_DELIM_CAPTURE);
    if (!is_array($parts)) { return $message; }
    $result = ''; $protected = '';
    foreach ($parts as $index => $part)
    {
        if ($index % 2)
        {
            if ($protected !== '')
            {
                if ($protected !== 'plaintext' && preg_match('~^</\s*' . $protected . '\s*>$~iD', $part)) { $protected = ''; }
            }
            elseif (preg_match('~^<(script|style|textarea|title|xmp|iframe|noembed|noframes|noscript|plaintext)(?:\s|/?>)~i', $part, $tag)) { $protected = strtolower($tag[1]); }
            $result .= $part; continue;
        }
        if ($protected !== '' || $part === '') { $result .= $part; continue; }
        // Preserve malformed/unclosed markup rather than interpreting it as text.
        if (strpos($part, '<') !== false) { return $message; }
        $text = html_entity_decode($part, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $count = preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE);
        // In particular, backtracking/resource failure must never erase a post.
        if ($count === false) { return $message; }
        if ($count === 0) { $result .= $part; continue; }
        $offset = 0;
        foreach ($matches[0] as $match)
        {
            if ($match[0] === '') { continue; }
            $result .= htmlspecialchars(substr($text, $offset, $match[1] - $offset), ENT_NOQUOTES, 'UTF-8') .
                '<b style="color:#' . $color . '">' . htmlspecialchars($match[0], ENT_NOQUOTES, 'UTF-8') . '</b>';
            $offset = $match[1] + strlen($match[0]);
        }
        $result .= htmlspecialchars(substr($text, $offset), ENT_NOQUOTES, 'UTF-8');
    }
    return $result;
}
