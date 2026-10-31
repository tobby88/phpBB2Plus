<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_pafiledb.php';
require_once dirname(dirname(dirname(__FILE__))) . '/includes/functions_acl_storage.php';
require_once dirname(dirname(dirname(__FILE__))) . '/includes/functions_jr_admin.php';

class PaFileDbFileActionFailure extends RuntimeException {}
function pafiledb_file_fail($key = 'PA_file_failed') { throw new PaFileDbFileActionFailure($key); }

// Current permissions on the coordinator's owning connection. Selected file
// rows are locked before their authority is captured; intentional deletion or
// movement by this transaction must not invalidate its own final commit guard.
class PaFileDbFileAuthority
{
    private $storage;
    private $actor;
    private $mode;
    private $action = null;
    private $destination = 0;
    private $files = array();
    private $user = null;
    private $config = array();

    function __construct($storage, $prefix, $actor, $mode)
    {
        if (!is_object($storage) || !$storage->namespace_matches($prefix) || !is_array($actor) || !isset($actor['id'],$actor['sid'],$actor['logged']) ||
            !is_int($actor['id']) || !is_bool($actor['logged']) || ($actor['logged'] ? ($actor['id'] < 1 || $actor['id'] > 8388607) : $actor['id'] !== ANONYMOUS) ||
            !is_string($actor['sid']) || $actor['sid'] === '' || strlen($actor['sid']) > 32 || !in_array($mode, array('public','files','categories','settings','licenses','custom','permissions','checker'), true)) { pafiledb_file_fail('Not_Authorised'); }
        foreach (array('USERS_TABLE'=>'users','SESSIONS_TABLE'=>'sessions','CONFIG_TABLE'=>'config','JR_ADMIN_TABLE'=>'jr_admin_users','PA_FILES_TABLE'=>'pa_files','PA_CATEGORY_TABLE'=>'pa_cat','PA_CONFIG_TABLE'=>'pa_config','PA_AUTH_ACCESS_TABLE'=>'pa_auth','USER_GROUP_TABLE'=>'user_group','GROUPS_TABLE'=>'groups') as $constant => $suffix) {
            if (!defined($constant) || constant($constant) !== $prefix . $suffix) { pafiledb_file_fail(); }
        }
        $this->storage = $storage; $this->actor = $actor; $this->mode = $mode;
    }

    static function id($value, $zero = false)
    {
        if (!(is_string($value) || is_int($value)) || !preg_match($zero ? '/^(?:0|[1-9][0-9]*)$/D' : '/^[1-9][0-9]*$/D', (string)$value) || (float)$value > 2147483647) { pafiledb_file_fail('PA_file_invalid'); }
        return (int)$value;
    }

    private function rows($sql)
    {
        $r = $this->storage->sql_query($sql); try { return $this->storage->sql_fetchrowset($r); } finally { $this->storage->sql_freeresult($r); }
    }

    private function current()
    {
        global $userdata, $phpEx;
        $logged = isset($userdata['session_logged_in']) && in_array($userdata['session_logged_in'], array(true,1,'1'), true);
        if (!isset($userdata['user_id'],$userdata['session_id']) || (string)$userdata['user_id'] !== (string)$this->actor['id'] || !is_string($userdata['session_id']) || !hash_equals($this->actor['sid'], $userdata['session_id']) || $logged !== $this->actor['logged']) { pafiledb_file_fail('Not_Authorised'); }
        $ttl = $this->rows("SELECT config_value FROM " . CONFIG_TABLE . " WHERE config_name='session_length' FOR UPDATE");
        if (count($ttl) !== 1 || !preg_match('/^[1-9][0-9]*$/D', (string)$ttl[0]['config_value']) || (float)$ttl[0]['config_value'] > 2147483647) { pafiledb_file_fail(); }
        $sid = $this->storage->sql_escape($this->actor['sid']);
        // Use the primary key to limit locks to this session; retain HEX for
        // byte-exact matching under case-insensitive or padded collations.
        $sessions = $this->rows('SELECT session_id,session_user_id,session_logged_in,session_admin,session_time FROM ' . SESSIONS_TABLE . " WHERE session_id='" . $sid . "' AND HEX(session_id)=HEX('" . $sid . "') FOR UPDATE");
        if (count($sessions) !== 1 || (int)$sessions[0]['session_user_id'] !== $this->actor['id'] || (string)$sessions[0]['session_logged_in'] !== ($logged ? '1' : '0') || (int)$sessions[0]['session_time'] < time() - (int)$ttl[0]['config_value']) { pafiledb_file_fail('Session_invalid'); }
        $users = $this->rows('SELECT user_id,username,user_active,user_level,user_allowhtml,user_allowbbcode,user_allowsmile FROM ' . USERS_TABLE . ' WHERE user_id=' . $this->actor['id'] . ' FOR UPDATE');
        if (count($users) !== 1 || ($logged && ((string)$users[0]['user_active'] !== '1' || !in_array((int)$users[0]['user_level'], array(USER,MOD,ADMIN), true)))) { pafiledb_file_fail('Not_Authorised'); }
        $this->user = $users[0]; if (!$logged) { $this->user['user_level'] = USER; }
        if ($this->mode !== 'public') {
            if (!$logged || (string)$sessions[0]['session_admin'] !== '1' || empty($userdata['session_admin'])) { pafiledb_file_fail('Not_Authorised'); }
            $this->rows('SELECT user_id FROM ' . JR_ADMIN_TABLE . ' WHERE user_id=' . $this->actor['id'] . ' FOR UPDATE');
            $modules = array('files'=>'admin_pa_file.','categories'=>'admin_pa_category.','settings'=>'admin_pa_settings.','licenses'=>'admin_pa_license.','custom'=>'admin_pa_custom.','permissions'=>'admin_pa_catauth.','checker'=>'admin_pa_fchecker.');
            try { phpbb_acp_actor($this->storage, $modules[$this->mode] . $phpEx); }
            catch (PhpbbAclException $error) { pafiledb_file_fail('Not_Authorised'); }
        }
        $this->config = array(); foreach ($this->rows('SELECT config_name,config_value FROM ' . PA_CONFIG_TABLE . ' ORDER BY config_name FOR UPDATE') as $row) { $this->config[$row['config_name']] = $row['config_value']; }
    }

