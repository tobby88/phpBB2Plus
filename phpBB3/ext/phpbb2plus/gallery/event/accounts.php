<?php
namespace phpbb2plus\gallery\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class accounts implements EventSubscriberInterface
{
	private $db;
	private $prefix;

	public function __construct($db, $prefix)
	{
		$this->db = $db;
		$this->prefix = $prefix;
	}

	static public function getSubscribedEvents()
	{
		return array('core.delete_user_before' => array('depart', 10));
	}

	public function depart($event)
	{
		foreach ($event['user_rows'] as $id => $row)
		{
			$id = (int) $id;
			if ($id <= ANONYMOUS) { continue; }
			$name = $event['retain_username'] ? htmlspecialchars_decode($row['username'], ENT_QUOTES) : '';
			// phpBB has already opened its account removal transaction.
			foreach (array('pics','comments') as $table)
			{
				$this->db->sql_query('UPDATE ' . $this->prefix . $table . ' SET ' . $this->db->sql_build_array('UPDATE',
					array('author_id' => ANONYMOUS, 'author_name' => $name, 'author_removed' => 1)) . ' WHERE author_id=' . $id);
			}
			$this->db->sql_query('UPDATE ' . $this->prefix . 'comments SET ' . $this->db->sql_build_array('UPDATE',
				array('editor_id' => ANONYMOUS, 'retired_editor' => $name)) . ' WHERE editor_id=' . $id);
			$this->db->sql_query('UPDATE ' . $this->prefix . 'cats SET owner_id=' . ANONYMOUS . ' WHERE owner_id=' . $id);
			$this->db->sql_query('UPDATE ' . $this->prefix . 'ratings SET user_id=' . ANONYMOUS . ' WHERE user_id=' . $id);
			$this->db->sql_query('DELETE FROM ' . $this->prefix . "grants WHERE subject_type='u' AND subject_id=$id");
		}
	}
}
