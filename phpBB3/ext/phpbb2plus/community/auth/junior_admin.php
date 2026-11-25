<?php
namespace phpbb2plus\community\auth;

/** Old grants identify individual ACP module registrations, including the query string. */
class junior_admin
{
	private const FILE_ACTIONS = array('phpbb2plus\downloads\acp\file_actions_module', 'manage');

	public const MODULES = array(
		'admin_words.php' => array('General', 'Word_Censor', array('a_words' => array('acp_words', 'words'))),
		'admin_disallow.php' => array('Users', 'Disallow', array('a_names' => array('acp_disallow', 'usernames'))),
		'admin_ranks.php' => array('Users', 'Ranks', array('a_ranks' => array('acp_ranks', 'ranks'))),
		'admin_statistics.php?mode=manage' => array('Statistics', 'Statistics_management', array('a_plus_stats_modules' => array('phpbb2plus\community\acp\statistics_module', 'manage'))),
		'admin_statistics.php?mode=config' => array('Statistics', 'Statistics_config', array('a_plus_stats_settings' => array('phpbb2plus\community\acp\statistics_module', 'settings'))),
		'admin_kb_art.php' => array('KB_title', 'Art_man', array('a_plus_kb_articles' => array('phpbb2plus\knowledgebase\acp\articles_module', 'manage'))),
		'admin_kb_types.php' => array('KB_title', 'Types_man', array('a_plus_kb_types' => array('phpbb2plus\knowledgebase\acp\types_module', 'manage'))),
		'admin_kb_cat.php' => array('KB_title', 'Cat_man', array('a_plus_kb_categories' => array('phpbb2plus\knowledgebase\acp\categories_module', 'manage'))),
		'admin_pa_category.php' => array('Download', 'Cat_manage_title', array('a_plus_dl_categories' => array('phpbb2plus\downloads\acp\categories_module', 'manage'))),
		'admin_pa_catauth.php' => array('Download', 'Permissions', array('a_plus_dl_perms' => array('phpbb2plus\downloads\acp\permissions_module', 'manage'))),
		'admin_pa_settings.php' => array('Download', 'Settings', array('a_plus_dl_settings' => array('phpbb2plus\downloads\acp\settings_module', 'manage'))),
		'admin_pa_file.php' => array('Download', 'File_manage_title', array(
			'a_plus_dl_edit' => self::FILE_ACTIONS, 'a_plus_dl_approval' => self::FILE_ACTIONS, 'a_plus_dl_delete' => self::FILE_ACTIONS,
			'a_plus_dl_mirrors' => array('phpbb2plus\downloads\acp\mirrors_module', 'manage'),
		)),
		'admin_pa_license.php' => array('Download', 'License_title', array('a_plus_dl_licenses' => array('phpbb2plus\downloads\acp\licenses_module', 'manage'))),
		'admin_pa_custom.php' => array('Download', 'Mfieldtitle', array('a_plus_dl_fields' => array('phpbb2plus\downloads\acp\fields_module', 'manage'))),
		'admin_album_cat.php' => array('Photo_Album', 'Categories', array('a_plus_album_categories' => array('phpbb2plus\gallery\acp\categories_module', 'manage'))),
		'admin_album_auth.php' => array('Photo_Album', 'Permissions', array('a_plus_album_auth' => array('phpbb2plus\gallery\acp\permissions_module', 'manage'))),
		'admin_album_personal.php' => array('Photo_Album', 'Personal_Galleries', array('a_plus_album_personal_auth' => array('phpbb2plus\gallery\acp\personal_permissions_module', 'manage'))),
		'admin_album_clearcache.php' => array('Photo_Album', 'Clear_Cache', array('a_plus_album_cache' => array('phpbb2plus\gallery\acp\cache_module', 'manage'))),
		'admin_cracker_tracker.php?modu=4' => array('ctracker_module_category', 'ctracker_module_4',
			array('a_plus_ct_notice' => array('phpbb2plus\community\acp\tracker_settings_module', 'notice'))),
		'admin_cracker_tracker.php?modu=9' => array('ctracker_module_category', 'ctracker_module_9',
			array('a_plus_ct_settings' => array('phpbb2plus\community\acp\tracker_settings_module', 'manage'))),
		'admin_hacks_list.php' => array('General', 'Hacks_List', array('a_plus_credits' => array('phpbb2plus\community\acp\credits_module', 'manage'))),
	);

