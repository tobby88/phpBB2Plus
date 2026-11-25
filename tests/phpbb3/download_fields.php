<?php
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 ||
	!preg_match('/^plus3_test_[a-f0-9]{24}$/D',$argv[1])) { exit(2); }
define('IN_PHPBB',true);
$phpbb_root_path = './';
$phpEx = 'php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
$p = $table_prefix . 'plus_dl_';
$rows = static function ($sql) use ($db) {
	$result = $db->sql_query($sql);
	$rows = $db->sql_fetchrowset($result);
	$db->sql_freeresult($result);
	return $rows;
};
$insert = static function ($table,$data) use ($db,$p) {
	$db->sql_query("INSERT INTO $p$table " . $db->sql_build_array('INSERT',$data));
};
switch ($argv[2])
{
	case 'installed':
	case 'uninstalled':
		$on = $argv[2] === 'installed';
		$perm = $rows('SELECT auth_option FROM ' . ACL_OPTIONS_TABLE . " WHERE auth_option='a_plus_dl_fields'");
		$module = $rows('SELECT module_id FROM ' . MODULES_TABLE . " WHERE module_class='acp' AND module_mode='manage' AND module_langname='ACP_PLUS_DL_FIELDS'");
		if (count($perm)!==(int) $on || count($module)!==(int) $on ||
			$phpbb_container->get('dbal.tools')->sql_index_exists($p . 'field_values','dl_field_use')!==$on)
		{ throw new RuntimeException('The field migration left its module, permission or assignment index in the wrong state'); }
		break;
	case 'admin':
		foreach (array('de'=>array('Download-Zusatzfelder verwalten','Mehrfachauswahlliste','Änderungen speichern'),
			'en'=>array('Manage custom download fields','Multiple selection list','Save changes')) as $locale=>$expected)
		{
			$translations = new \phpbb\language\language($phpbb_container->get('language.loader'));
			$translations->set_user_language($locale);
			$translations->add_lang('info_acp_downloads','phpbb2plus/downloads');
			$translations->add_lang('downloads','phpbb2plus/downloads');
			if (array($translations->lang('ACP_PLUS_DL_FIELDS'),$translations->lang('ACP_PLUS_DL_FIELD_TYPE_4'),$translations->lang('PLUS_DL_SAVE_FILE'))!==$expected)
			{ throw new RuntimeException('A native language pack did not load the custom field labels'); }
		}
		foreach (array(9810=>82,9811=>86) as $id=>$from)
		{
			$definition = $rows("SELECT * FROM {$p}fields WHERE field_id=$from")[0];
			$insert('fields',array('field_id'=>$id,'title'=>'Zusatzfeld ' . $id)+$definition);
		}
		foreach (array(9840=>53,9841=>51,9842=>52) as $id=>$from)
		{
			$file = $rows("SELECT * FROM {$p}files WHERE file_id=$from")[0];
			$insert('files',array('file_id'=>$id,'review_revision'=>7)+$file);
			foreach (array(9810=>82,9811=>86,83=>83) as $field=>$source)
			{
				$value = $rows("SELECT * FROM {$p}field_values WHERE file_id=53 AND field_id=$source")[0];
				unset($value['row_id']);
				$insert('field_values',array('file_id'=>$id,'field_id'=>$field)+$value);
			}
		}
		require_once './includes/acp/auth.php';
		require_once './includes/functions_module.php';
		$subject = $rows('SELECT * FROM ' . USERS_TABLE . " WHERE username='DepartingFixture'")[0];
		$actor = (int) $subject['user_id'];
		$db->sql_transaction('begin');
		try
		{
			(new auth_admin())->acl_set('user',0,$actor,array('a_plus_dl_fields'=>ACL_YES));
			$user->data = $rows('SELECT * FROM ' . USERS_TABLE . " WHERE user_id=$actor")[0] + array('session_admin'=>1,'is_registered'=>true);
			$auth->acl($user->data);
			define('IN_ADMIN',true);
			$access = $phpbb_container->get('phpbb2plus.downloads.access');
			$expr = (new \phpbb2plus\downloads\acp\fields_info())->module()['modes']['manage']['auth'];
			if (!$access->field_admin() || !p_master::module_auth($expr,0) || $access->admin() || $access->permissions_admin() || $access->license_admin())
			{ throw new RuntimeException('Custom field delegation added unrelated administration rights'); }
		}
		finally { $db->sql_transaction('rollback'); $auth->acl_clear_prefetch($actor); }
		break;
	case 'edited':
		$def = $rows("SELECT * FROM {$p}fields WHERE field_id=9810")[0];
		$meta = json_decode($def['source_json'],true);
		$choice = $rows("SELECT * FROM {$p}fields WHERE field_id=9811")[0];
		$old = $rows("SELECT * FROM {$p}fields WHERE field_id=86")[0];
		$values = $rows("SELECT values_json,source_json FROM {$p}field_values WHERE file_id=9840 AND field_id=9811")[0];
		$original = $rows("SELECT values_json,source_json FROM {$p}field_values WHERE file_id=53 AND field_id=86")[0];
		if ($meta['regex'] !== '^(?:PA-[0-9]+)?$' || $meta['plus_field_rule'] !== '^(?:NEU-[0-9]+)?$' ||
			(int) $choice['position']!==0 || (int) $choice['field_type']!==4 || $choice['source_json'] !== $old['source_json'] || $values !== $original)
		{ throw new RuntimeException('Definition changes rewrote an archival field or a stored file value'); }
		break;
	case 'late':
		$value = $rows("SELECT * FROM {$p}field_values WHERE file_id=9840 AND field_id=9811")[0];
		unset($value['row_id']);
		$file = $rows("SELECT * FROM {$p}files WHERE file_id=9842")[0];
		$insert('files',array('file_id'=>9843)+$file);
		$insert('field_values',array('file_id'=>9843)+$value);
		break;
	case 'removed':
		$left = $rows("SELECT field_id FROM {$p}fields WHERE field_id IN (9810,9811) OR title='Neue Ausstattung'");
		$values = $rows("SELECT field_id FROM {$p}field_values WHERE field_id IN (9810,9811)");
		$logs = $rows('SELECT DISTINCT log_operation FROM ' . LOG_TABLE . " WHERE log_operation IN ('LOG_PLUS_DL_FIELD_CREATE','LOG_PLUS_DL_FIELD_EDIT','LOG_PLUS_DL_FIELD_DELETE')");
		if ($left || $values || count($logs)!==3) { throw new RuntimeException('Field removal left selected definitions or entries, or lost the admin log'); }
		foreach (array(9840=>53,9841=>51,9842=>52,9843=>52) as $id=>$source)
		{
			$columns = 'cat_id,approved,storage_name,owner_id,license_id,asset_status,source_json';
			$file = $rows("SELECT $columns,review_revision FROM {$p}files WHERE file_id=$id")[0];
			$revision = (int) $file['review_revision'];
			unset($file['review_revision']);
			$old = $rows("SELECT $columns FROM {$p}files WHERE file_id=$source")[0];
			$other = $rows("SELECT values_json FROM {$p}field_values WHERE file_id=$id AND field_id=83");
			if ($file !== $old || $revision!==8 || count($other)!== (int) ($id!==9843))
			{ throw new RuntimeException('Removing fields affected file contents, permissions, or unrelated entries'); }
		}
		$db->sql_query("DELETE FROM {$p}field_values WHERE file_id BETWEEN 9840 AND 9843");
		$db->sql_query("DELETE FROM {$p}files WHERE file_id BETWEEN 9840 AND 9843");
		break;
	case 'runtime':
		$file = $rows("SELECT * FROM {$p}files WHERE file_id=53")[0];
		$insert('files',array('file_id'=>9830)+$file);
		foreach ($rows("SELECT * FROM {$p}field_values WHERE file_id=53") as $row)
		{
			unset($row['row_id']);
			$insert('field_values',array('file_id'=>9830)+$row);
		}
		break;
	case 'unchanged':
		$columns = 'field_id,values_json,source_json';
		$old = $rows("SELECT $columns FROM {$p}field_values WHERE file_id=53 ORDER BY field_id");
		$copy = $rows("SELECT $columns FROM {$p}field_values WHERE file_id=9830 ORDER BY field_id");
		if (!$old || $old !== $copy) { throw new RuntimeException('A file edit changed archived values despite an identical selection'); }
		break;
	case 'completed':
		$before = $rows("SELECT source_json FROM {$p}field_values WHERE field_id=86 AND file_id=53")[0];
		$changed = $rows("SELECT values_json,source_json FROM {$p}field_values WHERE field_id=86 AND file_id=9830")[0];
		if (!$before['source_json'] || $changed['source_json'] !== '{}' || json_decode($changed['values_json'],true) !==
			array('Vergriffen &amp;amp; 📗','Weitere')) { throw new RuntimeException('Changing a selection corrupted the file values'); }
		$new = $rows("SELECT file_id FROM {$p}files WHERE title='Prüfling'");
		if (count($new)!==1) { throw new RuntimeException('The member submission was lost or duplicated'); }
		$new = (int) $new[0]['file_id'];
		$values = $rows("SELECT d.field_type,v.values_json,v.source_json FROM {$p}field_values v JOIN {$p}fields d ON d.field_id=v.field_id WHERE v.file_id=$new ORDER BY d.field_type");
		$actual = array_map(array(\phpbb2plus\downloads\service\field_data::class,'values'),$values);
		if ($actual !== array(array('PA-22'),array("Zwei\nZeilen"),array('Archiv &amp; 📗'),array('Aktuell'),
			array('Archiv &amp; 📗','Weitere'),array('Aktuell'))) { throw new RuntimeException('A new download did not save all six field types'); }
		$db->sql_query("DELETE FROM {$p}receipts WHERE file_id=$new");
		$db->sql_query("DELETE FROM {$p}field_values WHERE file_id=$new");
		$db->sql_query("DELETE FROM {$p}files WHERE file_id=$new");
		$db->sql_query("DELETE FROM {$p}field_values WHERE file_id=9830");
		$db->sql_query("DELETE FROM {$p}files WHERE file_id=9830");
		break;
	default: exit(2);
}
