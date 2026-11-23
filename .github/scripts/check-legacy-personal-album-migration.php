<?php
if (PHP_SAPI !== 'cli' || getenv('PHPBB_LEGACY_ALBUM_NATIVE') !== '1')
{
	echo "Legacy Album migration fixture skipped.\n";
	return;
}
$root = dirname(dirname(__DIR__));
require_once $root.'/update/album_legacy_personal_migration.php';
mysqli_report(MYSQLI_REPORT_OFF);
$port = (int) (getenv('PHPBB_LEGACY_ALBUM_PORT') ?: 3306);
$password = getenv('PHPBB_LEGACY_ALBUM_PASSWORD') ?: '';
$legacy_db = mysqli_connect('127.0.0.1', 'root', $password, '', $port);
if (!$legacy_db || !mysqli_set_charset($legacy_db, 'utf8mb4')) { throw new RuntimeException('Own legacy Album fixture unavailable'); }
$schema = 'codex_legacy_album_'.bin2hex(openssl_random_pseudo_bytes(8));
$created = false;
$checks = 0;

function lag_check($ok, $why)
{
	if (!$ok) { throw new RuntimeException($why); }
	$GLOBALS['checks']++;
}
function lag_sql($sql) { return plus_storage_query($GLOBALS['legacy_db'], $sql); }
function lag_rows($sql) { return plus_storage_rows($GLOBALS['legacy_db'], $sql); }
function lag_snapshot()
{
	return array(lag_rows('SELECT * FROM fixture_album ORDER BY pic_id'), lag_rows('SELECT * FROM fixture_album_cat ORDER BY cat_id'),
		lag_rows('SELECT * FROM fixture_album_comment ORDER BY comment_id'), lag_rows('SELECT * FROM fixture_album_rate ORDER BY rate_pic_id,rate_point'));
}
function lag_cli($schema, $port, $password, $args)
{
	global $root;
	$dir = sys_get_temp_dir().'/'.$schema;
	$file = $dir.'/config.php';
	lag_check(mkdir($dir,0700), 'Own CLI configuration directory');
	try
	{
		$values = array('dbms'=>'mysqli','dbhost'=>'127.0.0.1:'.$port,'dbuser'=>'root','dbpasswd'=>$password,
			'dbname'=>$schema,'table_prefix'=>'fixture_');
		$config = "<?php\n";
		foreach ($values as $key=>$value) { $config .= '$'.$key.'='.var_export($value,true).";\n"; }
		lag_check(file_put_contents($file,$config) !== false && chmod($file,0600), 'Own trusted CLI configuration');
		$command = escapeshellarg(PHP_BINARY);
		if (DIRECTORY_SEPARATOR === '\\')
		{
			$command .= ' -n -d '.escapeshellarg('extension_dir='.ini_get('extension_dir')).' -d extension=php_mysqli.dll -d extension=php_openssl.dll';
		}
		$command .= ' '.escapeshellarg($root.'/update/update_from_153a.php').' --album-legacy-personal-only '.escapeshellarg('--config='.$file);
		foreach ($args as $arg) { $command .= ' '.escapeshellarg($arg); }
		$job = proc_open($command, array(0=>array('pipe','r'), 1=>array('pipe','w'), 2=>array('pipe','w')), $pipes, null, null, array('bypass_shell'=>true));
		lag_check(is_resource($job), 'Own actual CLI updater starts');
		fclose($pipes[0]);
		$out = stream_get_contents($pipes[1]); fclose($pipes[1]);
		$err = stream_get_contents($pipes[2]); fclose($pipes[2]);
		return array(proc_close($job),$out,$err);
	}
	finally
	{
		if (is_file($file)) { unlink($file); }
		rmdir($dir);
	}
}

