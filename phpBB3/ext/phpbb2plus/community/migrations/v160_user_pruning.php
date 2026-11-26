<?php
namespace phpbb2plus\community\migrations;

class v160_user_pruning extends \phpbb\db\migration\migration
{
	public static function depends_on()
	{
		return array('\phpbb2plus\community\migrations\v121_manage_delegations');
	}

	public function update_data()
	{
		return array(
			array('config.add', array('plus_account_prune_key', bin2hex(random_bytes(32)))),
			array('module.add', array('acp', 'ACP_CAT_USERS', array(
				'module_basename' => '\phpbb2plus\community\acp\user_pruning_module',
				'modes' => array('manage'),
			))),
		);
	}
}
