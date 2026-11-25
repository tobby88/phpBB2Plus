<?php
namespace phpbb2plus\downloads\event;

use phpbb2plus\downloads\service\access;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class portal implements EventSubscriberInterface
{
	private $access;
	private $db;
	private $config;
	private $template;
	private $helper;
	private $language;
	private $files;

	public function __construct($access, $db, $config, $template, $helper, $language, $files)
	{
		$this->access = $access;
		$this->db = $db;
		$this->config = $config;
		$this->template = $template;
		$this->helper = $helper;
		$this->language = $language;
		$this->files = $files;
	}

	public static function getSubscribedEvents() { return array('phpbb2plus.community.portal_content'=>'recent'); }

	public function recent()
	{
		$limit = min(100, (int) $this->config['plus_portal_downloads']);
		if ($limit <= 0 || !$this->access->enabled()) { return; }
		$allowed = array();
		foreach (array_keys($this->access->categories()) as $id)
		{
			if ($this->access->visible($id, true) && $this->access->allows($id, 'download')) { $allowed[] = $id; }
		}
		if (!$allowed) { return; }
		$this->language->add_lang('downloads', 'phpbb2plus/downloads');
		$this->template->assign_var('U_PORTAL_DOWNLOADS', $this->helper->route('phpbb2plus_downloads_index'));
		$result = $this->db->sql_query_limit('SELECT file_id,title FROM ' . $this->files . ' WHERE approved=1 AND ' .
			$this->db->sql_in_set('cat_id', $allowed) . ' ORDER BY added_at DESC,file_id DESC', $limit);
		while ($file = $this->db->sql_fetchrow($result))
		{
			$this->template->assign_block_vars('plus_downloads', array('TITLE'=>access::plain($file['title']),
				'U_FILE'=>$this->helper->route('phpbb2plus_downloads_file', array('file'=>(int) $file['file_id']))));
		}
		$this->db->sql_freeresult($result);
	}
}
