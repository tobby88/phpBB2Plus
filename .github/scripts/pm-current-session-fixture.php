<?php
// Explicit fixture login: switching the cached actor also switches the stored
// session. Revocation tests mutate these independently and never use this helper.
function pm_fixture_login($actor)
{
    global $userdata;
    $server = isset($GLOBALS['pm_repair_server']) ? $GLOBALS['pm_repair_server'] : $GLOBALS['mutation_server'];
    $query = $server->pdo->prepare('UPDATE ' . SESSIONS_TABLE . ' SET session_user_id=? WHERE session_id=?');
    $query->execute(array((int)$actor, $userdata['session_id']));
    $userdata['user_id'] = (int)$actor;
}
