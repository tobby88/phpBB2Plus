<?php
namespace phpbb2plus\news\migrations;

class v100_news extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb\db\migration\data\v33x\v3319'); }

	public function update_schema()
	{
		return array('add_tables'=>array($this->table_prefix . 'plus_news_cats'=>array(
			'COLUMNS'=>array('category_id'=>array('UINT',null,'auto_increment'), 'name'=>array('VCHAR_UNI',''),
				'image'=>array('VCHAR',''), 'active'=>array('BOOL',1)),
			'PRIMARY_KEY'=>'category_id')),
			'add_columns'=>array($this->table_prefix . 'topics'=>array('plus_news_cat'=>array('UINT',0))),
			'add_index'=>array($this->table_prefix . 'topics'=>array('plus_news_time'=>array('plus_news_cat','topic_time'))));
	}

	public function revert_schema()
	{
		return array('drop_keys'=>array($this->table_prefix . 'topics'=>array('plus_news_time')),
			'drop_columns'=>array($this->table_prefix . 'topics'=>array('plus_news_cat')),
			'drop_tables'=>array($this->table_prefix . 'plus_news_cats'));
	}

	public function update_data()
	{
		$changes = array(array('permission.add',array('f_plus_news',false,'f_announce')),
			array('custom',array(array($this,'seed_categories'))));
		foreach (array('publish'=>1,'page_size'=>10,'trim'=>0,'title_trim'=>0,'feed'=>1,'feed_limit'=>15,
			'feed_abstract'=>1,'feed_description'=>'','feed_language'=>'','feed_ttl'=>60,'feed_category'=>'',
			'feed_image'=>'','feed_image_desc'=>'') as $name=>$value)
		{
			$changes[] = array('config.add',array('plus_news_' . $name, $value));
		}
		return $changes;
	}

	public function seed_categories()
	{
		$this->db->sql_multi_insert($this->table_prefix . 'plus_news_cats',array(
			array('category_id'=>1,'name'=>'News','image'=>'topicnews.gif','active'=>1),
			array('category_id'=>2,'name'=>'Announcements','image'=>'announcements.gif','active'=>1)));
	}
}
