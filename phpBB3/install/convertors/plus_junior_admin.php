<?php
if (!defined('IN_PHPBB')) { exit; }

use phpbb2plus\community\auth\junior_admin;

function plus_jr_source_present($source_db, $prefix)
{
	$table = $source_db->sql_escape($prefix . 'jr_admin_users');
	$result = $source_db->sql_query("SELECT COUNT(*) AS present FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$table'");
	$present = (bool) $source_db->sql_fetchfield('present');
	$source_db->sql_freeresult($result);
	return $present;
}

function plus_jr_source_directory($root)
{
	$helper = @file_get_contents(plus_media_path($root, 'includes/functions_jr_admin.php'));
	if ($helper === false) { return false; }
	$tokens = array_values(array_filter(token_get_all($helper), static function ($token)
	{
		return !is_array($token) || !in_array($token[0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true);
	}));
	$directory = null;
	for ($i = 0, $limit = count($tokens) - 5; $i < $limit; $i++)
	{
		if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_STRING || strtolower($tokens[$i][1]) !== 'define' ||
			$tokens[$i + 1] !== '(' || jr_admin_registration_value(array($tokens[$i + 2]), '') !== 'JR_ADMIN_DIR') { continue; }
		if ($directory !== null || $tokens[$i + 3] !== ',' || !is_array($tokens[$i + 4]) ||
			$tokens[$i + 4][0] !== T_CONSTANT_ENCAPSED_STRING || $tokens[$i + 5] !== ')') { return false; }
		$directory = jr_admin_registration_value(array($tokens[$i + 4]), '');
	}
	if (!is_string($directory) || !preg_match('~^[a-z0-9_-]+(?:/[a-z0-9_-]+)*/?$~iD', $directory)) { return false; }
	return plus_media_path($root, rtrim($directory, '/'), true);
}

function plus_jr_source_matches($contents, $digest)
{
	return is_string($contents) && hash('sha256', str_replace(array("\r\n", "\r"), "\n", $contents)) === $digest;
}

function plus_jr_reviewed_dependencies($root, $directory, array $dependencies)
{
	foreach ($dependencies as $file => $digest)
	{
		// A sibling controller lives in the installation's actual ACP directory.
		$base = strpos($file, '/') === false ? $directory : $root;
		try { $path = plus_media_path($base, $file); }
		catch (\RuntimeException $e) { return false; }
		if (!plus_jr_source_matches(@file_get_contents($path), $digest)) { return false; }
	}
	return true;
}

