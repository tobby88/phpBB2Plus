<?php
namespace phpbb2plus\community\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class forum_hierarchy implements EventSubscriberInterface
{
	private $settings, $language;
	private $root, $mode, $split;
	private $parents = array(), $categories = array(), $depth = array(), $occupied = array();
	private $rows_started = false;

	public function __construct($settings, $language) { $this->settings = $settings; $this->language = $language; }
	public static function getSubscribedEvents()
	{
		return array('core.display_forums_modify_sql' => 'reset', 'core.display_forums_modify_row' => 'branch',
			'core.display_forums_modify_forum_rows' => 'visible', 'core.display_forums_before' => array('prepare', 10),
			'core.display_forums_modify_category_template_vars' => 'category', 'core.display_forums_modify_template_vars' => 'row');
	}
	public function reset()
	{
		$this->root = null;
		$this->mode = $this->settings->enabled() ? $this->settings->get('sub_forum') : null;
		$this->split = $this->settings->get('split_cat');
		$this->rows_started = false;
		$this->parents = $this->categories = $this->depth = $this->occupied = array();
	}
	public function branch($event)
	{
		if ($this->mode === null) { return; }
		$row = $event['row'];
		$id = (int) $row['forum_id'];
		$parent = (int) $row['parent_id'];
		if ($this->root === null)
		{
			$this->root = (int) $event['branch_root_id'];
			// Opening a category expands its first level, including from a packed index.
			if ($this->root && $this->mode === 2) { $this->mode = 1; }
		}
		if ($this->mode === 0) { $event['branch_root_id'] = $parent; }
		if ((int) $row['forum_type'] !== FORUM_CAT) { return; }
		$this->categories[$id] = true;
		if ((int) $row['left_id'] + 1 >= (int) $row['right_id'] || ($this->mode !== 2 && $parent === $this->root)) { return; }
		// The native forum builder can now aggregate permitted descendants, counts, read state and last posts.
		$row['forum_type'] = FORUM_POST;
		$row['display_subforum_limit'] = 1;
		$event['row'] = $row;
	}
	public function visible($event)
	{
		if ($this->mode === null) { return; }
		$row = $event['row'];
		$id = (int) $row['forum_id'];
		$parent = $this->parents[$id] = (int) $row['parent_id'];
		$this->depth[$id] = isset($this->depth[$parent]) ? $this->depth[$parent] + 1 : 0;
	}
	public function prepare($event)
	{
		if ($this->mode === null) { return; }
		// "visible" runs only after phpBB has accepted the row and all its ancestors.
		foreach (array_reverse($this->parents, true) as $id => $parent)
		{
			if (!isset($this->categories[$id]) || isset($this->occupied[$id])) { $this->occupied[$parent] = true; }
		}
		$rows = $event['forum_rows'];
		foreach ($this->categories as $id => $unused)
		{
			if (!isset($this->occupied[$id])) { unset($rows[$id]); }
		}
		$event['forum_rows'] = $rows;
	}
	public function category($event)
	{
		if ($this->mode === null) { return; }
		$this->rows_started = true;
		if ($this->root || $this->split) { return; }
		$category = $event['cat_row'];
		$category['S_IS_CAT'] = false;
		$category['S_IS_LINK'] = true;
		$category['FORUM_IMG_STYLE'] = 'plus-category-heading';
		$event['cat_row'] = $category;
	}
	public function row($event)
	{
		if ($this->mode === null) { return; }
		$row = $event['forum_row'];
		$id = (int) $row['FORUM_ID'];
		if (!$this->root && !$this->split) { $row['S_NO_CAT'] = false; }
		elseif ($this->mode === 2 && isset($this->categories[$id])) { $row['S_NO_CAT'] = $this->rows_started; }
		$this->rows_started = true;
		if ($this->mode === 0 && !empty($this->depth[$id]) && $this->depth[$id] > 1)
		{
			$indent = min(6, $this->depth[$id]-1);
			$row['FORUM_IMAGE'] = '<span class="plus-forum-indent" aria-hidden="true" style="width:' . $indent . 'em"></span>' . $row['FORUM_IMAGE'];
		}
		// Packed category links use the native unread markers and destinations. Empty private branches disappear.
		$subforums = $event['subforums_row'];
		$filtered = array();
		foreach ($subforums as $sub)
		{
			$query = parse_url(html_entity_decode($sub['U_SUBFORUM'], ENT_QUOTES, 'UTF-8'), PHP_URL_QUERY);
			parse_str((string) $query, $params);
			$sub_id = (int) ($params['f'] ?? 0);
			if (!isset($this->categories[$sub_id]) || isset($this->occupied[$sub_id])) { $filtered[] = $sub; }
		}
		if (count($filtered) !== count($subforums))
		{
			$event['subforums_row'] = $filtered;
			$row['S_SUBFORUMS'] = (bool) $filtered;
			$links = array();
			foreach ($filtered as $sub)
			{
				$state = $sub['S_UNREAD'] ? 'unread' : 'read';
				$label = $this->language->lang($sub['S_UNREAD'] ? 'UNREAD_POSTS' : 'NO_UNREAD_POSTS');
				$links[] = '<a href="' . $sub['U_SUBFORUM'] . '" class="subforum ' . $state . '" title="' . $label . '">' . $sub['SUBFORUM_NAME'] . '</a>';
			}
			$row['SUBFORUMS'] = implode($this->language->lang('COMMA_SEPARATOR'), $links);
			$row['L_SUBFORUM_STR'] = $this->language->lang(count($filtered) === 1 ? 'SUBFORUM' : 'SUBFORUMS');
		}
		$event['forum_row'] = $row;
	}
}
