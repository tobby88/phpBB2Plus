<?php
namespace phpbb2plus\banners\migrations;

class v100_banners extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb\db\migration\data\v33x\v3319'); }

	public function update_schema()
	{
		$p = $this->table_prefix . 'plus_banner';
		$id = array('UINT', 0);
		$num = array('INT:11', 0);
		$text = array('TEXT_UNI', '');
		$auto = array('UINT:10', null, 'auto_increment');
		// phpBB's 22-digit decimal has 20 integral digits, enough for the original unsigned BIGINT.
		$counter = array('DECIMAL:22', 0);
		return array('add_tables'=>array(
			$p . 's'=>array('COLUMNS'=>array('banner_id'=>$auto, 'display_name'=>$text, 'description'=>$text, 'target_url'=>$text,
				'spot'=>$id, 'forum_id'=>$id, 'owner_id'=>$id, 'source_owner'=>$num, 'clicks'=>$counter, 'views'=>$counter,
				'weight'=>$id, 'active'=>array('BOOL', 0), 'time_mode'=>$num, 'time_begin'=>$num, 'time_end'=>$num,
				'date_begin'=>$num, 'date_end'=>$num, 'audience'=>$num, 'audience_mode'=>$num, 'notes'=>$text,
				'media_type'=>$num, 'width'=>$id, 'height'=>$num, 'filter_clicks'=>array('BOOL', 0), 'filter_seconds'=>$num,
				'asset_name'=>array('VCHAR:80',''), 'asset_mime'=>array('VCHAR:64',''), 'asset_bytes'=>array('BINT',0),
				'remote_asset'=>$text), 'PRIMARY_KEY'=>'banner_id', 'KEYS'=>array('active_forum'=>array('INDEX',array('active','forum_id','spot')))),
			$p . '_hits'=>array('COLUMNS'=>array('hit_id'=>$auto, 'banner_id'=>$id, 'hit_time'=>array('BINT',0), 'ip'=>array('VCHAR:45',''),
				'user_id'=>$id, 'source_user'=>$num, 'duration'=>array('BINT',0)), 'PRIMARY_KEY'=>'hit_id',
				'KEYS'=>array('banner_time'=>array('INDEX',array('banner_id','hit_time')), 'ip_time'=>array('INDEX',array('ip','hit_time')))),
			$p . '_receipts'=>array('COLUMNS'=>array('banner_id'=>$id, 'visitor_key'=>array('CHAR:64',''), 'expires'=>array('TIMESTAMP',0)),
				'PRIMARY_KEY'=>array('banner_id','visitor_key'), 'KEYS'=>array('expiry'=>array('INDEX','expires')))));
	}

	public function revert_schema()
	{
		$p = $this->table_prefix . 'plus_banner';
		return array('drop_tables'=>array($p . '_receipts', $p . '_hits', $p . 's'));
	}
	public function update_data() { return array(array('config.add',array('plus_banners_enabled',0))); }
}
