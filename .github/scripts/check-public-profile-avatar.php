<?php
// Actual public controller/avatar code. CLI replaces only HTTP upload transport.
// Optional native mode uses an explicitly enabled, owned loopback MariaDB schema.
define('IN_PHPBB', true);
define('USER_AVATAR_NONE', 0);
define('USER_AVATAR_UPLOAD', 1);
define('USER_AVATAR_REMOTE', 2);
define('USER_AVATAR_GALLERY', 3);
define('USERS_TABLE', 'fixture_users');
define('GENERAL_ERROR', 202);
date_default_timezone_set('UTC');
$source = dirname(dirname(__DIR__)) . '/phpBB2/';
require $source . 'includes/php_compat.php';
class AvatarFixtureExit extends RuntimeException {}
function message_die() { throw new AvatarFixtureExit('Fixture write/upload failure'); }
function phpbb_ltrim($value, $chars) { return ltrim($value, $chars); }
function avatar_check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
// This existing validation helper has no application side effects.
$helpers = file_get_contents($source . 'includes/functions.php');
$start = strpos($helpers, 'function phpbb_image_dimensions_safe(');
// Extract by balanced braces, not by assuming neighboring function formatting.
$open = strpos($helpers, '{', $start); $depth = 1; $end = $open + 1;
while ($depth > 0 && $end < strlen($helpers)) { if ($helpers[$end] === '{') { $depth++; } elseif ($helpers[$end] === '}') { $depth--; } $end++; }
avatar_check($start !== false && $depth === 0, 'Image validation helper exists');
eval(substr($helpers, $start, $end - $start));
eval('namespace PublicAvatarFixture; use \\Exception; use \\Error;
 function is_uploaded_file($path) { return $path === $GLOBALS["input"] && is_file($path); }
 function move_uploaded_file($from, $to) { return rename($from, $to); }
 ' . substr(file_get_contents($source . 'includes/usercp_avatar.php'), 5));
$controller = file_get_contents($source . 'includes/usercp_register.php');
$start = strpos($controller, "\t\$avatar_sql = '';");
$end = strpos($controller, '// End add - Birthday MOD', $start);
avatar_check($start !== false && $end > $start, 'Actual avatar and subsequent birthday validation');
$prepare = substr($controller, $start, $end - $start);
$start = strpos($controller, '$public_avatar_scope->write_attempted();');
$end = strpos($controller, 'if ( !empty($passwd_sql) )', $start);
$write = substr($controller, $start, $end - $start);
avatar_check(strpos($write, 'Could not update users table') !== false, 'Actual edit write boundary');
avatar_check(substr_count($controller, '$public_avatar_scope->write_attempted();') === 2 && substr_count($controller, '$public_avatar_scope->saved();') === 0 && strpos($controller, '$profile_scope->finish();') !== false, 'Edit confirms through owning transaction; registration enlists its account write');
$registration_owner=file_get_contents($source.'includes/functions_registration_storage.php');
avatar_check(strpos($controller, '$registration_scope->finish();') !== false && strpos($registration_owner, 'if ($this->confirmed) { $this->avatar->saved(); }') !== false, 'Registration confirms avatar only after owned commit and release');
class AvatarFixtureDatabase
{
 var $native; var $failure = ''; var $references = 1; var $queries = 0;
 function __construct($native) { $this->native = $native; }
 function sql_escape($value) { return $this->native ? $this->native->sql_escape($value) : str_replace("'", "''", $value); }
 function sql_query($sql)
 {
  $this->queries++;
  if (strpos($sql, 'SELECT COUNT(*)') === 0) {
   if ($this->failure === 'read-fail') { return false; }
   if ($this->failure === 'read-throw') { throw new RuntimeException('Unreadable'); }
   if ($this->failure === 'malformed') { return new stdClass(); }
  }
  if (strpos($sql, 'UPDATE') === 0 && $this->failure === 'write-fail') { return false; }
  $r = $this->native ? $this->native->sql_query($sql) : true;
  if (strpos($sql, 'UPDATE') === 0 && $this->failure === 'lost-ack') { return false; }
  return $r;
 }
 function sql_fetchrow($r) { return $this->failure === 'malformed' ? false : ($this->native ? $this->native->sql_fetchrow($r) : array('avatar_references' => $this->references)); }
 function sql_freeresult($r) { if ($this->native && $this->failure !== 'malformed') { $this->native->sql_freeresult($r); } }
}
$native = null; $control = null; $schema = null;
$fixture = sys_get_temp_dir() . '/phpbb-public-avatar-' . bin2hex(phpbb_random_bytes(8));
avatar_check(mkdir($fixture) && mkdir($fixture . '/avatars') && mkdir($fixture . '/gallery'), 'Owned filesystem fixture');
$phpbb_root_path = $fixture . '/'; $input = $fixture . '/input.png';
$board_config = array('avatar_path'=>'avatars', 'avatar_gallery_path'=>'gallery', 'avatar_filesize'=>4096, 'avatar_max_width'=>100, 'avatar_max_height'=>100,
 'allow_avatar_upload'=>1, 'allow_avatar_remote'=>1, 'allow_avatar_local'=>1, 'birthday_required'=>0, 'min_user_age'=>0, 'max_user_age'=>150);
$lang = array('Wrong_remote_avatar_format'=>'Invalid URL', 'Wrong_birthday_format'=>'Invalid birthday', 'Avatar_filetype'=>'Invalid image');
$bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j4x8AAAAASUVORK5CYII=');
$public_avatar_scope = null; $admin_profile_scope = null; $cases = 0;
try {
 if (getenv('PHPBB_PUBLIC_AVATAR_NATIVE') === '1') {
  avatar_check(PHP_SAPI === 'cli', 'Native fixture is CLI only');
  require $source . 'db/mysqli.php';
  $port = getenv('PHPBB_PUBLIC_AVATAR_PORT') ?: '3306';
  avatar_check(preg_match('/^[0-9]{1,5}$/D', $port) && (int)$port > 0 && (int)$port <= 65535, 'Explicit loopback port');
  $password = getenv('PHPBB_PUBLIC_AVATAR_PASSWORD') ?: '';
  $control = new sql_db('127.0.0.1:' . $port, 'root', $password, '', false);
  $schema = 'codex_public_avatar_' . bin2hex(phpbb_random_bytes(8));
  avatar_check($control->sql_query('CREATE DATABASE ' . $schema . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'), 'Owned native schema');
  $native = new sql_db('127.0.0.1:' . $port, 'root', $password, $schema, false);
  avatar_check($native->sql_query('CREATE TABLE fixture_users (user_id INT PRIMARY KEY, user_avatar VARCHAR(255) NOT NULL, user_avatar_type INT NOT NULL) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'), 'Native avatar references');
 }
 foreach (array('prior-error','bad-url','bad-gallery','delete','remote','upload','late-birthday','shared','write-fail','lost-ack','read-fail','read-throw','malformed','unchanged','registration-reject','registration-save') as $scenario) {
  file_put_contents($fixture . '/avatars/before.png', $bytes); file_put_contents($input, $bytes);
  if ($native) {
   avatar_check($native->sql_query('DELETE FROM fixture_users') && $native->sql_query("INSERT INTO fixture_users VALUES (1,'before.png',1)"), 'Reset only owned references');
   if ($scenario === 'shared') { avatar_check($native->sql_query("INSERT INTO fixture_users VALUES (2,'before.png',1)"), 'Shared avatar fixture'); }
  }
  $db = new AvatarFixtureDatabase($native); $db->failure = $scenario;
  $db->references = in_array($scenario, array('shared','unchanged','bad-gallery'), true) ? 1 : 0;
  $public_avatar_scope = new PublicAvatarFixture\PhpbbPublicAvatarScope($db);
  $mode = strpos($scenario, 'registration-') === 0 ? 'register' : 'editprofile';
  $userdata = array('user_avatar'=>'before.png','user_avatar_type'=>USER_AVATAR_UPLOAD);
  $error = $scenario === 'prior-error'; $error_msg = '';
  $user_avatar_upload = $user_avatar_name = $user_avatar_local = $user_avatar_category = $user_avatar_remoteurl = '';
  $user_avatar_size = 1; $user_avatar_filetype = 'forged/mime';
  $b_day = $b_md = $b_year = 0;
  $_POST = array();
  if (in_array($scenario, array('prior-error','delete'), true)) { $_POST['avatardel'] = '1'; }
  elseif (in_array($scenario, array('bad-url','remote'), true)) { $user_avatar_remoteurl = $scenario === 'bad-url' ? 'https://#invalid' : 'https://example.invalid/avatar.png'; }
  elseif ($scenario === 'bad-gallery') { $user_avatar_local = 'absent.png'; $user_avatar_category = 'absent'; }
  elseif ($scenario !== 'unchanged') { $user_avatar_upload = $input; $user_avatar_name = 'valid.png'; }
  if (in_array($scenario, array('late-birthday','registration-reject'), true)) { $b_day = 31; $b_md = 2; $b_year = 2000; }
  // Namespace resolution calls the actual helpers with the CLI transport stub.
  eval('namespace PublicAvatarFixture; ' . $prepare);
  avatar_check(is_file($fixture . '/avatars/before.png'), $scenario . ': validation never removes old bytes');
  $new = $public_avatar_scope->new_files; $failed = false;
  if (!$error) {
   if ($mode === 'register') {
    $public_avatar_scope->write_attempted();
    if ($native) { avatar_check($native->sql_query('INSERT INTO fixture_users VALUES (3,' . $avatar_sql . ')'), 'Publish registration avatar'); }
    $public_avatar_scope->saved();
   } else {
    $sql = 'UPDATE fixture_users SET user_id=1' . $avatar_sql . ' WHERE user_id=1';
    // This fixture isolates the avatar lifecycle; full transaction confirmation
    // and rollback are exercised by check-public-profile-native.php.
    try { eval($write); $public_avatar_scope->saved(); } catch (AvatarFixtureExit $e) { $failed = true; }
   }
  }
  $public_avatar_scope->release(); $public_avatar_scope->release();
  $keep_old = !in_array($scenario, array('delete','remote','upload'), true);
  avatar_check(is_file($fixture . '/avatars/before.png') === $keep_old, $scenario . ': correct old-file outcome');
  foreach ($new as $file) { avatar_check(is_file($fixture . '/avatars/' . $file) === !$error, $scenario . ': staged bytes only discarded before write'); }
  avatar_check($failed === in_array($scenario, array('write-fail','lost-ack'), true), $scenario . ': original write outcome preserved');
  foreach (array_merge(array('before.png'), array_values($new)) as $file) { if (is_file($fixture . '/avatars/' . $file)) { unlink($fixture . '/avatars/' . $file); } }
  $cases++;
 }
 echo 'Public profile avatar lifecycle: ' . $cases . ' actual-code cases passed (' . ($native ? 'native MariaDB/MySQL references' : 'fault-injection DB fixture') . "; CLI HTTP-upload transport stub).\n";
} finally {
 if ($public_avatar_scope) { $public_avatar_scope->release(); }
 if ($native) { $native->sql_close(); }
 if ($control && $schema) { avatar_check($control->sql_query('DROP DATABASE ' . $schema), 'Remove owned native schema'); $control->sql_close(); }
 foreach (glob($fixture . '/avatars/*') as $file) { unlink($file); }
 if (is_file($input)) { unlink($input); }
 rmdir($fixture . '/avatars'); rmdir($fixture . '/gallery'); rmdir($fixture);
}
