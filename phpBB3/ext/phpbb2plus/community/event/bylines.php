<?php
namespace phpbb2plus\community\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class bylines implements EventSubscriberInterface
{
	private $db;
	private $user;
	private $table;
	private $names;
	private $editors = array();

	public function __construct($db, $user, $table, $names)
	{
		$this->db = $db;
		$this->user = $user;
		$this->table = $table;
		$this->names = $names;
	}

	static public function getSubscribedEvents()
	{
		return array('core.viewtopic_modify_post_data'=>'show_bylines', 'phpbb2plus.community.preview_bylines'=>'show_bylines',
			'core.viewtopic_modify_post_row'=>'show_editor');
	}

	public function show_bylines($event)
	{
		if (!$event['post_list'])
		{
			return;
		}
		// Limit the lookup to the posts phpBB already selected for this viewer.
		$rows = $event['rowset'];
		$result = $this->db->sql_query('SELECT post_id, author_name, editor_name FROM ' . $this->table . '
			WHERE ' . $this->db->sql_in_set('post_id', array_map('intval', $event['post_list'])));
		while ($byline = $this->db->sql_fetchrow($result))
		{
			$id = (int) $byline['post_id'];
			if (isset($rows[$id]) && (int) $rows[$id]['user_id'] === ANONYMOUS && $byline['author_name'] !== '')
			{
				$rows[$id]['post_username'] = $this->names->hidden() ? $this->names->label() : $byline['author_name'];
			}
			if (isset($rows[$id]['post_edit_user']) && $byline['editor_name'] !== '' && (int) $rows[$id]['post_edit_user'] <= ANONYMOUS)
			{
				$this->editors[$id] = $byline['editor_name'];
			}
		}
		$this->db->sql_freeresult($result);
		$event['rowset'] = $rows;
	}

	public function show_editor($event)
	{
		$row = $event['row'];
		$id = (int) $row['post_id'];
		$post = $event['post_row'];
		if (!isset($this->editors[$id]) || $post['EDITED_MESSAGE'] === '') { return; }
		$name = $this->editors[$id];
		if ($this->names->hidden() && $this->names->known($name)) { $name = $this->names->label(); }
		$post['EDITED_MESSAGE'] = $this->user->lang('EDITED_TIMES_TOTAL', (int) $row['post_edit_count'], $name,
			$this->user->format_date($row['post_edit_time'], false, true));
		$event['post_row'] = $post;
	}
}
