<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_profile_fields.php';
require_once dirname(__DIR__) . '/attach_mod/includes/functions_quota_storage.php';

// Matches the canonical users schema, including post-install ALTER additions.
// A corrupted custom definition must never target a core account column.
function phpbb_profile_definition_core_columns()
{
    return array(
        'user_id','user_active','username','user_password',
        'user_session_time','user_session_page','user_session_topic','user_lastvisit',
        'user_regdate','user_reg_ip','user_reg_host','user_level',
        'user_posts','user_timezone','user_style','user_lang',
        'user_dateformat','user_new_privmsg','user_unread_privmsg','user_last_privmsg',
        'user_login_tries','user_last_login_try','user_emailtime','user_viewemail',
        'user_attachsig','user_setbm','user_allowhtml','user_allowbbcode',
        'user_allowsmile','user_allowavatar','user_allow_pm','user_allow_viewonline',
        'user_notify','user_notify_pm','user_popup_pm','user_rank',
        'user_avatar','user_avatar_type','user_email','user_icq',
        'user_website','user_from','user_from_flag','user_sig',
        'user_sig_bbcode_uid','user_aim','user_yim','user_msnm',
        'user_fb','user_ig','user_pt','user_twr',
        'user_skp','user_tg','user_li','user_tt',
        'user_dc','user_signal','user_threema','user_occ',
        'user_interests','user_actkey','user_newpasswd','ct_last_pw_reset',
        'ct_enable_ip_warn','ct_last_used_ip','ct_last_ip','ct_last_pw_change',
        'ct_global_msg_read','ct_miserable_user','user_sub_forum','user_split_cat',
        'user_last_topic_title','user_sub_level_links','user_display_viewonline','user_birthday',
        'user_next_birthday_greeting','user_gender','user_color_group','user_lastlogon',
        'user_totaltime','user_totallogon','user_totalpages','user_calendar_display_open',
        'user_calendar_header_cells','user_calendar_week_start','user_calendar_nb_row','user_calendar_birthday',
        'user_calendar_forum','user_warnings','user_passwd_change','user_badlogin',
        'user_blocktime','user_block_by','user_split_global_announce','user_split_announce',
        'user_split_sticky','user_split_news','user_split_topic_split','user_absence',
        'user_absence_mode','user_absence_text','user_announcement_date_display','user_announcement_display',
        'user_announcement_display_forum','user_announcement_split','user_announcement_forum','user_use_ajax_preview',
        'user_use_ajax_edit','games_block_pm','arcade_banned'
    );
}

function phpbb_profile_definition_fields()
{
    return array('field_name','field_description','field_type','text_field_default','text_field_maxlen',
        'text_area_default','text_area_maxlen','radio_button_default','radio_button_values',
        'checkbox_default','checkbox_values','is_required','users_can_view','view_in_profile',
        'profile_location','view_in_memberlist','view_in_topic','topic_location');
}

function phpbb_profile_definition_revision($row)
{
    if (!is_array($row)) { phpbb_acl_error('Profile_definition_changed'); }
    $snapshot = array();
    foreach (array_merge(array('field_id','field_column'), phpbb_profile_definition_fields()) as $key)
    {
        $value = isset($row[$key]) ? $row[$key] : null;
        if ($value !== null && !is_scalar($value)) { phpbb_acl_error('Profile_definition_changed'); }
        $snapshot[$key] = $value === null ? null : (string)$value;
    }
    $json = json_encode($snapshot);
    if ($json === false) { phpbb_acl_error('Profile_definition_changed'); }
    return hash('sha256', $json);
}

