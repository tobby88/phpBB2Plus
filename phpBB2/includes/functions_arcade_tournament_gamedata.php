<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }

/**
 * Read a saved set of player turns, retaining its keys and existing fields.
 * Null is reserved for an unreadable record that a caller must not overwrite.
 */
function phpbb_arcade_tournament_gamedata($stored)
{
    if ($stored === null || $stored === '' || $stored === 'a:0:{}')
    {
        return array();
    }
    if (!is_string($stored))
    {
        return null;
    }

    $games = phpbb_safe_unserialize_array($stored);
    if (!$games && strpos($stored, '\\') !== false)
    {
        // Some older imports still have one layer of request escaping.
        $games = phpbb_safe_unserialize_array(stripslashes($stored));
    }
    return $games ? $games : null;
}
