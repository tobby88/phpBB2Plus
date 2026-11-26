<?php
namespace phpbb2plus\weblinks\migrations;

class v100_directory extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb\db\migration\data\v33x\v3319'); }

	public function update_schema()
	{
		$p = $this->table_prefix . 'plus_wl_';
		$auto = array('UINT:10', null, 'auto_increment');
		$text = array('TEXT_UNI', '');
		$id = array('UINT', 0);
		return array('add_tables'=>array(
			$p . 'categories'=>array('COLUMNS'=>array('cat_id'=>$auto, 'title'=>$text, 'position'=>$id),
				'PRIMARY_KEY'=>'cat_id', 'KEYS'=>array('list_order'=>array('INDEX', array('position','cat_id')))),
			$p . 'entries'=>array('COLUMNS'=>array('link_id'=>$auto, 'cat_id'=>$id, 'title'=>$text, 'description'=>$text,
				'destination'=>$text, 'logo'=>$text, 'local_logo'=>array('VCHAR:80',''), 'joined'=>array('TIMESTAMP',0),
				'status'=>array('TINT:2',0), 'hits'=>array('BINT',0), 'user_id'=>$id, 'source_user'=>array('INT:11',0),
				'submitter_ip'=>array('VCHAR:45',''), 'last_ip'=>array('VCHAR:45',''), 'revision'=>array('UINT',1)),
				'PRIMARY_KEY'=>'link_id', 'KEYS'=>array('category'=>array('INDEX',array('cat_id','status','joined')),
					'latest'=>array('INDEX',array('status','joined')), 'popular'=>array('INDEX',array('status','hits')),
					'author'=>array('INDEX',array('user_id','joined')))),
		));
	}

	public function update_data()
	{
		$changes = array();
		foreach (\phpbb2plus\weblinks\service\directory::DEFAULTS as $key=>$value)
		{
			$changes[] = array('config.add',array('plus_wl_' . $key,$value));
		}
		$changes[] = array('config.add',array('plus_wl_local_logo',''));
		$changes[] = array('config.add',array('plus_wl_inputs_valid',1));
		$changes[] = array('custom',array(array($this,'default_category')));
		foreach (array('a_plus_wl_review','a_plus_wl_publish') as $right)
		{
			$changes[] = array('permission.add',array($right));
			$changes[] = array('permission.permission_set',array('ROLE_ADMIN_FULL',$right));
		}
		$changes[] = array('module.add',array('acp','ACP_BOARD_CONFIGURATION',array(
			'module_basename'=>'\phpbb2plus\weblinks\acp\review_module','modes'=>array('review'))));
		return $changes;
	}

	public function default_category()
	{
		$this->db->sql_query('INSERT INTO ' . $this->table_prefix . 'plus_wl_categories ' .
			$this->db->sql_build_array('INSERT',array('cat_id'=>1,'title'=>'Links','position'=>1)));
	}

	public function revert_schema()
	{
		return array('drop_tables'=>array($this->table_prefix . 'plus_wl_entries',$this->table_prefix . 'plus_wl_categories'));
	}
}