// Internal, already HTML-encoded definition values; never allow caller-supplied
// identifiers, SQL fragments, mapping columns or row IDs in the assignment set.
function phpbb_profile_definition_values($values)
{
    if (!is_array($values)) { phpbb_acl_error('Profile_definition_invalid'); }
    $keys = array_keys($values); sort($keys); $expected = phpbb_profile_definition_fields(); sort($expected);
    if ($keys !== $expected) { phpbb_acl_error('Profile_definition_invalid'); }
    foreach ($values as $key=>$value)
    {
        if (!(is_string($value) || is_int($value)) || preg_match('//u', (string)$value) !== 1 || strpos((string)$value, "\0") !== false)
        { phpbb_acl_error('Profile_definition_invalid'); }
        $values[$key] = (string)$value;
    }
    foreach (array('field_type'=>array('0','1','2','3'), 'is_required'=>array('0','1'),
        'users_can_view'=>array('0','1'), 'view_in_profile'=>array('0','1'), 'profile_location'=>array('1','2'),
        'view_in_memberlist'=>array('0','1'), 'view_in_topic'=>array('0','1'), 'topic_location'=>array('1','2','3')) as $key=>$allowed)
    {
        if (!in_array($values[$key], $allowed, true)) { phpbb_acl_error('Profile_definition_invalid'); }
    }
    foreach (array('text_field_maxlen'=>array(1,TEXT_FIELD_MAXLENGTH), 'text_area_maxlen'=>array(TEXTAREA_MINLENGTH,TEXTAREA_MAXLENGTH)) as $key=>$limits)
    {
        if (!preg_match('/^[0-9]{1,5}$/D', $values[$key]) || (int)$values[$key] < $limits[0] || (int)$values[$key] > $limits[1])
        { phpbb_acl_error('Profile_definition_invalid'); }
        $values[$key] = (string)(int)$values[$key];
    }
    foreach (array('field_name'=>255,'field_description'=>255,'text_field_default'=>(int)$values['text_field_maxlen'],
        'text_area_default'=>(int)$values['text_area_maxlen'],'radio_button_default'=>255,
        'radio_button_values'=>60000,'checkbox_default'=>60000,'checkbox_values'=>60000) as $key=>$maximum)
    { if (strlen($values[$key]) > $maximum) { phpbb_acl_error('Profile_definition_invalid'); } }
    if (trim($values['field_name']) === '' || preg_match('/[\x00-\x1f\x7f]/', $values['field_name'])) { phpbb_acl_error('Profile_definition_invalid'); }
    foreach (array('radio_button_values'=>'radio_button_default','checkbox_values'=>'checkbox_default') as $key=>$default)
    {
        $options = $values[$key] === '' ? array() : explode(',', $values[$key]);
        if (count($options) > 100 || count(array_unique($options)) !== count($options)) { phpbb_acl_error('Profile_definition_invalid'); }
        foreach ($options as $option)
        { if ($option === '' || strlen($option) > 255 || preg_match('/[\x00-\x1f\x7f]/', $option)) { phpbb_acl_error('Profile_definition_invalid'); } }
        $defaults = $default === 'checkbox_default' ? ($values[$default] === '' ? array() : explode(',', $values[$default])) : ($values[$default] === '' ? array() : array($values[$default]));
        foreach ($defaults as $value) { if (!in_array($value, $options, true)) { phpbb_acl_error('Profile_definition_invalid'); } }
        if (($values['field_type'] === '2' && $key === 'radio_button_values') || ($values['field_type'] === '3' && $key === 'checkbox_values'))
        { if (!$options) { phpbb_acl_error('Profile_definition_invalid'); } }
    }
    return $values;
}

