<?php
namespace phpbb2plus\community\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class bylines implements EventSubscriberInterface
{
	private $db;
	private $config;
	private $user;
	private $table;

	public function __construct($db, $config, $user, $table)
	{
		$this->db = $db;
		$this->config = $config;
		$this->user = $user;
		$this->table = $table;
	}

	static public function getSubscribedEvents()
	{
		return array('core.viewtopic_modify_post_data' => 'show_bylines');
	}

	public function show_bylines($event)
	{
		if (!$event['post_list'] || $this->config['plus_hide_former_names'] && empty($this->user->data['is_registered']))
		{
			return;
		}
		// Limit the lookup to the posts phpBB already selected for this viewer.
		$rows = $event['rowset'];
		$result = $this->db->sql_query('SELECT post_id, author_name FROM ' . $this->table . '
			WHERE ' . $this->db->sql_in_set('post_id', array_map('intval', $event['post_list'])));
		while ($byline = $this->db->sql_fetchrow($result))
		{
			$id = (int) $byline['post_id'];
			if (isset($rows[$id]) && (int) $rows[$id]['user_id'] === ANONYMOUS && $byline['author_name'] !== '')
			{
				$rows[$id]['post_username'] = $byline['author_name'];
			}
		}
		$this->db->sql_freeresult($result);
		$event['rowset'] = $rows;
	}
}
