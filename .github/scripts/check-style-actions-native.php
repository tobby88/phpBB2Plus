<?php
if (PHP_SAPI !== 'cli' || getenv('PHPBB_STYLE_ACTIONS_NATIVE') !== '1') { echo "Style action checks require an explicitly enabled disposable database.\n"; return; }
putenv('PHPBB_STYLE_DATA_NATIVE=1'); putenv('PHPBB_STYLE_DATA_PORT=' . (getenv('PHPBB_STYLE_ACTIONS_PORT') ?: '3306'));
putenv('PHPBB_STYLE_DATA_PASSWORD=' . (getenv('PHPBB_STYLE_ACTIONS_PASSWORD') ?: ''));
define('CONFIG_TABLE', 'fixture_config'); define('XS_MODS_ADMIN_TEMPLATES', true); define('XS_TPL_PATH', '');
// Reuse only the owned database/bootstrap fixture. Execute the actual style
// actions controller; neither its SQL nor the writer is extracted or replaced.
$sa_fixture = file_get_contents(__DIR__ . '/check-style-data-native.php');
$sa_cut = strpos($sa_fixture, " if (getenv('PHPBB_STYLE_DATA_PROBE')");
if ($sa_cut === false) { throw new RuntimeException('Native style fixture boundary missing'); }
$sa_head = str_replace('__DIR__', var_export(__DIR__, true), substr($sa_fixture, 5, $sa_cut - 5));
$sa_head = str_replace('codex_style_data_', 'codex_style_actions_', $sa_head);
$sa_body = <<<'PHP'
 class StyleActionTemplate extends StyleDataTemplate {
  function assign_vars($data) {} function set_filenames($data) {} function pparse($name) { throw new StyleDataExit('rendered'); }
 }
 $template = new StyleActionTemplate(); $xs_row_class = array('row1', 'row2');
 sd_check(preg_match('/^\s*user_style\s+([^,]+),/m', $schema, $style_definition) === 1, 'Canonical user style column');
 sd_sql('ALTER TABLE fixture_users ADD user_style ' . $style_definition[1]);
 sd_check(preg_match('/CREATE TABLE phpbb_config\s*\([\s\S]*?;/', $schema, $m) === 1, 'Canonical configuration schema');
 sd_sql(str_replace('phpbb_config', CONFIG_TABLE, $m[0]));
 $sa_helper = $sd_root . '/includes/functions_style_actions.php';
 file_put_contents($sa_helper, '<?php require_once ' . var_export($sd_source . 'includes/functions_style_actions.php', true) . ';'); $sd_files[] = $sa_helper;
 function sa_snapshot() { $out = sd_snapshot(); $out[] = phpbb_acl_rows($GLOBALS['peer'], 'SELECT * FROM fixture_config ORDER BY config_name'); $out[] = phpbb_acl_rows($GLOBALS['peer'], 'SELECT user_id,user_style FROM fixture_users ORDER BY user_id'); return $out; }
 function sa_reset($actor = 'root') {
  global $userdata, $board_config, $sd_hook, $sd_failure, $sd_queries, $sd_after_commit;
  $sd_hook = null; $sd_failure = ''; $sd_queries = array(); $sd_after_commit = null;
  foreach (array(THEMES_TABLE, THEMES_NAME_TABLE, CONFIG_TABLE, USERS_TABLE, SESSIONS_TABLE, JR_ADMIN_TABLE) as $table) { sd_sql('DELETE FROM ' . $table); }
  sd_sql("INSERT INTO fixture_themes (themes_id,template_name,style_name,theme_public) VALUES (1,'fisubsilversh','First',1),(2,'fisubsilversh','Second',0),(3,'fisubsilversh','Third',1)");
  sd_sql("INSERT INTO fixture_config VALUES ('default_style','1'),('override_user_style','0'),('version','preserve')");
  sd_sql('INSERT INTO fixture_users VALUES (1,' . ($actor === 'root' ? 1 : 0) . ',1,1),(2,0,1,2),(3,0,1,1),(4,0,1,NULL),(-1,0,0,1)');
  sd_sql("INSERT INTO fixture_sessions VALUES ('fixture-admin',1,1,1)");
  if ($actor === 'delegated') { $hash = array_search('xs_frameset.php', jr_admin_authorization_routes(), true); sd_check($hash !== false, 'Registered XS grant'); sd_sql("INSERT INTO fixture_jr VALUES (1,'" . $hash . "')"); }
  $userdata = array('user_id' => 1, 'session_id' => 'fixture-admin', 'session_admin' => 1, 'session_logged_in' => 1, 'user_level' => $actor === 'root' ? 1 : 0);
  $board_config = array('default_style' => 1, 'override_user_style' => 0); $_SERVER['REQUEST_METHOD'] = 'POST';
  foreach (array('config_data.cache', 'themes.cache') as $file) { file_put_contents($GLOBALS['sd_root'] . '/cache/' . $file, 'old cache'); }
 }
 function sa_run($action, $request = array()) {
  global $db, $phpbb_root_path, $phpEx, $lang, $template, $userdata, $HTTP_POST_VARS, $HTTP_GET_VARS, $board_config, $xs_row_class;
  $_POST = $HTTP_POST_VARS = array_merge(array('sid' => 'fixture-admin', 'style_action' => $action), $request); $HTTP_GET_VARS = array();
  try { include $GLOBALS['sd_source'] . 'admin/xs_styles.php'; throw new RuntimeException('Controller did not terminate'); }
  catch (StyleDataExit $e) { return $e->getMessage(); }
 }
 if (getenv('PHPBB_STYLE_ACTIONS_PROBE') === '1') {
  sa_reset(); $sd_failure = 'UPDATE fixture_config '; $out = sa_run('default:2');
  $rows = phpbb_acl_rows($peer, "SELECT config_value FROM fixture_config WHERE config_name='default_style'");
  sd_check($out === 'rendered' && $board_config['default_style'] === 2 && $rows[0]['config_value'] === '1', 'Actual controller hides failed default write');
  echo "Reproduced: failed board default write is ignored while request-local state changes and cache remains stale.\n";
 } else {
  $cases = 0; $serialized = 0;
  function sa_boundary($sql) { return preg_match('/^UPDATE fixture_(themes|config|users) /', $sql) || strpos($sql, ' LOCK IN SHARE MODE') !== false || $sql === 'COMMIT'; }
  $actions = array('default:2' => array(), 'override:1' => array(), 'moveusers:3' => array(), 'moveaway:1' => array('movestyle' => '3'),
   'moveaway:01' => array('movestyle' => '0'), 'admin:2:1' => array(), 'admin:3:0' => array());
  foreach (array('root','delegated') as $actor) { foreach ($actions as $action => $request) {
   sa_reset($actor); $before = sa_snapshot(); sd_check(sa_run($action, $request) === 'rendered', 'Actual action succeeds ' . $action); $expected = sa_snapshot();
   sd_check($before[1] === $expected[1] && $expected[2][2]['config_value'] === 'preserve' && $expected[3][0] === $before[3][0], 'Preserve labels, unrelated config and anonymous user');
   sd_check($board_config['default_style'] === ($action === 'default:2' ? '2' : '1') && $board_config['override_user_style'] === ($action === 'override:1' ? '1' : '0'), 'Request-local state follows confirmed values');
   if ($action === 'default:2') { sd_check($expected[0][1]['theme_public'] === '1', 'Default selection also makes target public'); }
   if ($action === 'moveusers:3') { foreach (array_slice($expected[3], 1) as $user) { sd_check($user['user_style'] === '3', 'All registered users moved'); } }
   if (strpos($action, 'moveaway:') === 0) { sd_check($expected[3][1]['user_style'] === ($request['movestyle'] === '0' ? null : '3') && $expected[3][2]['user_style'] === '2' && $expected[3][4]['user_style'] === null, 'Only selected users moved, including default/null destination'); }
   foreach (array('config_data.cache','themes.cache') as $cache) { sd_check(!is_file($sd_root . '/cache/' . $cache), 'Relevant caches evicted'); } sd_unlocked(); $cases++;
   $boundaries = array_values(array_filter($sd_queries, 'sa_boundary'));
   foreach (array('missing','inactive','logout','admin-off','foreign','case',$actor === 'root' ? 'role' : 'grant') as $kind) {
    sa_reset($actor); sd_sql(sd_revoke($kind)); $before = sa_snapshot(); sd_check(sa_run($action, $request) === 'error' && sa_snapshot() === $before, 'Entry revocation denies ' . $action); sd_unlocked(); $cases++;
   }
   foreach (array('missing',$actor === 'root' ? 'role' : 'grant') as $kind) { for ($boundary = 1; $boundary <= count($boundaries); $boundary++) {
    sa_reset($actor); $before = sa_snapshot(); $seen = 0; $reached = false; $blocked = false;
    $sd_hook = function($sql) use ($boundary, $kind, &$seen, &$reached, &$blocked) {
     if (!sa_boundary($sql) || ++$seen !== $boundary) { return; } $GLOBALS['sd_hook'] = null; $reached = true;
     if (!$GLOBALS['peer']->sql_query(sd_revoke($kind))) { $error = $GLOBALS['peer']->sql_error(); sd_check((int)$error['code'] === 1205, 'Only real lock timeout serializes'); $blocked = true; }
    };
    $out = sa_run($action, $request); sd_check($reached, 'Every action boundary exercised');
    if ($blocked) { sd_check($out === 'rendered' && sa_snapshot() === $expected, 'Save precedes serialized revocation'); sd_sql(sd_revoke($kind)); $serialized++; }
    else { sd_check($out === 'error' && sa_snapshot() === $before, 'Revoked action rolls back completely: ' . $actor . '/' . $action . '/' . $kind . '/' . $boundary); }
    sd_check(sa_run($action, $request) === 'error', 'Later action denied'); sd_unlocked(); $cases++;
   } }
   foreach (array('COMMIT','lost-ack') as $failure) {
    sa_reset($actor); $before = sa_snapshot(); $local = $board_config; $sd_failure = $failure;
    sd_check(sa_run($action, $request) === 'error' && sa_snapshot() === ($failure === 'lost-ack' ? $expected : $before), 'Unconfirmed commit: ' . $action . '/' . $failure);
    sd_check($board_config === $local, 'No speculative local changes'); sd_unlocked(); $cases++;
    if ($failure === 'lost-ack') { $sd_failure = ''; sd_check(sa_run($action, $request) === 'rendered' && sa_snapshot() === $expected, 'Idempotent retry after lost acknowledgement'); sd_unlocked(); $cases++; }
   }
   echo $actor . '/' . $action . " native action boundaries passed\n";
  } }
  foreach (array(array('default:1 OR 1=1',array()),array('default:0',array()),array('default:16777216',array()),array('default:2:1',array()),array('admin:2',array()),
   array('override:2',array()),array('moveaway:1',array('movestyle'=>array('2'))),array('moveaway:1',array('movestyle'=>'2x')),array(array('default:2'),array()),array('default:2',array('sid'=>array('bad'))),
   array('admin:1:0',array()),array('moveusers:2',array()),array('moveaway:1',array('movestyle'=>'2')),array('default:99',array())) as $bad) {
   sa_reset(); $before = sa_snapshot(); sd_check(sa_run($bad[0], $bad[1]) !== 'rendered' && sa_snapshot() === $before, 'Invalid action/default/private destination rejected'); sd_unlocked(); $cases++;
  }
  foreach (array("UPDATE fixture_config SET config_value='2'", 'UPDATE fixture_themes SET theme_public=1', 'SELECT GET_LOCK', 'SELECT ENGINE, ROW_FORMAT') as $failure) {
   sa_reset(); $before = sa_snapshot(); $local = $board_config; $sd_failure = $failure;
   sd_check(sa_run('default:2') === 'error' && sa_snapshot() === $before && $board_config === $local, 'Coupled default update failure is atomic'); sd_unlocked(); $cases++;
  }
  foreach (array('default:2','moveusers:3','moveaway:1') as $action) {
   sa_reset(); $before = sa_snapshot();
   $sd_hook = function($sql, $connection) { if (strpos($sql, 'UPDATE fixture_') === 0) { $GLOBALS['sd_hook'] = null; sd_sql('KILL CONNECTION ' . mysqli_thread_id($connection->db_connect_id)); } };
   sd_check(sa_run($action, array('movestyle'=>'0')) === 'error' && sa_snapshot() === $before, 'Owner loss cannot continue publishing'); sd_unlocked(); $cases++;
  }
  // Chunked user changes remain atomic, and identities created later are not
  // accidentally included in the selected batch.
  foreach (array(false, true) as $fail_second) {
   sa_reset(); for ($i=5;$i<=410;$i++) { sd_sql('INSERT INTO fixture_users VALUES (' . $i . ',0,1,1)'); }
   $before = sa_snapshot(); $seen=0;
   $sd_hook=function($sql)use($fail_second,&$seen){if(strpos($sql,'UPDATE fixture_users ')!==0){return;}$seen++;
    if($seen===1){sd_sql('INSERT INTO fixture_users VALUES (900,0,1,1)');}
    if($seen===2&&$fail_second){$GLOBALS['sd_failure']='UPDATE fixture_users ';}
   };
   $out=sa_run('moveusers:3'); $after=sa_snapshot(); $later=array_pop($after[3]);
   sd_check($later['user_id']==='900'&&$later['user_style']==='1','Later registration preserved outside selection');
   if($fail_second){sd_check($out==='error'&&$after===$before,'Second user chunk failure rolls back earlier chunk');}
   else{sd_check($out==='rendered'&&$seen===3,'All user chunks committed');foreach(array_slice($after[3],1) as $user){sd_check($user['user_style']==='3','Every selected user updated');}}
   sd_unlocked();$cases++;
  }
  foreach(array(THEMES_TABLE,CONFIG_TABLE,USERS_TABLE,SESSIONS_TABLE,JR_ADMIN_TABLE) as $table){foreach(array('ENGINE=MyISAM','ROW_FORMAT=COMPACT','CONVERT TO CHARACTER SET latin1') as $ddl){
   sa_reset();sd_sql('ALTER TABLE '.$table.' '.$ddl);$before=sa_snapshot();sd_check(sa_run('default:2')==='error'&&sa_snapshot()===$before,'Refuse non-migrated storage');
   sd_sql('ALTER TABLE '.$table.' ENGINE=InnoDB ROW_FORMAT=DYNAMIC, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');sd_unlocked();$cases++;
  }}
  foreach(array('missing','alias') as $bad){sa_reset();sd_sql($bad==='missing'?"DELETE FROM fixture_config WHERE config_name='default_style'":"UPDATE fixture_config SET config_name='Default_style' WHERE config_name='default_style'");$before=sa_snapshot();sd_check(sa_run('default:2')==='error'&&sa_snapshot()===$before,'Missing/aliased defaults require explicit repair');sd_unlocked();$cases++;}
  foreach(array('config_data.cache','themes.cache') as $blocked){
   sa_reset();unlink($sd_root.'/cache/'.$blocked);mkdir($sd_root.'/cache/'.$blocked);
   try{sd_check(sa_run('moveaway:1',array('movestyle'=>'0'))==='error','Cache failure is an incomplete outcome');sd_unlocked();$other=$blocked==='themes.cache'?'config_data.cache':'themes.cache';sd_check(!is_file($sd_root.'/cache/'.$other),'Attempt both cache cleanups');}
   finally{rmdir($sd_root.'/cache/'.$blocked);}
   file_put_contents($sd_root.'/cache/'.$blocked,'stale retry cache');sd_check(sa_run('moveaway:1',array('movestyle'=>'0'))==='rendered'&&!is_file($sd_root.'/cache/'.$blocked),'Successful no-op retry clears leftover cache');sd_unlocked();$cases++;
  }
  foreach(array('missing','role','disconnect') as $change){
   sa_reset();$sd_after_commit=function($owner)use($change){if($change==='disconnect'){sd_sql('KILL CONNECTION '.mysqli_thread_id($owner->db_connect_id));}else{sd_sql(sd_revoke($change));}
    $GLOBALS['sd_hook']=function($sql,$connection)use($owner){sd_check($connection!==$owner,'No writer SQL after acknowledged COMMIT');};};
   sd_check(sa_run('default:2')==='rendered','Confirmed action survives later revocation/disconnect');sd_unlocked();$cases++;
  }
  foreach(array(128,65536,16777215) as $high_id){
   sa_reset();sd_sql('UPDATE fixture_themes SET themes_id='.$high_id.' WHERE themes_id=3');
   sd_check(sa_run('moveusers:'.$high_id)==='rendered','Actual assignment accepts full theme ID capacity');
   foreach(array_slice(sa_snapshot()[3],1) as $user){sd_check($user['user_style']===(string)$high_id,'High ID persists without truncation');}
   sd_unlocked();$cases++;
  }
  echo 'Style action native checks: '.$cases.' cases; '.$serialized." revocations serialized after save\n";
 }
} finally {
 $sd_hook = null; $sd_failure = ''; $sd_after_commit = null; $db->sql_close(); $peer->sql_close(); $control->sql_query('DROP DATABASE ' . $fixture); $control->sql_close(); chdir($sd_previous);
 foreach (array('themes.cache','config_data.cache') as $file) { if (is_file($sd_root . '/cache/' . $file)) { unlink($sd_root . '/cache/' . $file); } }
 foreach (array_reverse($sd_files) as $file) { unlink($file); } foreach (array_reverse($sd_dirs) as $dir) { rmdir($dir); }
 restore_error_handler();
}
PHP;
eval($sa_head . $sa_body);