// Restate the server's existing attributes verbatim: MODIFY otherwise drops
// omitted defaults/nullability/comments. Only ordinary, unindexed text columns
// are eligible. Unsupported definitions fail closed instead of being rebuilt.
function phpbb_profile_definition_widen_sql($table, $column, $create, $capacity, $maximum, $literal_override = null, &$has_literal_default = false)
{
    foreach (array($table, $column) as $identifier)
    { if (!is_string($identifier) || !preg_match('/^[a-z_][a-z0-9_]{0,63}$/D', $identifier)) { phpbb_acl_error('Profile_definition_capacity'); } }
    if (!is_int($maximum) || $maximum < 1 || $maximum > 60000 || in_array($column, phpbb_profile_definition_core_columns(), true)
        || !is_array($capacity) || !isset($capacity['DATA_TYPE'], $capacity['COLUMN_KEY'], $capacity['EXTRA'])
        || $capacity['COLUMN_KEY'] !== '' || !in_array($capacity['EXTRA'], array('', 'DEFAULT_GENERATED'), true) || !is_string($create)) { phpbb_acl_error('Profile_definition_capacity'); }
    if (!preg_match('/^\s*`' . preg_quote($column, '/') . '` (varchar\([0-9]+\)|tinytext|text|mediumtext|longtext)([^\r\n]*)$/mi', $create, $match))
    { phpbb_acl_error('Profile_definition_capacity'); }
    $old_type = strtolower($match[1]); $tail = preg_replace('/,$/', '', $match[2]);
    $type = preg_replace('/\([0-9]+\)$/', '', $old_type);
    if ($type !== $capacity['DATA_TYPE']) { phpbb_acl_error('Profile_definition_capacity'); }
    $literal = <<<'SQL_LITERAL'
'(?:[^'\\]|\\[\s\S]|'')*'
SQL_LITERAL;
    $pattern = '/^(?: CHARACTER SET utf8mb4)?(?: COLLATE utf8mb4_unicode_ci)?(?: NOT NULL| NULL)?(?: DEFAULT (NULL|' . $literal . '|\(' . $literal . '\)))?(?: COMMENT ' . $literal . ')?$/D';
    if (!preg_match($pattern, $tail, $attributes)) { phpbb_acl_error('Profile_definition_capacity'); }
    $has_literal_default = isset($attributes[1]) && $attributes[1] !== '' && $attributes[1] !== 'NULL';
    if ($literal_override !== null)
    {
        if (!$has_literal_default || !is_string($literal_override) || !preg_match('/^' . $literal . '$/D', $literal_override)) { phpbb_acl_error('Profile_definition_capacity'); }
        $needle = ' DEFAULT ' . $attributes[1]; $position = strpos($tail, $needle);
        $tail = substr_replace($tail, ' DEFAULT ' . $literal_override, $position, strlen($needle));
        $attributes[1] = $literal_override;
    }
    if ($type === 'varchar' && $maximum <= 1024)
    {
        if (!preg_match('/^varchar\(([0-9]+)\)$/D', $old_type, $length) || (int)$length[1] >= $maximum) { phpbb_acl_error('Profile_definition_capacity'); }
        $new_type = 'varchar(' . $maximum . ')';
    }
    else
    {
        if (!in_array($type, array('varchar','tinytext','text'), true)) { phpbb_acl_error('Profile_definition_capacity'); }
        $new_type = $type === 'tinytext' && $maximum <= 1024 ? 'text' : 'mediumtext';
        // MySQL requires a parenthesized expression for a non-NULL TEXT default;
        // MariaDB accepts the same literal expression. Never evaluate expressions.
        if (isset($attributes[1]) && isset($attributes[1][0]) && $attributes[1][0] === "'")
        {
            $needle = ' DEFAULT ' . $attributes[1]; $position = strpos($tail, $needle);
            $tail = substr_replace($tail, ' DEFAULT (' . $attributes[1] . ')', $position, strlen($needle));
        }
    }
    return 'ALTER TABLE `' . $table . '` MODIFY COLUMN `' . $column . '` ' . $new_type . $tail;
}

