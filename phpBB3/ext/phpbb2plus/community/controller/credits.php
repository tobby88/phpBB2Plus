<?php
namespace phpbb2plus\community\controller;

class credits
{
	private $db;
	private $table;
	private $extensions;
	private $helper;
	private $template;
	private $language;
	private $request;
	private $pagination;

	public function __construct($db, $table, $extensions, $helper, $template, $language, $request, $pagination)
	{
		$this->db = $db;
		$this->table = $table;
		$this->extensions = $extensions;
		$this->helper = $helper;
		$this->template = $template;
		$this->language = $language;
		$this->request = $request;
		$this->pagination = $pagination;
	}

	private function installed()
	{
		$enabled = $this->extensions->all_enabled();
		ksort($enabled);
		foreach (array_keys($enabled) as $name)
		{
			if (strpos($name, 'phpbb2plus/') !== 0) { continue; }
			$entry = array('NAME' => $name, 'VERSION' => '', 'AUTHORS' => '');
			try
			{
				$package = $this->extensions->create_extension_metadata_manager($name);
				$data = $package->get_metadata();
				// The metadata manager HTML-encodes its values before returning them.
				$entry['NAME'] = htmlspecialchars_decode($package->get_metadata('display-name'), ENT_QUOTES);
				$entry['VERSION'] = htmlspecialchars_decode($data['version'] ?? '', ENT_QUOTES);
				$entry['AUTHORS'] = htmlspecialchars_decode(implode(', ', array_column($data['authors'] ?? array(), 'name')), ENT_QUOTES);
			}
			catch (\phpbb\extension\exception $e)
			{
				// The extension is enabled even when its local composer metadata is incomplete.
			}
			$this->template->assign_block_vars('plus_packages', $entry);
		}
	}

	private static function display($value)
	{
		$value = html_entity_decode((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		// Some phpBB2 distributions stored quoted strings with an extra slash.
		return strtr($value, array("\\'" => "'", '\\"' => '"', '\\\\' => '\\'));
	}

	private static function web_url($value)
	{
		$value = self::display($value);
		return preg_match('~^https?://~i', $value) && filter_var($value, FILTER_VALIDATE_URL) ? $value : '';
	}

	public function show()
	{
		$this->language->add_lang('community', 'phpbb2plus/community');
		$this->installed();
		$result = $this->db->sql_query('SELECT COUNT(*) AS total FROM ' . $this->table . ' WHERE visible=1');
		$count = (int) $this->db->sql_fetchfield('total');
		$this->db->sql_freeresult($result);
		if ($count)
		{
			$url = $this->helper->route('phpbb2plus_community_credits');
			$limit = 30;
			$start = $this->pagination->validate_start($this->request->variable('start', 0), $limit, $count);
			$this->pagination->generate_template_pagination($url, 'pagination', 'start', $count, $limit, $start);
			$result = $this->db->sql_query_limit('SELECT credit_id,title,description,author,contact_email,author_website,version_text,download_url
				FROM ' . $this->table . ' WHERE visible=1 ORDER BY title,credit_id', $limit, $start);
			while ($row = $this->db->sql_fetchrow($result))
			{
				$email = self::display($row['contact_email']);
				$this->template->assign_block_vars('archive_credits', array('ID' => (int) $row['credit_id'],
					'NAME' => self::display($row['title']), 'VERSION' => self::display($row['version_text']),
					'DESCRIPTION' => self::display($row['description']), 'AUTHOR' => self::display($row['author']),
					'EMAIL' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '',
					'WEBSITE' => self::web_url($row['author_website']), 'DOWNLOAD' => self::web_url($row['download_url'])));
			}
			$this->db->sql_freeresult($result);
		}
		return $this->helper->render('@phpbb2plus_community/credits.html', $this->language->lang('PLUS_CREDITS'));
	}
}