	private $db;
	private $table;

	public function __construct($db, $table)
	{
		$this->db = $db;
		$this->table = $table;
	}

	/** One source registration may now use several independently assignable native permissions. */
	public static function targets()
	{
		static $targets;
		if ($targets === null) { $targets = array_merge(...array_column(self::MODULES, 2)); }
		return $targets;
	}

	public static function supported_option($option)
	{
		// An older Knowledge Base used the review permission for editing any article.
		return $option !== 'a_plus_kb_articles' || method_exists(\phpbb2plus\knowledgebase\service\articles::class, 'reviewer');
	}

	public function options($userdata, $auth)
	{
		if (empty($userdata['plus_jr_delegate']) || empty($userdata['is_registered']) || !isset($userdata['user_perm_from']) || (int) $userdata['user_perm_from'] !== 0 ||
			(int) ($userdata['user_type'] ?? USER_IGNORE) !== USER_NORMAL) { return array(); }
		$id = (int) $userdata['user_id'];
		$result = $this->db->sql_query("SELECT rights_json FROM {$this->table} WHERE user_id=$id");
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		if (!$row) { return array(); }
		$rights = json_decode($row['rights_json'], true);
		if (!is_array($rights)) { return array(); }
		$allowed = array();
		foreach ($this->states($id, $auth, $rights) as $right => $state)
		{
			if ($state === 'available' || $state === 'native') { $allowed[$right] = true; }
		}
		return $allowed;
	}

	/** The same native never/extension checks drive both the ACP editor and the effective login. */
	public function states($id, $auth, array $requested)
	{
		$modules = array();
		$needed = array('phpbb2plus/community');
		foreach (self::targets() as $right => $target)
		{
			if (!in_array($right, $requested, true)) { continue; }
			$parts = explode('\\', ltrim($target[0], '\\'));
			$extension = isset($parts[1]) ? $parts[0] . '/' . $parts[1] : '';
			$modules[$right] = $extension;
			if ($extension !== '') { $needed[] = $extension; }
		}
		if (!$modules) { return array(); }
		$result = $this->db->sql_query('SELECT ext_name FROM ' . EXT_TABLE . ' WHERE ext_active=1 AND ' .
			$this->db->sql_in_set('ext_name', array_values(array_unique($needed))));
		$enabled = array_flip(array_column($this->db->sql_fetchrowset($result), 'ext_name'));
		$this->db->sql_freeresult($result);
		// Native unset and NEVER have the same cached bit. Honour explicit denials from the
		// account, its roles and its active groups before exposing even a single module.
		$existing = $auth->acl_raw_data((int) $id, array_merge(array('a_'), array_keys($modules)), 0);
		$existing = $existing[$id][0] ?? array();
		$states = array();
		foreach ($modules as $right => $extension)
		{
			$setting = (int) ($existing[$right] ?? ACL_NO);
			if (!isset($enabled['phpbb2plus/community'], $auth->acl_options['global'][$right]) ||
				($extension !== '' && !isset($enabled[$extension])) || !self::supported_option($right)) { $states[$right] = 'unavailable'; }
			elseif ($setting === ACL_NEVER || (int) ($existing['a_'] ?? ACL_NO) === ACL_NEVER) { $states[$right] = 'never'; }
			else { $states[$right] = $setting === ACL_YES ? 'native' : 'available'; }
		}
		return $states;
	}
}