    private function grants()
    {
        $ids = array(); $grants = array(); if (!$this->actor['logged']) { return $grants; }
        foreach ($this->rows('SELECT group_id,user_pending FROM ' . USER_GROUP_TABLE . ' WHERE user_id=' . $this->actor['id'] . ' ORDER BY group_id FOR UPDATE') as $row) {
            if ((string)$row['user_pending'] !== '0') { continue; } $id = self::id($row['group_id']);
            if (count($this->rows('SELECT group_id FROM ' . GROUPS_TABLE . ' WHERE group_id=' . $id . ' FOR UPDATE')) === 1) { $ids[] = $id; }
        }
        if ($ids) { foreach ($this->rows('SELECT * FROM ' . PA_AUTH_ACCESS_TABLE . ' WHERE group_id IN(' . implode(',', array_unique($ids)) . ') ORDER BY group_id,cat_id FOR UPDATE') as $row) { $grants[(int)$row['cat_id']][] = $row; } }
        return $grants;
    }

    private function allowed($level, $field, $grants)
    {
        if (!in_array((string)$level, array((string)AUTH_ALL,(string)AUTH_REG,(string)AUTH_ACL,(string)AUTH_MOD,(string)AUTH_ADMIN), true)) { pafiledb_file_fail(); }
        if ((int)$level === AUTH_ALL) { return true; } if (!$this->actor['logged']) { return false; } if ((int)$level === AUTH_REG) { return true; }
        $policy = new pafiledb(); return (bool)$policy->auth_check_user((int)$level, (int)$level === AUTH_MOD ? 'auth_mod' : $field, $grants, (int)$this->user['user_level'] === ADMIN);
    }

    private function category($id, $grants, $require_upload = false, $require_file_view = false, $permissions = true)
    {
        $seen = array(); $leaf = null;
        while ($id !== 0) {
            if (isset($seen[$id])) { pafiledb_file_fail('Cat_not_exist'); } $seen[$id] = true;
            $rows = $this->rows('SELECT * FROM ' . PA_CATEGORY_TABLE . ' WHERE cat_id=' . $id . ' FOR UPDATE'); if (count($rows) !== 1) { pafiledb_file_fail('Cat_not_exist'); } $row = $rows[0];
            $access = isset($grants[$id]) ? $grants[$id] : array();
            if ($permissions) { foreach (array('auth_view','auth_read') as $field) { if (!$this->allowed($row[$field], $field, $access)) { pafiledb_file_fail('Not_Authorised'); } } }
            if ($leaf === null) {
                if ($require_upload && ((string)$row['cat_allow_file'] !== (string)PA_CAT_ALLOW_FILE || ($permissions && !$this->allowed($row['auth_upload'], 'auth_upload', $access)))) { pafiledb_file_fail('Not_Authorised'); }
                if ($require_file_view && !$this->allowed($row['auth_view_file'], 'auth_view_file', $access)) { pafiledb_file_fail('Not_Authorised'); }
                $leaf = $row;
            }
            $id = $row['cat_parent'] === null ? 0 : self::id($row['cat_parent'], true);
        }
        if ($leaf === null) { pafiledb_file_fail('Cat_not_exist'); } return $leaf;
    }