// Coordinated metadata publication. Physical capacity preparation is separate:
// this transaction must never execute DDL or rename/shrink a users-table column.
class PhpbbProfileDefinitionWriter extends PhpbbAttachQuotaWriter
{
    var $definition_action;
    var $confirmed = false;
    var $commit_attempted = false;
    function __construct($database, $action, $request)
    {
        global $phpEx;
        phpbb_attach_quota_post($request);
        if (!in_array($action, array('add','edit'), true)) { phpbb_acl_error('Profile_definition_invalid'); }
        parent::__construct($database, 'user');
        $this->definition_action = $action;
        $this->route = 'admin_profile_fields.' . $phpEx . '?mode=' . $action . '&pfid=x';
        $this->failure_key = 'Profile_definition_failed';
    }
    function pin_actor()
    {
        global $userdata;
        $actor = $this->actor(); $sid = $this->sql_escape($userdata['session_id']);
        foreach (array('SELECT session_id FROM ' . SESSIONS_TABLE . " WHERE session_id='$sid' AND HEX(session_id)=HEX('$sid')",
            'SELECT user_id FROM ' . USERS_TABLE . ' WHERE user_id=' . (int)$actor['user_id'],
            'SELECT user_id FROM ' . JR_ADMIN_TABLE . ' WHERE user_id=' . (int)$actor['user_id']) as $sql)
        { $r = $this->sql_query($sql . ' LOCK IN SHARE MODE'); $this->sql_freeresult($r); }
        if (phpbb_acl_rows($this, 'SELECT ban_id FROM ' . BANLIST_TABLE . ' WHERE ban_userid=' . (int)$actor['user_id'] . ' LOCK IN SHARE MODE'))
        { phpbb_acl_error('Not_Authorised'); }
        return $this->actor();
    }
    function actor()
    {
        global $userdata;
        if ($this->transactional)
        {
            $id = phpbb_acl_id(isset($userdata['user_id']) ? $userdata['user_id'] : null);
            $sid = isset($userdata['session_id']) && is_string($userdata['session_id']) ? $this->sql_escape($userdata['session_id']) : '';
            if (!phpbb_acl_rows($this, 'SELECT session_id FROM ' . SESSIONS_TABLE . " WHERE session_id='$sid' AND HEX(session_id)=HEX('$sid') AND session_user_id=" . $id . ' AND session_logged_in=1 AND session_admin=1 LOCK IN SHARE MODE'))
            { phpbb_acl_error('Not_Authorised'); }
        }
        return parent::actor();
    }
    function sql_query($sql, $transaction = false)
    {
        // Authority decisions inside REPEATABLE READ use current locking reads,
        // never a snapshot made before a competing revocation committed.
        $tables = array(USERS_TABLE, SESSIONS_TABLE, JR_ADMIN_TABLE, BANLIST_TABLE);
        $pattern = implode('|', array_map(function($table) { return preg_quote($table, '/'); }, $tables));
        if ($this->transactional && preg_match('/^SELECT\s+[^()]+?\s+FROM\s+(?:' . $pattern . ')(?:\s|$)/i', $sql)
            && !preg_match('/\b(?:FOR UPDATE|LOCK IN SHARE MODE)\s*$/i', $sql))
        { $sql .= ' LOCK IN SHARE MODE'; }
        return parent::sql_query($sql, $transaction);
    }
    function current_definition($id, $revision)
    {
        if (!is_string($revision) || !preg_match('/^[a-f0-9]{64}$/D', $revision)) { phpbb_acl_error('Profile_definition_changed'); }
        // Lock the range, including other definitions with legacy NULL mappings.
        $rows = phpbb_acl_rows($this, 'SELECT * FROM ' . PROFILE_FIELDS_TABLE . ' ORDER BY field_id FOR UPDATE');
        $current = null;
        foreach ($rows as $row) { if ((int)$row['field_id'] === $id) { $current = $row; } }
        if (!$current || !array_key_exists('field_column', $current)) { phpbb_acl_error('Profile_definition_upgrade'); }
        if (!hash_equals(phpbb_profile_definition_revision($current), $revision)) { phpbb_acl_error('Profile_definition_changed'); }
        $column = phpbb_profile_field_column($current);
        if ($column === '' || in_array($column, phpbb_profile_definition_core_columns(), true)) { phpbb_acl_error('Profile_definition_invalid'); }
        foreach ($rows as $row)
        { if ((int)$row['field_id'] !== $id && phpbb_profile_field_column($row) === $column) { phpbb_acl_error('Profile_definition_changed'); } }
        return $current;
    }
    function column_capacity($column)
    {
        $rows = phpbb_acl_rows($this, "SELECT DATA_TYPE,CHARACTER_MAXIMUM_LENGTH,CHARACTER_OCTET_LENGTH,EXTRA,COLUMN_KEY FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" . $this->sql_escape(USERS_TABLE) . "' AND COLUMN_NAME='" . $this->sql_escape($column) . "'");
        if (count($rows) !== 1 || !in_array($rows[0]['DATA_TYPE'], array('char','varchar','tinytext','text','mediumtext','longtext'), true))
        { phpbb_acl_error('Profile_definition_invalid'); }
        // MySQL labels even a constant parenthesized TEXT default this way.
        // Virtual/stored generated account columns are never custom storage.
        if (!in_array($rows[0]['EXTRA'], array('', 'DEFAULT_GENERATED'), true)) { phpbb_acl_error('Profile_definition_invalid'); }
        return $rows[0];
    }
    function capacity_sufficient($capacity, $values)
    {
        $maximum = $values['field_type'] === '0' ? (int)$values['text_field_maxlen'] : ($values['field_type'] === '1' ? (int)$values['text_area_maxlen'] : ($values['field_type'] === '2' ? 255 : 60000));
        return (float)$capacity['CHARACTER_MAXIMUM_LENGTH'] >= $maximum && (float)$capacity['CHARACTER_OCTET_LENGTH'] >= $maximum * 4;
    }
    function assert_capacity($column, $values)
    {
        if (!$this->capacity_sufficient($this->column_capacity($column), $values))
        { phpbb_acl_error('Profile_definition_capacity'); }
    }
    private function prepare_edit_capacity($id, $revision, $values, $current)
    {
        $column = phpbb_profile_field_column($current); $capacity = $this->column_capacity($column);
        if ($this->capacity_sufficient($capacity, $values)) { return; }
        // Do not reinterpret CHAR padding, generated columns or index semantics.
        if ($capacity['DATA_TYPE'] === 'char' || $capacity['COLUMN_KEY'] !== '') { phpbb_acl_error('Profile_definition_capacity'); }
        $rows = phpbb_acl_rows($this, 'SHOW CREATE TABLE `' . USERS_TABLE . '`');
        if (count($rows) !== 1 || !isset($rows[0]['Create Table'])) { phpbb_acl_error('Profile_definition_capacity'); }
        $maximum = $values['field_type'] === '0' ? (int)$values['text_field_maxlen'] : ($values['field_type'] === '1' ? (int)$values['text_area_maxlen'] : ($values['field_type'] === '2' ? 255 : 60000));
        $has_literal_default = false;
        $sql = phpbb_profile_definition_widen_sql(USERS_TABLE, $column, $rows[0]['Create Table'], $capacity, $maximum, null, $has_literal_default);
        if ($has_literal_default)
        {
            // MariaDB's SHOW metadata can replace non-BMP default characters
            // with '?'. Read the actual literal default while the column is
            // pinned, not that lossy presentation. Never evaluate an arbitrary
            // default expression: the planner has already required a literal.
            $defaults = phpbb_acl_rows($this, 'SELECT DEFAULT(`' . $column . '`) AS literal_default FROM `' . USERS_TABLE . '` LIMIT 1');
            if (count($defaults) !== 1 || !is_string($defaults[0]['literal_default'])) { phpbb_acl_error('Profile_definition_capacity'); }
            $sql = phpbb_profile_definition_widen_sql(USERS_TABLE, $column, $rows[0]['Create Table'], $capacity, $maximum, "'" . $this->sql_escape($defaults[0]['literal_default']) . "'");
        }
        // Release our metadata/authority locks before DDL, but retain the shared
        // writer mutex. ALTER must not implicitly commit a metadata publication.
        $this->sql_query('ROLLBACK');
        $actor = $this->actor();
        if (phpbb_acl_rows($this, 'SELECT ban_id FROM ' . BANLIST_TABLE . ' WHERE ban_userid=' . (int)$actor['user_id'])) { phpbb_acl_error('Not_Authorised'); }
        // Only this private, generated widening command bypasses the DML guard.
        // A failed/lost reply leaves the old definition; retry discovers capacity.
        if (!$this->connection->sql_query('SET SESSION lock_wait_timeout=10') || !$this->connection->sql_query($sql)) { phpbb_acl_error('Profile_definition_capacity'); }
        $this->begin(array(PROFILE_FIELDS_TABLE, BANLIST_TABLE), true); $this->pin_actor();
        $current = $this->current_definition($id, $revision);
        if (phpbb_profile_field_column($current) !== $column) { phpbb_acl_error('Profile_definition_changed'); }
        $this->assert_capacity($column, $values);
    }
    private function creation_job($operation, $values)
    {
        global $userdata;
        $rows = phpbb_acl_rows($this, "SELECT * FROM " . PROFILE_FIELD_JOBS_TABLE . " WHERE operation_key='" . $operation . "' FOR UPDATE");
        if (!$rows) { return null; }
        $job = $rows[0];
        if ((int)$job['actor_id'] !== (int)$userdata['user_id'] || !hash_equals($job['session_hash'], hash('sha256', $userdata['session_id']))
            || !hash_equals($job['payload_hash'], phpbb_profile_definition_revision($values))
            || $job['field_column'] !== 'cpf_' . substr($operation, 0, 32)
            || !in_array($job['job_state'], array('staged','published'), true)) { phpbb_acl_error('Profile_definition_changed'); }
        return $job;
    }
    private function creation_definitions($values, $column, $published_id = null)
    {
        // Explicitly require the upgrade even on an empty profile_fields table.
        $r = $this->sql_query('SELECT field_column FROM ' . PROFILE_FIELDS_TABLE . ' LIMIT 0'); $this->sql_freeresult($r);
        $rows = phpbb_acl_rows($this, 'SELECT * FROM ' . PROFILE_FIELDS_TABLE . ' ORDER BY field_id FOR UPDATE');
        $found = false;
        foreach ($rows as $row)
        {
            if (phpbb_profile_field_column($row) === $column)
            {
                if ($published_id === null || (int)$row['field_id'] !== $published_id || $row['field_column'] !== $column) { phpbb_acl_error('Profile_definition_changed'); }
                $found = true;
            }
        }
        if ($published_id !== null)
        { if (!$found) { phpbb_acl_error('Profile_definition_changed'); } return; }
        // Let the real database collation decide label collisions, not PHP's
        // case-sensitive comparison. The range above pins concurrent inserts.
        if (phpbb_acl_rows($this, 'SELECT field_id FROM ' . PROFILE_FIELDS_TABLE . " WHERE field_name='" . $this->sql_escape($values['field_name']) . "' FOR UPDATE"))
        { phpbb_acl_error('field_exists'); }
    }
    private function staged_column($column, $operation)
    {
        $rows = phpbb_acl_rows($this, "SELECT DATA_TYPE,IS_NULLABLE,COLUMN_COMMENT,EXTRA,CHARACTER_SET_NAME,COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" . $this->sql_escape(USERS_TABLE) . "' AND COLUMN_NAME='" . $column . "'");
        if (!$rows) { return false; }
        $row = $rows[0];
        if (count($rows) !== 1 || $row['DATA_TYPE'] !== 'mediumtext' || $row['IS_NULLABLE'] !== 'YES' || $row['EXTRA'] !== ''
            || $row['CHARACTER_SET_NAME'] !== 'utf8mb4' || $row['COLLATION_NAME'] !== 'utf8mb4_unicode_ci'
            || $row['COLUMN_COMMENT'] !== 'phpbb-profile-job:' . $operation) { phpbb_acl_error('Profile_definition_changed'); }
        return true;
    }
    function create($operation, $values)
    {
        global $userdata;
        try
        {
            if ($this->definition_action !== 'add') { phpbb_acl_error('Not_Authorised'); }
            if (!is_string($operation) || !preg_match('/^[a-f0-9]{64}$/D', $operation)) { phpbb_acl_error('Profile_definition_invalid'); }
            $values = phpbb_profile_definition_values($values); $column = 'cpf_' . substr($operation, 0, 32);
            $tables = array(PROFILE_FIELDS_TABLE, PROFILE_FIELD_JOBS_TABLE, BANLIST_TABLE);
            $this->begin($tables, true); $actor = $this->pin_actor();
            $job = $this->creation_job($operation, $values);
            if ($job && $job['job_state'] === 'published')
            {
                $id = phpbb_acl_id($job['field_id']); $this->creation_definitions($values, $column, $id);
                $this->sql_query('ROLLBACK'); $this->confirmed = true; return $id;
            }
            $this->creation_definitions($values, $column);
            if (!$job)
            {
                // Even a column carrying a copied marker is not ours without
                // its prior durable journal record. Never adopt arbitrary data.
                if (phpbb_acl_rows($this, "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" . $this->sql_escape(USERS_TABLE) . "' AND COLUMN_NAME='" . $column . "'")) { phpbb_acl_error('Profile_definition_changed'); }
                $now = time();
                $this->sql_query('INSERT INTO ' . PROFILE_FIELD_JOBS_TABLE . " (operation_key,actor_id,session_hash,payload_hash,field_column,job_state,created_at,updated_at) VALUES ('" . $operation . "'," . (int)$actor['user_id'] . ",'" . hash('sha256', $userdata['session_id']) . "','" . phpbb_profile_definition_revision($values) . "','" . $column . "','staged'," . $now . ',' . $now . ')');
                // If this reply is lost, no DDL runs. The durable staged receipt
                // makes submitting the exact same form safe after reconnecting.
                $this->commit();
            }
            else { $this->sql_query('ROLLBACK'); }
            if (!$this->staged_column($column, $operation))
            {
                $actor = $this->actor();
                if (phpbb_acl_rows($this, 'SELECT ban_id FROM ' . BANLIST_TABLE . ' WHERE ban_userid=' . (int)$actor['user_id'])) { phpbb_acl_error('Not_Authorised'); }
                $ddl = 'ALTER TABLE `' . USERS_TABLE . '` ADD COLUMN `' . $column . "` MEDIUMTEXT NULL COMMENT 'phpbb-profile-job:" . $operation . "'";
                if (!$this->connection->sql_query('SET SESSION lock_wait_timeout=10') || !$this->connection->sql_query($ddl)) { phpbb_acl_error('Profile_definition_capacity'); }
            }
            $this->begin($tables, true); $this->pin_actor();
            $job = $this->creation_job($operation, $values);
            if (!$job || $job['job_state'] !== 'staged' || !$this->staged_column($column, $operation)) { phpbb_acl_error('Profile_definition_changed'); }
            $this->creation_definitions($values, $column);
            // An interrupted ADD creates only NULLs. Do not overwrite data
            // introduced independently into a staged, not-yet-published column.
            if (phpbb_acl_rows($this, 'SELECT user_id FROM ' . USERS_TABLE . ' WHERE `' . $column . '` IS NOT NULL LIMIT 1 FOR UPDATE')) { phpbb_acl_error('Profile_definition_changed'); }
            $defaults = array('text_field_default','text_area_default','radio_button_default','checkbox_default');
            $this->sql_query('UPDATE ' . USERS_TABLE . ' SET `' . $column . "`='" . $this->sql_escape($values[$defaults[(int)$values['field_type']]]) . "'");
            $keys = array('field_column'); $encoded = array("'" . $column . "'");
            foreach ($values as $key=>$value) { $keys[] = $key; $encoded[] = "'" . $this->sql_escape($value) . "'"; }
            $this->sql_query('INSERT INTO ' . PROFILE_FIELDS_TABLE . ' (' . implode(',', $keys) . ') VALUES (' . implode(',', $encoded) . ')');
            $id = phpbb_acl_id((string)$this->sql_nextid());
            $this->sql_query('UPDATE ' . PROFILE_FIELD_JOBS_TABLE . " SET field_id=" . $id . ",job_state='published',updated_at=" . time() . " WHERE operation_key='" . $operation . "'");
            $this->pin_actor(); $this->commit_attempted = true; $this->commit(); $this->confirmed = true;
            return $id;
        }
        finally { $this->release(); }
    }
    function edit($id, $revision, $values)
    {
        try
        {
            if ($this->definition_action !== 'edit') { phpbb_acl_error('Not_Authorised'); }
            $id = phpbb_acl_id($id); $values = phpbb_profile_definition_values($values);
            $this->begin(array(PROFILE_FIELDS_TABLE, BANLIST_TABLE), true); $this->pin_actor();
            $current = $this->current_definition($id, $revision); $column = phpbb_profile_field_column($current);
            $this->prepare_edit_capacity($id, $revision, $values, $current);
            $this->assert_capacity($column, $values);
            $assignments = array("field_column='" . $this->sql_escape($column) . "'");
            foreach ($values as $key=>$value) { $assignments[] = $key . "='" . $this->sql_escape($value) . "'"; }
            $this->sql_query('UPDATE ' . PROFILE_FIELDS_TABLE . ' SET ' . implode(',', $assignments) . ' WHERE field_id=' . $id);
            $this->pin_actor(); $this->commit_attempted = true; $this->commit(); $this->confirmed = true;
            return $id;
        }
        finally { $this->release(); }
    }
}