try
{
	lag_sql('CREATE DATABASE `'.$schema.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
	$created = true;
	lag_check(mysqli_select_db($legacy_db,$schema), 'Own migration schema selected');
	lag_check(!plus_legacy_album_plan($legacy_db,'fixture_')['installed'], 'Absent optional album is a no-op');
	$ddl = file_get_contents($root.'/phpBB2/install/schemas/mysql_schema.sql');
	foreach (array('album','album_cat','album_comment','album_rate','album_config','users','user_group') as $name)
	{
		lag_check(preg_match('/^CREATE TABLE phpbb_'.$name.'\s*\([\s\S]*?;/m', $ddl, $match) === 1, 'Exact canonical definition');
		lag_sql(str_replace('phpbb_'.$name,'fixture_'.$name,$match[0]));
	}
	lag_sql("SET SESSION sql_mode='STRICT_ALL_TABLES,ONLY_FULL_GROUP_BY,NO_BACKSLASH_ESCAPES'");
	lag_sql("INSERT INTO fixture_album_config VALUES ('personal_gallery_view','3'),('personal_gallery_private','9, 12')");
	foreach (array(7,8,9,10,11,12) as $id)
	{
		lag_sql("INSERT INTO fixture_users(user_id,username,user_password,user_email,user_active) VALUES(".$id.",'Account ".$id."','','',".($id === 9 ? 0 : 1).')');
	}
	lag_sql("INSERT INTO fixture_album_cat(cat_id,cat_title,cat_order,cat_view_level,cat_view_groups,cat_parent,cat_user_id) VALUES
		(1,'Other existing album',1,-1,'',0,0),(20,'Compatible existing root',1,3,'12,9',0,8),
		(30,'Moderator existing root',1,2,'',0,10),(31,'First conflicting root',1,3,'9,12',0,11),
		(32,'Second conflicting root',1,3,'9,12',0,11),(33,'Broken hierarchy',1,3,'9,12',57,12)");
	$title = 'CONVERT(0x'.bin2hex("Größe 😀 ' \\").' USING utf8mb4)';
	$owners = array(101=>7,102=>7,103=>8,104=>-1,105=>99,106=>9,107=>10,108=>11,109=>12);
	foreach ($owners as $id=>$owner)
	{
		lag_sql("INSERT INTO fixture_album(pic_id,pic_filename,pic_title,pic_desc,pic_user_id,pic_time,pic_cat_id,pic_approval)
			VALUES(".$id.",'fixture-".$id.".jpg',".$title.",".$title.",".$owner.",100,0,".($id === 102 ? 0 : 1).')');
	}
	lag_sql("INSERT INTO fixture_album(pic_id,pic_filename,pic_title,pic_user_id,pic_time,pic_cat_id) VALUES(110,'modern.jpg','Modern picture',8,100,20)");
	foreach (array(101=>0,102=>77,103=>1,104=>0,110=>20,111=>0) as $id=>$category)
	{
		lag_sql("INSERT INTO fixture_album_comment(comment_pic_id,comment_cat_id,comment_user_id,comment_user_ip,comment_time,comment_text)
			VALUES(".$id.",".$category.",-1,'00000000',100,".$title.')');
	}
	lag_sql("INSERT INTO fixture_album_rate(rate_pic_id,rate_user_id,rate_user_ip,rate_point) VALUES(101,8,'00000000',7)");
	$before = lag_snapshot();
	$plan = plus_legacy_album_plan($legacy_db,'fixture_');
	lag_check($plan['pictures'] === 3 && $plan['policy'] === array('view'=>3,'groups'=>'9,12') &&
		$plan['owners'][7]['root'] === 0 && $plan['owners'][8]['root'] === 20 &&
		$plan['skipped'] === array('guest_or_unassigned_owner'=>1,'missing_or_inactive_account'=>2,
			'different_root_visibility'=>1,'ambiguous_hierarchy'=>2) && lag_snapshot() === $before, 'Preview preserves all rows and classifies each owner');

	if (stripos(mysqli_get_server_info($legacy_db), 'MariaDB') !== false)
	{
		lag_sql('SET SESSION check_constraint_checks=ON');
	}
	lag_sql("ALTER TABLE fixture_album ADD CONSTRAINT refuse_late_gallery CHECK(pic_filename<>'fixture-103.jpg' OR pic_cat_id=0)");
	lag_sql('START TRANSACTION');
	$probe = '';
	try { lag_sql('UPDATE fixture_album SET pic_cat_id=20 WHERE pic_id=103'); } catch (Exception $error) { $probe = $error->getMessage(); }
	finally { lag_sql('ROLLBACK'); }
	lag_check(strpos($probe, 'refuse_late_gallery') !== false, 'Fixture SQL constraint on '.mysqli_get_server_info($legacy_db).': '.$probe);
	$failure = '';
	$applied = null;
	try { $applied = plus_legacy_album_apply($legacy_db,'fixture_',true,true); } catch (Exception $error) { $failure = $error->getMessage(); }
	lag_check(strpos($failure, 'refuse_late_gallery') !== false, 'Expected later-gallery constraint failure, got: '.$failure.' '.json_encode($applied));
	lag_check(lag_snapshot() === $before, 'Failure in a later gallery rolls back all prior photo, category and comment changes');
	lag_sql('ALTER TABLE fixture_album DROP CONSTRAINT refuse_late_gallery');
	$lease = 'album_files_'.sha1($schema."\0fixture_");
	lag_sql("SELECT GET_LOCK('".$lease."',0)");
	try
	{
		$failed = false;
		try { plus_legacy_album_apply($legacy_db,'fixture_',true,true); } catch (Exception $error) { $failed = true; }
		lag_check($failed && lag_snapshot() === $before &&
			lag_rows("SELECT IS_USED_LOCK('".$lease."')=CONNECTION_ID() AS ours")[0]['ours'] === '1',
			'Migration refuses to take over the caller writer lock');
	}
	finally { lag_sql("DO RELEASE_LOCK('".$lease."')"); }

	$result = lag_cli($schema,$port,$password,array());
	lag_check($result[0] === 0 && strpos($result[1],'3 (2 galleries)') !== false && lag_snapshot() === $before, 'Actual CLI previews planned rows: '.$result[2]);
	foreach (array(array('--apply'), array('--apply','--backup-confirmed'), array('--storage-only')) as $args)
	{
		$result = lag_cli($schema,$port,$password,$args);
		lag_check($result[0] === 2 && lag_snapshot() === $before, 'Actual CLI guards maintenance and conflicting options');
	}
	$flags = array('--apply','--backup-confirmed','--maintenance-confirmed');
	$result = lag_cli($schema,$port,$password,$flags);
	lag_check($result[0] === 0 && strpos($result[1],'3 pictures, 3 comment category snapshots') !== false, 'Actual updater moves exact collection: '.$result[2]);
	$after = lag_snapshot();
	$first = (int) $after[0][0]['pic_cat_id'];
	$second = (int) $after[0][2]['pic_cat_id'];
	lag_check($first > 0 && $second > 0 && $first !== $second && count($after[1]) === count($before[1])+3, 'One new root and two dedicated subcategories');
	$expected = $before;
	$expected[0][0]['pic_cat_id'] = $expected[0][1]['pic_cat_id'] = (string) $first;
	$expected[0][2]['pic_cat_id'] = (string) $second;
	$expected[2][0]['comment_cat_id'] = $expected[2][1]['comment_cat_id'] = (string) $first;
	$expected[2][2]['comment_cat_id'] = (string) $second;
	lag_check($after[0] === $expected[0] && $after[2] === $expected[2] && $after[3] === $expected[3],
		'All bytes besides the planned category fields remain exact, including other albums, approval and orphans');
	$result = lag_cli($schema,$port,$password,$flags);
	lag_check($result[0] === 0 && strpos($result[1],'0 pictures, 0 comment category snapshots') !== false && lag_snapshot() === $after, 'Actual CLI retry is idempotent');

	if (!defined('IN_PHPBB')) { define('IN_PHPBB',true); }
	$phpbb_root_path = $root.'/phpBB2/';
	$album_root_path = $phpbb_root_path.'album_mod/';
	$phpEx = 'php';
	$table_prefix = 'fixture_';
	require_once $phpbb_root_path.'includes/php_compat.php';
	require_once $phpbb_root_path.'includes/constants.php';
	require_once $phpbb_root_path.'includes/functions.php';
	require_once $phpbb_root_path.'db/mysqli.php';
	require_once $album_root_path.'album_functions.php';
	require_once $album_root_path.'album_hierarchy_functions.php';
	if (!function_exists('album_sql_id_list'))
	{
		function album_sql_id_list($value, $maximum = 500) { return phpbb_sql_id_list($value,$maximum); }
	}
	$db = new sql_db('127.0.0.1:'.$port,'root',$password,$schema,false);
	try
	{
		$category = lag_rows('SELECT * FROM fixture_album_cat WHERE cat_id='.$first)[0];
		$data = lag_rows(album_picture_details_sql(101));
		lag_check(count($data) === 1 && (int) $data[0]['cat_id'] === $first, 'Actual detail reader resolves the original picture ID after conversion');
		lag_sql('INSERT INTO fixture_user_group(group_id,user_id,user_pending) VALUES(9,8,0),(12,10,1)');
		$album_config = array('rate'=>1,'comment'=>1,'personal_gallery'=>0,'personal_gallery_view'=>3,'personal_gallery_private'=>'9,12',
			'personal_allow_gallery_mod'=>1,'album_debug_mode'=>0);
		foreach (array(array(-1,USER,0),array(7,USER,0),array(8,USER,1),array(10,USER,0),array(11,ADMIN,1)) as $actor)
		{
			$userdata = array('user_id'=>$actor[0],'session_logged_in'=>$actor[0]>0,'user_level'=>$actor[1]);
			$old = personal_gallery_access(1,0);
			$new = album_permissions(7,$first,ALBUM_AUTH_ALL,$category);
			lag_check((int) $old['view'] === $actor[2] && (int) $new['view'] === $actor[2] &&
				($actor[1] === ADMIN || (!$new['upload'] && !$new['moderator'])), 'Actual before and after ACL agrees for each actor');
		}

		foreach (array(13=>ALBUM_GUEST,14=>ALBUM_USER) as $owner => $view)
		{
			lag_sql("UPDATE fixture_album_config SET config_value='".$view."' WHERE config_name='personal_gallery_view'");
			lag_sql("INSERT INTO fixture_users(user_id,username,user_password,user_email,user_active) VALUES(".$owner.",'Owner','','',1)");
			lag_sql("INSERT INTO fixture_album(pic_id,pic_filename,pic_title,pic_user_id,pic_time,pic_cat_id)
				VALUES(".$owner.",'synthetic.jpg','Old picture',".$owner.",100,0)");
			$form = plus_legacy_album_apply($legacy_db,'fixture_',true,true);
			lag_check($form['pictures'] === 1 && $form['policy']['view'] === $view, 'Other legacy policies migrate one matching gallery');
			$album_config['personal_gallery_view'] = $view;
			$category = lag_rows(album_picture_details_sql($owner))[0];
			foreach (array(-1, 7) as $id)
			{
				$userdata = array('user_id'=>$id,'session_logged_in'=>$id>0,'user_level'=>USER);
				$old = personal_gallery_access(1,0);
				$new = album_permissions($owner,$category['cat_id'],ALBUM_AUTH_ALL,$category);
				lag_check($old['view'] === $new['view'] && $new['view'] === (($id > 0 || $view === ALBUM_GUEST) ? 1 : 0),
					'Guest and member legacy audiences stay the same');
			}
		}
	}
	finally { $db->sql_close(); }
	echo 'Native legacy personal Album migration: '.$checks." checks PASS; real CLI, access, atomic rollback, retained rows and data.\n";
}
finally
{
	if ($created && preg_match('/^codex_legacy_album_[a-f0-9]{16}$/D', $schema))
	{
		mysqli_query($legacy_db, 'DROP DATABASE `'.$schema.'`');
	}
	mysqli_close($legacy_db);
}
