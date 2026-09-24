<?php
define('IN_PHPBB', true);
define('TEXT_FIELD', 0); define('TEXTAREA', 1); define('RADIO', 2); define('CHECKBOX', 3);
define('TEXT_FIELD_MAXLENGTH', 255); define('TEXTAREA_MAXLENGTH', 60000);
$root = dirname(dirname(__DIR__));
require $root . '/phpBB2/includes/php_compat.php';
require $root . '/phpBB2/includes/functions_profile_fields.php';
function pfc_check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
function pfc_function($source, $name)
{
    $tokens = token_get_all($source); $code = ''; $active = false; $opened = false; $depth = 0;
    for ($i = 0; $i < count($tokens); $i++) {
        $token = $tokens[$i];
        if (is_array($token) && $token[0] === T_FUNCTION) {
            $j = $i + 1;
            while (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) { $j++; }
            if (is_array($tokens[$j]) && $tokens[$j][1] === $name) { $active = true; }
        }
        if (!$active) { continue; }
        $code .= is_array($token) ? $token[1] : $token;
        if ($token === '{') { $opened = true; $depth++; }
        elseif ($token === '}' && --$depth === 0 && $opened) { break; }
    }
    pfc_check($code !== '' && $depth === 0, 'Actual updater function ' . $name); eval($code);
}
$legacy = array('field_name'=>'user_notes','field_type'=>TEXT_FIELD,'text_field_maxlen'=>255);
pfc_check(phpbb_profile_field_column($legacy) === 'user_notes', 'Old metadata remains readable');
pfc_check(phpbb_profile_field_column($legacy + array('field_column'=>null)) === 'user_notes', 'Migrated NULL remains legacy');
$mapped = $legacy; $mapped['field_column'] = 'user_notes'; $mapped['field_name'] = 'Neue Bezeichnung 😀';
pfc_check(phpbb_profile_field_column($mapped) === 'user_notes', 'Label independent of column');
pfc_check(get_udata_txt(array($mapped), 'u.') === ', u.user_notes', 'SQL readers use mapping');
pfc_check(phpbb_profile_field_input($mapped, array('user_notes'=>'0')) === '0', 'Input uses stable control name');
pfc_check(strpos(phpbb_profile_field_form_control($mapped, '0'), 'name="user_notes"') !== false, 'Forms retain stable control name');
foreach (array('', 'x` = 1', 'x.y', array('nested'), false, 0, str_repeat('a',65)) as $invalid) {
    $bad = $legacy; $bad['field_column'] = $invalid;
    pfc_check(phpbb_profile_field_column($bad) === '', 'Invalid explicit mapping cannot fall back');
}
$updater = file_get_contents($root . '/update/update_from_153a.php');
foreach (array('update_quote_identifier','update_query_or_fail','update_scalar','update_table_exists','update_column_exists','update_index_exists','update_queue_column','update_queue_profile_field_columns','update_extract_create_tables') as $name) { pfc_function($updater,$name); }
pfc_check(strpos($updater, "update_queue_profile_field_columns(\$operations, \$connection, \$dbname, \$table_prefix . 'profile_fields');") !== false, 'Consolidated updater invokes mapping migration');
$pfc_schema=file_get_contents($root.'/phpBB2/install/schemas/mysql_schema.sql');$pfc_creates=update_extract_create_tables($pfc_schema);
pfc_check(isset($pfc_creates['phpbb_profile_field_jobs']),'Creation journal is canonical fresh/install-updater schema');
pfc_check(isset($pfc_creates['phpbb_profile_field_actions']),'Retirement receipts are canonical fresh/install-updater schema');
$pfc_begin=strpos($updater,'foreach ($create_statements as $generic_table => $generic_sql)');$pfc_end=strpos($updater,'update_queue_log_widths(', $pfc_begin);
pfc_check($pfc_begin!==false&&$pfc_end>$pfc_begin,'Actual missing-table migration planner');$pfc_planner=substr($updater,$pfc_begin,$pfc_end-$pfc_begin);
echo "Profile column mapping: legacy/NULL compatibility, stable names and invalid mapping rejection passed.\n";
if (getenv('PHPBB_PROFILE_COLUMN_NATIVE') !== '1') { return; }
mysqli_report(MYSQLI_REPORT_OFF);
$port = getenv('PHPBB_PROFILE_COLUMN_PORT') ?: '3306';
pfc_check(preg_match('/^[0-9]{1,5}$/D',$port) && (int)$port > 0 && (int)$port <= 65535, 'Explicit local fixture port');
$connection = mysqli_connect('127.0.0.1','root',getenv('PHPBB_PROFILE_COLUMN_PASSWORD') ?: '', '', (int)$port);
pfc_check((bool)$connection, 'Local native connection');
$database = 'codex_profile_columns_' . bin2hex(phpbb_random_bytes(8));
update_query_or_fail($connection,'CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
mysqli_select_db($connection,$database);mysqli_set_charset($connection,'utf8mb4');
function pfc_rows($connection, $sql) { $r=update_query_or_fail($connection,$sql);$rows=array();while($row=mysqli_fetch_assoc($r)){$rows[]=$row;}mysqli_free_result($r);return $rows; }
try {
    $create_statements=array('phpbb_profile_field_jobs'=>$pfc_creates['phpbb_profile_field_jobs'],'phpbb_profile_field_actions'=>$pfc_creates['phpbb_profile_field_actions']);$table_prefix='fixture_';$dbname=$database;$operations=array();eval($pfc_planner);
    pfc_check(count($operations)===2&&!update_table_exists($connection,$database,'fixture_profile_field_jobs')&&!update_table_exists($connection,$database,'fixture_profile_field_actions'),'Journal dry run makes no changes');
    foreach($operations as $operation){update_query_or_fail($connection,$operation);}
    update_query_or_fail($connection,"INSERT INTO fixture_profile_field_jobs (operation_key,actor_id,session_hash,payload_hash,field_column,job_state,created_at,updated_at) VALUES ('".str_repeat('a',64)."',2,'".str_repeat('b',64)."','".str_repeat('c',64)."','cpf_".str_repeat('a',32)."','staged',1,1)");
    $job_before=pfc_rows($connection,'SELECT * FROM fixture_profile_field_jobs');$operations=array();eval($pfc_planner);
    pfc_check(!$operations&&$job_before===pfc_rows($connection,'SELECT * FROM fixture_profile_field_jobs'),'Repeated updater preserves unfinished operations');
    update_query_or_fail($connection,"INSERT INTO fixture_profile_field_actions (operation_key,field_id,field_column,definition_revision,definition_snapshot,column_signature,actor_id,session_hash,action_state,created_at,updated_at) VALUES ('".str_repeat('d',64)."',1,'old_notes','".str_repeat('e',64)."','{\"note\":\"Grüße 😀\"}','".str_repeat('f',64)."',2,'".str_repeat('a',64)."','retired',1,1)");
    $actions_before=pfc_rows($connection,'SELECT * FROM fixture_profile_field_actions');$operations=array();eval($pfc_planner);
    pfc_check(!$operations&&$actions_before===pfc_rows($connection,'SELECT * FROM fixture_profile_field_actions'),'Repeated updater preserves retirement snapshots');
    $storage=pfc_rows($connection,"SELECT ENGINE,ROW_FORMAT,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='fixture_profile_field_actions'")[0];
    pfc_check($storage['ENGINE']==='InnoDB'&&strtolower($storage['ROW_FORMAT'])==='dynamic'&&$storage['TABLE_COLLATION']==='utf8mb4_unicode_ci','Retirement journal created with modern storage');
    $storage=pfc_rows($connection,"SELECT ENGINE,ROW_FORMAT,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='fixture_profile_field_jobs'")[0];
    pfc_check($storage['ENGINE']==='InnoDB'&&strtolower($storage['ROW_FORMAT'])==='dynamic'&&$storage['TABLE_COLLATION']==='utf8mb4_unicode_ci','Journal created with modern storage');
    $schema=file_get_contents($root.'/phpBB2/install/schemas/mysql_schema.sql');
    pfc_check(preg_match('/CREATE TABLE phpbb_profile_fields\s*\([\s\S]*?;/',$schema,$m)===1,'Canonical metadata schema');
    update_query_or_fail($connection,str_replace('phpbb_profile_fields','fixture_profile_fields',$m[0]));
    pfc_check(update_column_exists($connection,$database,'fixture_profile_fields','field_column') && update_index_exists($connection,$database,'fixture_profile_fields','field_column'),'Fresh schema has mapping and unique index');
    update_query_or_fail($connection,'ALTER TABLE fixture_profile_fields DROP INDEX field_column, DROP COLUMN field_column');
    update_query_or_fail($connection,"INSERT INTO fixture_profile_fields (field_id,field_name,field_description) VALUES (1,'user_notes','Grüße 😀'),(2,'second_field',NULL)");
    update_query_or_fail($connection,'CREATE TABLE fixture_users (user_id INT PRIMARY KEY,user_notes VARCHAR(255)) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    update_query_or_fail($connection,"INSERT INTO fixture_users VALUES (2,'Grüße &amp; 😀')");
    $before=pfc_rows($connection,'SELECT * FROM fixture_profile_fields ORDER BY field_id');$users=pfc_rows($connection,'SELECT * FROM fixture_users');
    $operations=array();update_queue_profile_field_columns($operations,$connection,$database,'fixture_profile_fields');
    pfc_check(count($operations)===2 && !update_column_exists($connection,$database,'fixture_profile_fields','field_column'),'Dry run is read-only');
    update_query_or_fail($connection,$operations[0]); // Simulated interruption between DDL steps.
    $operations=array();update_queue_profile_field_columns($operations,$connection,$database,'fixture_profile_fields');
    pfc_check(count($operations)===1,'Retry resumes index step');update_query_or_fail($connection,$operations[0]);
    $operations=array();update_queue_profile_field_columns($operations,$connection,$database,'fixture_profile_fields');pfc_check($operations===array(),'Completed migration is idempotent');
    $after=pfc_rows($connection,'SELECT * FROM fixture_profile_fields ORDER BY field_id');foreach($after as &$row){pfc_check($row['field_column']===null,'Migration does not guess physical mappings');unset($row['field_column']);}unset($row);
    pfc_check($before===$after && $users===pfc_rows($connection,'SELECT * FROM fixture_users'),'All old metadata and profile bytes preserved');
    $stale_sql='SELECT user_id' . get_udata_txt(array($legacy)) . ' FROM fixture_users';
    foreach(array('ROLLBACK','COMMIT') as $outcome){
        update_query_or_fail($connection,'START TRANSACTION');
        update_query_or_fail($connection,"UPDATE fixture_profile_fields SET field_column='user_notes',field_name='Neue Bezeichnung 😀' WHERE field_id=1");
        update_query_or_fail($connection,$outcome);
        $field=pfc_rows($connection,'SELECT * FROM fixture_profile_fields WHERE field_id=1')[0];
        pfc_check($field['field_name']===($outcome==='COMMIT'?'Neue Bezeichnung 😀':'user_notes'),'Metadata transaction outcome');
        pfc_check(phpbb_profile_field_column($field)==='user_notes','Stable mapping before/after metadata commit');
        pfc_check($users===pfc_rows($connection,$stale_sql),'Already assembled reader remains valid');
        pfc_check($users===pfc_rows($connection,'SELECT user_id'.get_udata_txt(array($field)).' FROM fixture_users'),'New reader sees same bytes');
    }
    pfc_check(mysqli_query($connection,"UPDATE fixture_profile_fields SET field_column='user_notes' WHERE field_id=2")===false,'Two explicit fields cannot own one column');
    echo "Native mapping migration: dry run, interrupted retry, idempotence, byte preservation, unique ownership and rollback/commit readers passed.\n";
} finally {
    update_query_or_fail($connection,'DROP DATABASE `' . $database . '`');mysqli_close($connection);
}
