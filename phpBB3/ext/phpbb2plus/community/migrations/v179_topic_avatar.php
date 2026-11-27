<?php
namespace phpbb2plus\community\migrations;

class v179_topic_avatar extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\community\migrations\v109_legacy_links'); }
	public function update_schema()
	{
		return array('add_columns'=>array($this->table_prefix . 'users'=>array('plus_no_default_avatar'=>array('BOOL',0))));
	}
	public function revert_schema()
	{
		return array('drop_columns'=>array($this->table_prefix . 'users'=>array('plus_no_default_avatar')));
	}
	public function update_data()
	{
		// Earlier conversions did not record the board's original switch.
		$result = $this->db->sql_query_limit('SELECT user_id FROM ' . $this->table_prefix . 'users WHERE plus_legacy_id>0',1);
		$fresh = $this->db->sql_fetchfield('user_id') === false;
		$this->db->sql_freeresult($result);
		return array(array('config.add',array('plus_topic_default_avatar',(int) $fresh)), array('config.add',array('plus_topic_avatar_image','stock.gif')),
			array('custom',array(array($this,'missing_avatars'))));
	}
	public function missing_avatars()
	{
		$p = $this->table_prefix;
		$this->db->sql_query("UPDATE {$p}users SET plus_no_default_avatar=1 WHERE user_avatar='' AND plus_legacy_id>0
			AND plus_legacy_id IN (SELECT source_id FROM {$p}plus_media_issues WHERE asset_kind='avatar' AND reason IN ('unavailable','disabled_in_source'))");
	}
}
