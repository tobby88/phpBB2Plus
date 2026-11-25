<?php
namespace phpbb2plus\colours\migrations;

class v100_palette extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb\db\migration\data\v33x\v3319'); }

	public function update_schema()
	{
		return array('add_tables'=>array($this->table_prefix . 'plus_colours'=>array(
			'COLUMNS'=>array('colour_id'=>array('UINT', null, 'auto_increment'), 'name'=>array('TEXT_UNI',''),
				'colour'=>array('VCHAR:6',''), 'in_legend'=>array('BOOL',1), 'position'=>array('INT:11',0)),
			'PRIMARY_KEY'=>'colour_id')),
			'add_columns'=>array($this->table_prefix . 'users'=>array('plus_colour_id'=>array('UINT',0), 'plus_colour_applied'=>array('BOOL',0)),
				$this->table_prefix . 'groups'=>array('plus_colour_id'=>array('UINT',0))));
	}

	public function revert_schema()
	{
		return array('drop_columns'=>array($this->table_prefix . 'users'=>array('plus_colour_id','plus_colour_applied'),
			$this->table_prefix . 'groups'=>array('plus_colour_id')), 'drop_tables'=>array($this->table_prefix . 'plus_colours'));
	}
}
