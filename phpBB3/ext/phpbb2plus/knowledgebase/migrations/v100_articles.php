<?php
namespace phpbb2plus\knowledgebase\migrations;

class v100_articles extends \phpbb\db\migration\migration
{
	static public function depends_on() { return array('\phpbb\db\migration\data\v33x\v3319'); }

	public function update_schema()
	{
		$p = $this->table_prefix . 'plus_kb_';
		return array('add_tables' => array(
			$p . 'cats' => array('COLUMNS' => array('cat_id' => array('UINT', null, 'auto_increment'), 'parent_id' => array('UINT', 0),
				'title' => array('VCHAR_UNI', ''), 'description' => array('TEXT_UNI', ''), 'sort_order' => array('UINT', 0)),
				'PRIMARY_KEY' => 'cat_id', 'KEYS' => array('hierarchy' => array('INDEX', array('parent_id', 'sort_order')))),
			$p . 'types' => array('COLUMNS' => array('type_id' => array('UINT', null, 'auto_increment'), 'title' => array('VCHAR_UNI', '')), 'PRIMARY_KEY' => 'type_id'),
			$p . 'settings' => array('COLUMNS' => array('setting_name' => array('VCHAR:191', ''), 'setting_value' => array('TEXT_UNI', '')), 'PRIMARY_KEY' => 'setting_name'),
			$p . 'articles' => array('COLUMNS' => array('article_id' => array('UINT', null, 'auto_increment'), 'cat_id' => array('UINT', 0),
				'type_id' => array('UINT', 0), 'title' => array('VCHAR_UNI', ''), 'description' => array('TEXT_UNI', ''),
				'author_id' => array('UINT', 0), 'author_name' => array('VCHAR_UNI', ''), 'author_removed' => array('BOOL', 0),
				'created_at' => array('BINT', 0), 'source_date' => array('VCHAR:255', ''), 'approval' => array('TINT:2', 0), 'topic_id' => array('UINT', 0),
				'views' => array('BINT', 0), 'historical_points' => array('VCHAR:32', '0.0000'), 'historical_votes' => array('VCHAR:20', '0'),
				'editor_text' => array('MTEXT_UNI', ''), 'search_text' => array('MTEXT_UNI', '')),
				'PRIMARY_KEY' => 'article_id', 'KEYS' => array('category' => array('INDEX', array('cat_id','approval','article_id')),
					'author' => array('INDEX', array('author_id','approval')), 'topic' => array('INDEX', 'topic_id'))),
			$p . 'pages' => array('COLUMNS' => array('article_id' => array('UINT', 0), 'page_number' => array('UINT', 1),
				'heading' => array('VCHAR_UNI', ''), 'content' => array('MTEXT_UNI', ''), 'bbcode_uid' => array('VCHAR:8', ''),
				'bbcode_bitfield' => array('VCHAR:255', ''), 'bbcode_options' => array('UINT', 7)), 'PRIMARY_KEY' => array('article_id','page_number')),
			$p . 'votes' => array('COLUMNS' => array('vote_id' => array('UINT:10', null, 'auto_increment'), 'article_id' => array('UINT', 0),
				'user_id' => array('UINT', 0), 'user_ip' => array('VCHAR:50', ''), 'points' => array('USINT', 0)),
				'PRIMARY_KEY' => 'vote_id', 'KEYS' => array('article_user' => array('INDEX', array('article_id','user_id')), 'article_ip' => array('INDEX', array('article_id','user_ip')))),
		));
	}

	public function update_data()
	{
		return array(array('permission.add', array('a_plus_kb')), array('permission.add', array('m_plus_kb', true, false)),
			array('permission.permission_set', array('ROLE_ADMIN_FULL','a_plus_kb')), array('permission.permission_set', array('ROLE_ADMIN_STANDARD','a_plus_kb')));
	}

	public function revert_schema() { return array('drop_tables' => array_keys($this->update_schema()['add_tables'])); }
}
