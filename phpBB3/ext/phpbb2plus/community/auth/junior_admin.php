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
		$result = $this->db->sql_query("SELECT j.rights_json, e.ext_active FROM {$this->table} j JOIN " . EXT_TABLE .
			" c ON c.ext_name='phpbb2plus/community' AND c.ext_active=1 LEFT JOIN " . EXT_TABLE .
			" e ON e.ext_name='phpbb2plus/knowledgebase' WHERE j.user_id=$id");
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		if (!$row) { return array(); }
		$rights = json_decode($row['rights_json'], true);
		if (!is_array($rights)) { return array(); }
		$rights = array_intersect(array_column(self::MODULES, 2), $rights);
		if (!$rights) { return array(); }
		// Native unset and NEVER have the same cached bit. Honour explicit denials from the
		// account, its roles and its active groups before exposing even a single module.
		$existing = $auth->acl_raw_data($id, array_merge(array('a_'), $rights), 0);
		$existing = $existing[$id][0] ?? array();
		if ((int) ($existing['a_'] ?? ACL_NO) === ACL_NEVER) { return array(); }
		$allowed = array();
		foreach ($rights as $right)
		{
			if (isset($auth->acl_options['global'][$right]) && self::supported_option($right) && (int) ($existing[$right] ?? ACL_NO) !== ACL_NEVER &&
				($row['ext_active'] || strpos($right, 'a_plus_kb_') !== 0)) { $allowed[$right] = true; }
		}
		return $allowed;
	}
}
