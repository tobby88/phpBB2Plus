<?php
namespace phpbb2plus\weblinks\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class listener implements EventSubscriberInterface
{
	private $db;
	private $table;

	public function __construct($db, $table) { $this->db = $db; $this->table = $table; }

	public static function getSubscribedEvents()
	{
		return array('core.permissions'=>'permissions', 'core.user_setup'=>'language', 'core.delete_user_before'=>'accounts');
	}

	public function language($event)
	{
		$files = $event['lang_set_ext'];
		$files[] = array('ext_name'=>'phpbb2plus/weblinks','lang_set'=>'directory');
		$event['lang_set_ext'] = $files;
	}

	public function accounts($event)
	{
		$ids = array_map('intval',array_keys($event['user_rows']));
		if ($ids)
		{
			$this->db->sql_query("UPDATE $this->table SET source_user=CASE WHEN source_user>0 THEN source_user ELSE user_id END,
				user_id=" . ANONYMOUS . ',revision=revision+1 WHERE ' . $this->db->sql_in_set('user_id',$ids));
		}
	}

	public function permissions($event)
	{
		$map = $event['permissions'];
		foreach (array_merge(array('a_plus_wl_review','a_plus_wl_publish'), \phpbb2plus\weblinks\service\management::RIGHTS) as $right)
		{
			$map[$right] = array('lang'=>'ACL_' . strtoupper($right),'cat'=>'misc');
		}
		$event['permissions'] = $map;
	}
}
