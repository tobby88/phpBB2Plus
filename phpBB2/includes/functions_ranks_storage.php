<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_board_config.php';

class PhpbbRanksWriter extends PhpbbBoardConfigWriter
{
    var $identity = '';
    var $finished = false;
    function __construct($database)
    {
        $rows = phpbb_acl_rows($database, 'SELECT CONNECTION_ID() AS rank_connection_id');
        if (count($rows) !== 1) { phpbb_acl_error('Ranks_storage_failed'); }
        $source_identity = (string)$rows[0]['rank_connection_id'];
        parent::__construct($database); $this->failure_key = 'Ranks_storage_failed';
        try {
            $this->identity = $this->current_identity();
            if ($this->identity === $source_identity) {
                // Reject a broken factory without closing its caller's socket.
                $this->connection = null; phpbb_acl_error('Ranks_storage_failed');
            }
            // Cooperate with ACP profile/account writers using the same owner.
            $name = 'attachment:' . md5($database->dbname . "\0" . ATTACHMENTS_TABLE);
            $rows = phpbb_acl_rows($this, "SELECT GET_LOCK('" . $name . "', 10) AS acquired");
            if (count($rows) !== 1 || (int)$rows[0]['acquired'] !== 1) { phpbb_acl_error('Ranks_storage_failed'); }
        } catch (Exception $error) { $this->release(); throw $error; }
        catch (Error $error) { $this->release(); throw $error; }
    }
    function current_identity()
    {
        if (!$this->connection) { phpbb_acl_error('Ranks_storage_failed'); }
        $rows = phpbb_acl_rows($this->connection, 'SELECT CONNECTION_ID() AS rank_connection_id');
        if (count($rows) !== 1) { phpbb_acl_error('Ranks_storage_failed'); }
        return (string)$rows[0]['rank_connection_id'];
    }
    function actor() { global $phpEx; return phpbb_acp_actor($this, 'admin_ranks.' . $phpEx); }
    function sql_query($sql, $transaction = false)
    {
        if ($this->finished || $this->current_identity() !== $this->identity) { phpbb_acl_error('Ranks_storage_failed'); }
        if (is_string($sql) && preg_match('/^\s*(INSERT|DELETE|UPDATE)\b/i', $sql, $command)) {
            if (!$this->connection || !$this->transactional) { phpbb_acl_error('Ranks_storage_failed'); }
            $allowed = strpos($sql, 'UPDATE ' . RANKS_TABLE . ' SET ') === 0
                || strpos($sql, 'UPDATE ' . USERS_TABLE . ' SET user_rank=0 WHERE ') === 0
                || strpos($sql, 'DELETE FROM ' . RANKS_TABLE . ' WHERE ') === 0
                || strpos($sql, 'INSERT INTO ' . RANKS_TABLE . ' (rank_title,rank_special,rank_min,rank_image) SELECT ') === 0;
            if (!$allowed) { phpbb_acl_error('Ranks_storage_failed'); }
            if (strtoupper($command[1]) !== 'UPDATE') {
                $this->actor(); $result = $this->connection->sql_query($sql, $transaction);
                if (!$result) { phpbb_acl_error('Ranks_storage_failed'); } return $result;
            }
        }
        $result = parent::sql_query($sql, $transaction);
        if ($sql === 'COMMIT' || $sql === 'ROLLBACK') { $this->finished = true; }
        return $result;
    }
}

function phpbb_rank_id($value, $allow_zero = false)
{
    if (!(is_string($value) || is_int($value)) || !preg_match('/^[0-9]{1,5}$/D', (string)$value)
        || (int)$value > 65535 || (!$allow_zero && (int)$value === 0)) { phpbb_acl_error('Must_select_rank'); }
    return (int)$value;
}