function plus_jr_registry()
{
	global $convert;
	// This parser is shipped from the trusted Plus distribution by the assembler.
	// Source controllers are read strictly as text, including on customised boards.
	require_once __DIR__ . '/plus_jr_registrations.php';
	try { $directory = plus_jr_source_directory($convert->options['forum_path']); }
	catch (\RuntimeException $e) { return false; }
	if ($directory === false) { return false; }
	$files = @scandir($directory);
	if ($files === false) { return false; }
	// A locally modified module can expose fewer actions under the same name. These
	// fingerprints identify the reviewed source controllers; line endings are immaterial.
	$reviewed = array(
		'admin_statistics.php' => '9dae7adc915d806601d0140450537a82d430d8039f06e095ae763b32972980e1',
		'admin_pa_custom.php' => '9237b12da9f50adbc87ff38da3b3550063a92d017dc628b410ee65a69927350b',
		'admin_album_personal.php' => 'd97d4e085e90eac081030134f6894b60b3e4824ee6d1ef88ad82bd1fd36f5d87',
		'admin_words.php' => '5d8a246e3aa046ed04b6e9346336e66ca0a79031ad0c2a8aa81f4b8d8a257e59',
		'admin_disallow.php' => '5da99a11b96be1a5020ceca18693aaa0e6339e10ba40f91f18405b71ae7fc8fd',
		'admin_ranks.php' => 'ae0f662dc79f4e9a203a32e4193d983c33db4666f76e684aa81fc12296fcfd1e',
		'admin_kb_art.php' => '2a92d60fe3c5772a0fce2bd09f10fabbee72e185fc99c3ce1ecc843aaca6f5ff',
		'admin_kb_types.php' => '9176f2a2cea0f652c22e7111bd70442cc29da931f758dd4f882ba0369994fb46',
		'admin_kb_cat.php' => '863dc52eacce55c3c07f348a334afb4b12f5d807b796e390184e416e58b34b39',
		'admin_pa_category.php' => 'e9ce286f24b744c64d27055848046ddd874a23eb99f9bbe3b4e6e8b3f7ed3b00',
		'admin_pa_catauth.php' => '82f9f61690992ab2986c841535e13cd0678d838f0c180b1c8166a2cf043b489e',
		'admin_pa_license.php' => '32dc2e04ca66019c8b2e02cf77a14d2ad15256543844ab154bd915ead4fbb76c',
		'admin_album_cat.php' => '621ff302762cb0bad95c8a0039445bbdc1b94b4d7ee3d01e7fe669b30447c406',
		'admin_album_auth.php' => '6a79dbf47486d217b4660903ef30efa8b35a85c99daa72b04bd88766e2742767',
		'admin_hacks_list.php' => '12e39f97b24f72134803b9d4e82dbf0453240bc5c18ddda038278d4a3d73e9f1',
	);
	// These controllers share or delegate their actual write authority. A customised
	// or absent helper can narrow the source grant despite an unchanged menu entry.
	$file_authority = array(
		'includes/functions_jr_admin_auth.php' => '66560bc6fe4af98e0d6870c71b5b3682fb131ae6f13d2545aab481b0d5f2c735',
		'pafiledb/includes/functions_file_authority.php' => 'd875f99ae5eafa9a2398fbc89fefd86cc406a7d91633290834c62b77023ba2a1',
	);
	$dependencies = array(
		'admin_statistics.php' => array(
			'includes/functions_stats.php' => 'df16dfb95b1f29bd7afc18b882d5516c0193fa63f0dbc09a640bd7ec5570f602',
			'includes/functions_module.php' => '886c98ef69101689102bcfa5bd9a973214ca4bfaee639d4f031910508ec7d045',
		),
		'admin_pa_custom.php' => $file_authority + array(
			'pafiledb/includes/functions_custom_storage.php' => '148a8b14339e9d4808f5283add8ec0dc6ddc3ad2bef7da0dabdfcd29ade4c0ee',
			'pafiledb/includes/functions_field.php' => '55cda748f5b69160f14244f22d4496a8520f4ab4160150828effb35b4428ea20',
		),
		'admin_pa_catauth.php' => $file_authority + array(
			'admin_pa_ug_auth.php' => 'fa7397cc5100fd2dbeb871477fc5bc166d63a5d3ff789df671ce1a5e09edcd01',
			'pafiledb/includes/functions_permission_storage.php' => '4b9503464cdb3e0e7a5ec3426a116f0c6e0cb6c251a6ad5ebebac1d78900c450',
		),
		'admin_pa_license.php' => $file_authority + array(
			'pafiledb/includes/functions_license_storage.php' => '65f0f934e1574795548a1d9a52add58a77b7a3fb5e72cf59e6f1b62efdf92300',
		),
		'admin_album_cat.php' => array(
			'album_mod/album_admin_category_storage.php' => '872eab0aaf53f1ec8201ff7b91dbfc44d334187c7bc813de5ed43f078f8b5f2f',
			'album_mod/album_category_remove.php' => 'd10eea4bd6208303ec8430c513ae51de64fbfc1dd322a27b8bee3367e45439ba',
			'album_mod/album_lifecycle_authority.php' => '0c7a663f8211eb2f317695d1e384a27f28de1eaaf422d74b250dfd8ced638349',
		),
	);
	$registry = array();
	foreach ($files as $file)
	{
		if (!preg_match('/^admin_[a-z0-9_]+\.php$/iD', $file)) { continue; }
		try { $path = plus_media_path($directory, $file); }
		catch (\RuntimeException $e) { return false; }
		$source = @file_get_contents($path);
		$entries = $source !== false ? jr_admin_file_registrations($source, $file) : false;
		if ($entries === false) { return false; }
		foreach ($entries as $entry)
		{
			list($category, $label, $route) = $entry;
			$hash = md5($category . $label . $route);
			$record = array('category' => $category, 'module' => $label, 'route' => $route);
			$map = junior_admin::MODULES[$route] ?? null;
			if ($map && $file === explode('?', $route, 2)[0] && $category === $map[0] && $label === $map[1])
			{
				if (isset($reviewed[$file]) && plus_jr_source_matches($source, $reviewed[$file])
					&& plus_jr_reviewed_dependencies($convert->options['forum_path'], $directory, $dependencies[$file] ?? array()))
				{ $record['option'] = $map[2]; }
				else { $record['customised'] = true; }
			}
			// A concatenated identity need not identify the same three individual strings.
			if (array_key_exists($hash, $registry) && $registry[$hash] !== $record) { $record = false; }
			$registry[$hash] = $record;
		}
	}
	return $registry;
}

