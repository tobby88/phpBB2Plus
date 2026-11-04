<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_arcade_import_storage.php';

function phpbb_arcade_statistics_mode($mode)
{
    if (!is_string($mode) || !in_array($mode, array('reset_played','reset_last_player','reset_last_time'), true)) {
        throw new PhpbbOwnedStorageFailure('arcade_statistics_failed');
    }
    return $mode;
}

class PhpbbArcadeStatisticsWriter extends PhpbbArcadeImportWriter
{
    protected function actor()
    {
        global $phpEx;
        return phpbb_acp_actor($this, 'admin_arcade_reset.'.$phpEx.'?mode=home');
    }

    function reset($mode)
    {
        global $userdata;
        $mode = phpbb_arcade_statistics_mode($mode);
        $actor = $this->actor(); $sid = $this->escape($userdata['session_id']);
        foreach (array('SELECT session_id FROM '.SESSIONS_TABLE." WHERE session_id='$sid' AND HEX(session_id)=HEX('$sid')",
            'SELECT user_id FROM '.USERS_TABLE.' WHERE user_id='.(int)$actor['user_id'],
            'SELECT user_id FROM '.JR_ADMIN_TABLE.' WHERE user_id='.(int)$actor['user_id']) as $sql) {
            $this->rows($sql.' LOCK IN SHARE MODE');
        }
        $this->actor();
        // Match the transactional game writers' lock order and publish game
        // counters together with their category/global aggregates.
        if ($mode === 'reset_played') {
            $this->rows('SELECT game_id FROM '.iNA_GAMES.' ORDER BY game_id FOR UPDATE');
        }
        $this->rows('SELECT cat_id FROM '.iNA_CAT.' ORDER BY cat_id FOR UPDATE');
        if ($mode === 'reset_played') {
            $this->query('UPDATE '.iNA_GAMES.' SET played=0');
            $this->query('UPDATE '.iNA_CAT.' SET total_played=0');
        } else {
            $field = $mode === 'reset_last_player' ? 'last_player' : 'last_time';
            $this->query('UPDATE '.iNA_CAT.' SET '.$field.'=0');
        }
        $this->commit_attempted = true; $this->commit();
        return array('reset'=>true, 'mode'=>$mode);
    }
}

function phpbb_arcade_statistics_reset($database, $mode)
{
    global $arcade, $phpbb_root_path;
    $mode = phpbb_arcade_statistics_mode($mode);
    $writer = new PhpbbArcadeStatisticsWriter($database);
    try { return $writer->reset($mode); }
    finally {
        try {
            // An uncertain commit acknowledgement must not retain old caches.
            if ($writer->commit_attempted) {
                foreach (array('games','categories') as $key) {
                    try { $arcade->clear_cache($key, $phpbb_root_path); }
                    catch (Exception $ignored) {} catch (Error $ignored) {}
                }
            }
        } finally { $writer->release(); }
    }
}