function phpbb_ranks_change($database, $request)
{
    global $userdata;
    if (!is_array($request) || !isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST'
        || empty($userdata['session_id']) || !is_string($userdata['session_id']) || !isset($request['sid'])
        || !is_string($request['sid']) || !hash_equals($userdata['session_id'], $request['sid'])) { phpbb_acl_error('Session_invalid'); }
    if (!isset($request['mode']) || !in_array($request['mode'], array('save','delete'), true)) { phpbb_acl_error('Must_select_rank'); }
    $delete = $request['mode'] === 'delete';
    if ($delete && !isset($request['confirm'])) { phpbb_acl_error('Must_select_rank'); }
    $id = phpbb_rank_id(isset($request['id']) ? $request['id'] : '0', !$delete);
    $values = array();
    if (!$delete) {
        foreach (array('title'=>'','rank_image'=>'','special_rank'=>'0','min_posts'=>'0') as $key=>$default) {
            $value = isset($request[$key]) ? $request[$key] : $default;
            if (!is_string($value) || strpos($value, "\0") !== false || preg_match('//u', $value) !== 1) { phpbb_acl_error('Must_select_rank'); }
            $values[$key] = phpbb_request_raw_value($value);
        }
        $title = trim($values['title']);
        if ($title === '' || preg_match_all('/./us', $title, $characters) === false || count($characters[0]) > 50
            || !in_array($values['special_rank'], array('0','1'), true)) { phpbb_acl_error('Must_select_rank'); }
        $special = (int)$values['special_rank']; $minimum = -1;
        if (!$special) {
            if (!preg_match('/^[0-9]{1,7}$/D', $values['min_posts']) || (int)$values['min_posts'] > 8388607) { phpbb_acl_error('Must_select_rank'); }
            $minimum = (int)$values['min_posts'];
        }
        $image = phpbb_profile_image_name(basename(trim($values['rank_image'])));
        if ($image !== '' && !preg_match('/\.(?:gif|png|jpg)$/iD', $image)) { $image = ''; }
        $values = array('rank_title'=>$title,'rank_special'=>$special,'rank_min'=>$minimum,'rank_image'=>$image);
    }
    $db = new PhpbbRanksWriter($database);
    try {
        $db->actor();
        $db->sql_query("SET SESSION sql_mode = CONCAT_WS(',', @@SESSION.sql_mode, 'STRICT_ALL_TABLES')");
        $db->sql_query('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED'); $db->sql_query('START TRANSACTION');
        foreach (array(RANKS_TABLE,USERS_TABLE,SESSIONS_TABLE,JR_ADMIN_TABLE) as $table) {
            $result=$db->sql_query('SELECT * FROM ' . $table . ' LIMIT 0'); $db->sql_freeresult($result); $name=$db->sql_escape($table);
            $rows=phpbb_acl_rows($db,"SELECT ENGINE,ROW_FORMAT,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND HEX(TABLE_NAME)=HEX('".$name."')");
            $columns=phpbb_acl_rows($db,"SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND HEX(TABLE_NAME)=HEX('".$name."') AND CHARACTER_SET_NAME IS NOT NULL AND (CHARACTER_SET_NAME<>'utf8mb4' OR COLLATION_NAME<>'utf8mb4_unicode_ci')");
            if(count($rows)!==1 || $rows[0]['ENGINE']!=='InnoDB' || strtolower($rows[0]['ROW_FORMAT'])!=='dynamic' || $rows[0]['TABLE_COLLATION']!=='utf8mb4_unicode_ci' || $columns) { phpbb_acl_error('Ranks_storage_failed'); }
        }
        if ($id && count(phpbb_acl_rows($db,'SELECT rank_id FROM '.RANKS_TABLE.' WHERE rank_id='.$id.' FOR UPDATE')) !== 1) { phpbb_acl_error('Must_select_rank'); }
        // Pin current authority, not an earlier request-entry role/grant.
        $actor=$db->actor(); $sid=$db->sql_escape($userdata['session_id']);
        foreach(array('SELECT session_id FROM '.SESSIONS_TABLE." WHERE session_id='".$sid."' AND HEX(session_id)=HEX('".$sid."')",
            'SELECT user_id FROM '.USERS_TABLE.' WHERE user_id='.(int)$actor['user_id'],
            'SELECT user_id FROM '.JR_ADMIN_TABLE.' WHERE user_id='.(int)$actor['user_id']) as $sql) { $result=$db->sql_query($sql.' LOCK IN SHARE MODE'); $db->sql_freeresult($result); }
        $actor=$db->actor();
        if ($id && ($delete || !$values['rank_special'])) {
            $db->sql_query('UPDATE '.USERS_TABLE.' SET user_rank=0 WHERE user_rank='.$id.' AND '.$actor['guard']);
            if(phpbb_acl_rows($db,'SELECT user_id FROM '.USERS_TABLE.' WHERE user_rank='.$id)) { phpbb_acl_error('Ranks_storage_failed'); }
        }
        if ($delete) {
            $db->sql_query('DELETE FROM '.RANKS_TABLE.' WHERE rank_id='.$id.' AND '.$actor['guard']);
            if(phpbb_acl_rows($db,'SELECT rank_id FROM '.RANKS_TABLE.' WHERE rank_id='.$id)) { phpbb_acl_error('Ranks_storage_failed'); }
        } else {
            $encoded=array();foreach($values as $key=>$value){$encoded[$key]=is_int($value)?(string)$value:"'".$db->sql_escape($value)."'";}
            if($id){$assignments=array();foreach($encoded as $key=>$value){$assignments[]=$key.'='.$value;}$sql='UPDATE '.RANKS_TABLE.' SET '.implode(',',$assignments).' WHERE rank_id='.$id.' AND '.$actor['guard'];}
            else{$sql='INSERT INTO '.RANKS_TABLE.' (rank_title,rank_special,rank_min,rank_image) SELECT '.implode(',',$encoded).' WHERE '.$actor['guard'];}
            $db->sql_query($sql); if(!$id){$id=(int)$db->sql_nextid();phpbb_rank_id($id);}
            $stored=phpbb_acl_rows($db,'SELECT * FROM '.RANKS_TABLE.' WHERE rank_id='.$id);
            if(count($stored)!==1){phpbb_acl_error('Ranks_storage_failed');}
            foreach($values as $key=>$value){if((string)$stored[0][$key] !== (string)$value){phpbb_acl_error('Ranks_storage_failed');}}
        }
        $db->actor(); $db->sql_query('COMMIT'); return $id;
    } finally { $db->release(); }
}