function plus_jr_account($id, $level, $active)
{
	if ((int) $id <= 0) { return 'missing-account'; }
	if ((int) $active !== 1) { return 'inactive-account'; }
	if ((int) $level === 1) { return 'full-administrator'; }
	return in_array((int) $level, array(0, 2), true) ? 'active' : 'unknown-account-level';
}

function plus_jr_target_user($id)
{
	return (int) $id > 0 ? phpbb_user_id((int) $id) : 0;
}

function plus_jr_preserve($text)
{
	if (strlen((string) $text) > 12582909) { throw new \RuntimeException('A Junior Admin source field exceeds the destination MEDIUMTEXT capacity.'); }
	return base64_encode((string) $text);
}

function plus_jr_plan($id, $level, $active, $raw)
{
	global $db;
	static $previous = null, $plan = null, $registry = null, $available = null;
	$key = array($id, $level, $active, $raw);
	if ($previous === $key) { return $plan; }
	$previous = $key;
	$account = plus_jr_account($id, $level, $active);
	$rights = $report = array();
	if ($raw !== '')
	{
		if ($registry === null) { $registry = plus_jr_registry(); }
		if ($available === null)
		{
			$result = $db->sql_query('SELECT auth_option FROM ' . ACL_OPTIONS_TABLE . ' WHERE is_global=1 AND ' .
				$db->sql_in_set('auth_option', array_column(junior_admin::MODULES, 2)));
			$available = array_flip(array_column($db->sql_fetchrowset($result), 'auth_option'));
			$db->sql_freeresult($result);
		}
		if (strlen($raw) > 1048576 || substr_count($raw, '|') >= 4096)
		{
			$report[] = array('result' => 'oversized-list');
		}
		else
		{
			foreach (explode('|', $raw) as $token)
			{
				if (!preg_match('/^[a-f0-9]{32}$/D', $token)) { $report[] = array('result' => 'invalid-hash'); continue; }
				$entry = $registry[$token] ?? null;
				$entry = is_array($entry) ? $entry : array();
				if ($registry === false) { $reason = 'source-registry-unavailable'; }
				elseif (!array_key_exists($token, $registry)) { $reason = 'unresolved-module'; }
				elseif ($registry[$token] === false) { $reason = 'ambiguous-module'; }
				elseif (isset($entry['customised'])) { $reason = 'modified-source-module'; }
				elseif (!isset($entry['option'])) { $reason = 'manual-review'; }
				elseif ($account !== 'active') { $reason = $account; }
				elseif (!isset($available[$entry['option']])) { $reason = 'target-option-unavailable'; }
				elseif (!junior_admin::supported_option($entry['option'])) { $reason = 'target-module-too-broad'; }
				else
				{
					$reason = 'delegated';
					$rights[$entry['option']] = true;
				}
				$report[] = array('hash' => $token, 'result' => $reason) + $entry;
			}
		}
	}
	$plan = array(json_encode($report, JSON_INVALID_UTF8_SUBSTITUTE), json_encode(array_keys($rights)));
	return $plan;
}

function plus_jr_report($id, $level, $active, $raw) { return plus_jr_plan($id, $level, $active, $raw)[0]; }
function plus_jr_rights($id, $level, $active, $raw) { return plus_jr_plan($id, $level, $active, $raw)[1]; }

function plus_jr_schema($prefix, $truncate)
{
	$table = $prefix . 'plus_jr_records';
	$account = array('users.user_id AS jr_owner_id', 'users.user_level', 'users.user_active');
	$input = array_merge($account, array('jr_admin_users.user_jr_admin'));
	return array('target' => $table, 'primary' => 'jr_admin_users.user_id',
		'query_first' => array('target', $truncate . $table),
		'left_join' => 'jr_admin_users LEFT JOIN users ON users.user_id=jr_admin_users.user_id',
		array('source_user', 'jr_admin_users.user_id', 'intval'), array('user_id', $account[0], 'plus_jr_target_user'),
		array('account_state', $account, 'plus_jr_account'), array('modules_b64', 'jr_admin_users.user_jr_admin', 'plus_jr_preserve'),
		array('notes_b64', 'jr_admin_users.admin_notes', 'plus_jr_preserve'), array('notes_view', 'jr_admin_users.notes_view', 'intval'),
		array('start_date', 'jr_admin_users.start_date', ''), array('update_date', 'jr_admin_users.update_date', ''),
		array('report_json', $input, 'plus_jr_report'), array('rights_json', $input, 'plus_jr_rights'));
}

function plus_jr_finish()
{
	global $db, $table_prefix;
	\phpbb2plus\community\auth\delegations::import($db, $table_prefix);
}
