<?php
namespace phpbb2plus\community\migrations;

use phpbb2plus\community\service\forum_summaries;

class v198_forum_summaries extends \phpbb\db\migration\migration
{
	public static function depends_on()
	{
		return array('\\phpbb2plus\\community\\migrations\\v109_legacy_links', '\\phpbb2plus\\community\\migrations\\v136_removed_topic_shadows');
	}

	public function update_data()
	{
		$db = $this->db;
		$evidence = array(
			'SELECT user_id FROM ' . USERS_TABLE . ' WHERE plus_legacy_id>0',
			'SELECT forum_id FROM ' . FORUMS_TABLE . ' WHERE plus_legacy_category>0',
			'SELECT log_id FROM ' . LOG_TABLE . " WHERE log_type=" . LOG_ADMIN .
				" AND log_operation='LOG_INSTALL_CONVERTED' AND log_data " . $db->sql_like_expression($db->get_any_char() . 'phpBB2 Plus (UTF-8)' . $db->get_any_char()),
		);
		foreach ($evidence as $sql)
		{
			$result = $db->sql_query_limit($sql, 1);
			$converted = (bool) $db->sql_fetchrow($result);
			$db->sql_freeresult($result);
			if ($converted)
			{
				return array(array('config.add', array(forum_summaries::CURSOR, 0, true)), array('custom', array(array($this, 'synchronize'))));
			}
		}
		return array();
	}

	public function synchronize()
	{
		return forum_summaries::advance($this->db, $this->config);
	}
}
