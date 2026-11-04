<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_arcade_score_storage.php';

function phpbb_arcade_moderator_actor($database, $category)
{
    global $userdata, $lang;
    $category = phpbb_arcade_score_integer($category, -1);
    if (empty($userdata['session_logged_in']) || !isset($userdata['user_id'], $userdata['session_id'])
        || !(is_int($userdata['user_id']) || is_string($userdata['user_id']))
        || !preg_match('/^[1-9][0-9]{0,7}$/D', (string)$userdata['user_id']) || (float)$userdata['user_id'] > 16777215
        || !is_string($userdata['session_id']) || $userdata['session_id'] === '' || strlen($userdata['session_id']) > 32) {
        throw new PhpbbOwnedStorageFailure('Not_Moderator');
    }
    $id = (int)$userdata['user_id']; $sid = $database->sql_escape($userdata['session_id']);
    $users = phpbb_arcade_score_rows($database, 'SELECT u.user_id,u.user_level,u.user_active FROM '.USERS_TABLE.' u INNER JOIN '.SESSIONS_TABLE.
        " s ON s.session_user_id=u.user_id WHERE u.user_id=$id AND s.session_id='$sid' AND HEX(s.session_id)=HEX('$sid') AND s.session_logged_in=1");
    if (count($users) !== 1 || empty($users[0]['user_active'])) { throw new PhpbbOwnedStorageFailure('Not_Moderator'); }
    $user = $users[0]; $user['root'] = (int)$user['user_level'] === ADMIN;
    $cat = array('cat_id'=>$category, 'cat_name'=>isset($lang['all_games'])?$lang['all_games']:'', 'mod_id'=>0);
    if ($category > 0) {
        $rows = phpbb_arcade_score_rows($database, 'SELECT cat_id,cat_name,cat_type,mod_id FROM '.iNA_CAT.' WHERE cat_id='.$category);
        if (count($rows) !== 1 || $rows[0]['cat_type'] === 'l') { throw new PhpbbOwnedStorageFailure('Not_Moderator'); }
        $cat = $rows[0];
    }
    // Nonpositive scopes were always administrator-only. A normal category
    // moderator needs neither an ACP session nor a delegated ACP module.
    if (!$user['root'] && ($category < 1 || (int)$cat['mod_id'] !== $id)) { throw new PhpbbOwnedStorageFailure('Not_Moderator'); }
    $user['category'] = $cat;
    return $user;
}
function phpbb_arcade_moderated_game($database, $id, $category)
{
    $category = phpbb_arcade_score_integer($category, -1);
    $game = phpbb_arcade_score_game($database, $id, '');
    if ($game['cat_id'] === null || (string)$game['cat_id'] !== (string)$category) { throw new PhpbbOwnedStorageFailure('arcade_score_changed'); }
    return $game;
}
class PhpbbArcadeModeratorScoreWriter extends PhpbbArcadeScoreWriter
{
    protected $category_id;
    function __construct($database, $id, $category)
    {
        $this->category_id = phpbb_arcade_score_integer($category, -1);
        parent::__construct($database, $id);
    }
    protected function actor() { return phpbb_arcade_moderator_actor($this, $this->category_id); }
    protected function lock_actor()
    {
        global $userdata;
        $actor = $this->actor(); $sid = $this->escape($userdata['session_id']);
        $locks = array('SELECT session_id FROM '.SESSIONS_TABLE." WHERE session_id='$sid' AND HEX(session_id)=HEX('$sid')",
            'SELECT user_id FROM '.USERS_TABLE.' WHERE user_id='.(int)$actor['user_id']);
        if ($this->category_id > 0) { $locks[] = 'SELECT cat_id FROM '.iNA_CAT.' WHERE cat_id='.$this->category_id; }
        foreach ($locks as $sql) { $this->rows($sql.' LOCK IN SHARE MODE'); }
        $this->actor();
    }
    protected function identity_name($game)
    {
        if ($game['cat_id'] === null || (string)$game['cat_id'] !== (string)$this->category_id) { $this->storage_fail('arcade_score_changed'); }
        return parent::identity_name($game);
    }
}
function phpbb_arcade_moderator_score_delete($database, $id, $category, $board, $player, $revision)
{
    global $arcade, $phpbb_root_path;
    $id = phpbb_arcade_score_integer($id, 1); $category = phpbb_arcade_score_integer($category, -1);
    $player = phpbb_arcade_score_integer($player); phpbb_arcade_score_table($board);
    if (!is_string($revision) || !preg_match('/^[a-f0-9]{64}$/D', $revision)) { throw new PhpbbOwnedStorageFailure('arcade_score_changed'); }
    $writer = new PhpbbArcadeModeratorScoreWriter($database, $id, $category);
    try { return $writer->change($board, $player, $revision, 'delete', null); }
    finally {
        try { if ($writer->commit_attempted) { foreach (array('games','categories','best_player','best_at_player') as $key) {
            try { $arcade->clear_cache($key, $phpbb_root_path); } catch (Exception $ignored) {} catch (Error $ignored) {}
        } } } finally { $writer->release(); }
    }
}
