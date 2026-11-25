<?php
namespace phpbb2plus\community\auth;

/** Old grants identify individual ACP module registrations, including the query string. */
class junior_admin
{
	public const MODULES = array(
		'admin_words.php' => array('General', 'Word_Censor', 'a_words', 'acp_words', 'words'),
		'admin_disallow.php' => array('Users', 'Disallow', 'a_names', 'acp_disallow', 'usernames'),
		'admin_ranks.php' => array('Users', 'Ranks', 'a_ranks', 'acp_ranks', 'ranks'),
		'admin_kb_art.php' => array('KB_title', 'Art_man', 'a_plus_kb_articles', 'phpbb2plus\knowledgebase\acp\articles_module', 'manage'),
		'admin_kb_types.php' => array('KB_title', 'Types_man', 'a_plus_kb_types', 'phpbb2plus\knowledgebase\acp\types_module', 'manage'),
		'admin_kb_cat.php' => array('KB_title', 'Cat_man', 'a_plus_kb_categories', 'phpbb2plus\knowledgebase\acp\categories_module', 'manage'),
	);

	private $db;
	private $table;

	public function __construct($db, $table)
	{
		$this->db = $db;
		$this->table = $table;
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
		foreach (self::MODULES as $definition)
		{
			$right = $definition[2];
			if (!in_array($right, $requested, true)) { continue; }
			$parts = explode('\\', ltrim($definition[3], '\\'));
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