    function select($action, $ids, $destination = 0)
    {
        if ($this->action !== null || !in_array($action, array('create','edit','delete','approve','mirror','mirror-delete','category','download','settings','licenses','custom','permissions','checker'), true) || !is_array($ids) || count($ids) > 500) { pafiledb_file_fail('PA_file_invalid'); }
        if (($action === 'settings' && ($this->mode !== 'settings' || $ids || $destination !== 0)) || ($this->mode === 'settings' && $action !== 'settings')) { pafiledb_file_fail('Not_Authorised'); }
        if (($action === 'licenses' && ($this->mode !== 'licenses' || $ids || $destination !== 0)) || ($this->mode === 'licenses' && $action !== 'licenses')) { pafiledb_file_fail('Not_Authorised'); }
        if (($action === 'custom' && ($this->mode !== 'custom' || $ids || $destination !== 0)) || ($this->mode === 'custom' && $action !== 'custom')) { pafiledb_file_fail('Not_Authorised'); }
        if (($action === 'permissions' && ($this->mode !== 'permissions' || $ids || $destination !== 0)) || ($this->mode === 'permissions' && $action !== 'permissions')) { pafiledb_file_fail('Not_Authorised'); }
        if (($action === 'checker' && ($this->mode !== 'checker' || $ids || $destination !== 0)) || ($this->mode === 'checker' && $action !== 'checker')) { pafiledb_file_fail('Not_Authorised'); }
        $unique = array(); foreach ($ids as $id) { $id = self::id($id); if (isset($unique[$id])) { pafiledb_file_fail('PA_file_invalid'); } $unique[$id] = $id; }
        $this->destination = self::id($destination, true); $this->action = $action;
        if (($action === 'create' && ($unique || !$this->destination)) || ($action === 'edit' && (count($unique) !== 1 || !$this->destination)) || (in_array($action, array('delete','approve','mirror','mirror-delete','download'), true) && !$unique) || (in_array($action,array('mirror','mirror-delete','download'),true) && count($unique) !== 1) || ($action === 'download' && $this->mode !== 'public') || ($action === 'category' && ($this->mode !== 'categories' || $unique))) { pafiledb_file_fail('PA_file_invalid'); }
        $this->current();
        if ($unique) { foreach ($this->rows('SELECT * FROM ' . PA_FILES_TABLE . ' WHERE file_id IN(' . implode(',', $unique) . ') ORDER BY file_id FOR UPDATE') as $row) { $this->files[(int)$row['file_id']] = $row; } if (count($this->files) !== count($unique)) { pafiledb_file_fail('File_not_exist'); } }
        $this->check(); return $this->files;
    }

    function check()
    {
        if ($this->action === null) { pafiledb_file_fail('PA_file_invalid'); } $this->current();
        if ($this->mode === 'settings') { if ($this->action !== 'settings') { pafiledb_file_fail('Not_Authorised'); } return true; }
        if ($this->mode === 'licenses') { if ($this->action !== 'licenses') { pafiledb_file_fail('Not_Authorised'); } return true; }
        if ($this->mode === 'custom') { if ($this->action !== 'custom') { pafiledb_file_fail('Not_Authorised'); } return true; }
        if ($this->mode === 'permissions') { if ($this->action !== 'permissions') { pafiledb_file_fail('Not_Authorised'); } return true; }
        if ($this->mode === 'checker') { if ($this->action !== 'checker') { pafiledb_file_fail('Not_Authorised'); } return true; }
        if ($this->mode === 'categories') { if ($this->action !== 'category') { pafiledb_file_fail('Not_Authorised'); } return true; }
        if ($this->mode === 'files') {
            if (in_array($this->action, array('create','edit'), true)) { $this->category($this->destination, array(), true, false, false); }
            if ($this->action === 'mirror') { foreach ($this->files as $file) { $this->category(self::id($file['file_catid']),array(),true,false,false); } }
            return true;
        }
        if (in_array($this->action, array('mirror','mirror-delete','category'), true)) { pafiledb_file_fail('Not_Authorised'); }
        $grants = $this->grants();
        if ($this->action === 'create') { $this->category($this->destination, $grants, true); return true; }
        $policy = new pafiledb();
        foreach ($this->files as $file) {
            $category = self::id($file['file_catid']); $cat = $this->category($category, $grants, false, true); $access = isset($grants[$category]) ? $grants[$category] : array();
            $moderator = $this->actor['logged'] && $policy->auth_check_user(AUTH_MOD, 'auth_mod', $access, (int)$this->user['user_level'] === ADMIN);
            if ($this->action === 'download') {
                if (!$this->allowed($cat['auth_download'],'auth_download',$access) || ((string)$file['file_approved'] !== '1' && (!$this->actor['logged'] || (!$moderator && (int)$file['user_id'] !== $this->actor['id'])))) { pafiledb_file_fail('File_not_exist'); }
            }
            elseif ($this->action === 'approve') {
                if (!$moderator || ((int)$this->user['user_level'] !== ADMIN && (!isset($this->config['validator']) || $this->config['validator'] !== 'validator_mod'))) { pafiledb_file_fail('Not_Authorised'); }
            }
            else {
                $field = $this->action === 'edit' ? 'auth_edit_file' : 'auth_delete_file';
                if (!$moderator && (!$this->actor['logged'] || (int)$file['user_id'] !== $this->actor['id'] || !$this->allowed($cat[$field], $field, $access))) { pafiledb_file_fail('Not_Authorised'); }
            }
            if ($this->action === 'edit' && $this->destination !== $category) { $this->category($this->destination, $grants, true); }
        }
        return true;
    }

    function user() { return $this->user; }
    function settings() { return $this->config; }
    function selected() { return $this->files; }
}
