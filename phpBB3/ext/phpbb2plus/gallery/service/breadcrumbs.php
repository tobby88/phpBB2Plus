<?php
namespace phpbb2plus\gallery\service;

class breadcrumbs
{
	private $access;
	private $template;
	private $helper;
	private $language;

	public function __construct($access, $template, $helper, $language)
	{
		$this->access = $access;
		$this->template = $template;
		$this->helper = $helper;
		$this->language = $language;
	}

	public function assign($cat = null, $listing = array())
	{
		$this->language->add_lang('gallery', 'phpbb2plus/gallery');
		$this->template->assign_block_vars('navlinks', array('FORUM_NAME' => $this->language->lang('PLUS_ALBUM'),
			'U_VIEW_FORUM' => $this->helper->route('phpbb2plus_gallery_index')));
		$current = $cat ? (int) $cat['cat_id'] : 0;
		$parents = array();
		while ($cat)
		{
			$parents[] = $cat;
			$cat = $cat['parent_id'] ? $this->access->category($cat['parent_id']) : null;
		}
		foreach (array_reverse($parents) as $category)
		{
			$params = array('category' => $category['cat_id']) + ((int) $category['cat_id'] === $current ? $listing : array());
			$this->template->assign_block_vars('navlinks', array('FORUM_NAME' => utf8_htmlspecialchars($category['title']),
				'U_VIEW_FORUM' => $this->helper->route('phpbb2plus_gallery_category', $params)));
		}
	}
}
