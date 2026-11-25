<?php
namespace phpbb2plus\community\controller;

use phpbb2plus\community\service\credit_entries;

class credits
{
	private $entries;
	private $extensions;
	private $helper;
	private $template;
	private $language;
	private $request;
	private $pagination;

	public function __construct($entries, $extensions, $helper, $template, $language, $request, $pagination)
	{
		$this->entries = $entries;
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

	public function show()
	{
		$this->language->add_lang('community', 'phpbb2plus/community');
		$this->installed();
		$count = $this->entries->count(true);
		if ($count)
		{
			$url = $this->helper->route('phpbb2plus_community_credits');
			$limit = 30;
			$start = $this->pagination->validate_start($this->request->variable('start', 0), $limit, $count);
			$this->pagination->generate_template_pagination($url, 'pagination', 'start', $count, $limit, $start);
			foreach ($this->entries->listing(true, $limit, $start) as $row)
			{
				$data = $this->entries->values($row);
				$email = $data['contact_email'];
				$this->template->assign_block_vars('archive_credits', array('ID' => (int) $row['credit_id'],
					'NAME' => $data['title'], 'VERSION' => $data['version_text'], 'DESCRIPTION' => $data['description'], 'AUTHOR' => $data['author'],
					'EMAIL' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '',
					'WEBSITE' => credit_entries::url($data['author_website']), 'DOWNLOAD' => credit_entries::url($data['download_url'])));
			}
		}
		return $this->helper->render('@phpbb2plus_community/credits.html', $this->language->lang('PLUS_CREDITS'));
	}
}
