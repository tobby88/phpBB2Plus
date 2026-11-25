<?php
namespace phpbb2plus\gallery\service;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class pictures
{
	private $access;
	private $db;
	private $prefix;

	public function __construct($access, $db, $prefix)
	{
		$this->access = $access;
		$this->db = $db;
		$this->prefix = $prefix;
	}

	public function allowed($pic, $cat, $action)
	{
		if ($action === 'edit' || $action === 'delete') { return $this->access->may_change($cat, $pic['author_id'], $action); }
		if (!$this->access->moderates($cat)) { return false; }
		if ($action === 'approve' || $action === 'unapprove')
		{
			return (int) $cat['approval'] > 0 && ($this->access->administrator() || (int) $cat['approval'] === 2) &&
				((bool) $pic['approved'] === ($action === 'unapprove'));
		}
		if ($action === 'lock' || $action === 'unlock') { return (bool) $pic['locked'] === ($action === 'unlock'); }
		return $action === 'move';
	}

	public static function original_path($directory, $name)
	{
		if (!$directory || !preg_match('/^gallery_[a-f0-9]{64}$/D', $name)) { return false; }
		$path = $directory . DIRECTORY_SEPARATOR . $name;
		return is_file($path) && !is_link($path) ? $path : false;
	}

	public function context($picture, $action)
	{
		list($pic, $cat) = $this->access->picture($picture);
		if (!$this->allowed($pic, $cat, $action)) { throw new NotFoundHttpException(); }
		return array($pic, $cat);
	}

	private function can_receive($from, $to)
	{
		return $to && (int) $to['cat_id'] !== (int) $from['cat_id'] && (int) $from['owner_id'] === (int) $to['owner_id'] &&
			(int) $from['source_owner'] === (int) $to['source_owner'] && $this->access->visible($to) && $this->access->allows($to, 'upload') &&
			((int) $from['owner_id'] !== ANONYMOUS || (int) $from['source_owner'] || $this->root_id($from) === $this->root_id($to));
	}

	private function root_id($cat)
	{
		// Both endpoints have passed the visibility check, including their ancestor chain.
		while ((int) $cat['parent_id']) { $cat = $this->access->category_row($cat['parent_id']); }
		return (int) $cat['cat_id'];
	}

	public function destinations($cat)
	{
		$result = $this->db->sql_query('SELECT * FROM ' . $this->prefix . 'cats WHERE owner_id=' . (int) $cat['owner_id'] .
			' AND source_owner=' . (int) $cat['source_owner'] . ' ORDER BY sort_order, cat_id');
		$rows = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);
		return array_map(array($this->access, 'category_labels'), array_filter($rows, function ($to) use ($cat) { return $this->can_receive($cat, $to); }));
	}

	public function change($picture, $action, $title, $description, $destination)
	{
		return $this->access->write(function () use ($picture, $action, $title, $description, $destination)
		{
			list($pic, $cat) = $this->context($picture, $action);
			$id = (int) $pic['pic_id'];
			$where = ' WHERE pic_id=' . $id;
			if ($action === 'delete')
			{
				$this->discard($pic);
				return (int) $cat['cat_id'];
			}
			if ($action === 'edit')
			{
				if ($title === '' || utf8_strlen($title) > 255 || utf8_strlen($description) > max(0,min(65535,(int) $this->access->setting('desc_length', 512))))
				{ throw new \DomainException('PLUS_ALBUM_BAD_TEXT'); }
				$fields = array('title' => $title, 'description' => $description);
			}
			elseif ($action === 'move')
			{
				$to = $this->access->category($destination);
				if (!$this->can_receive($cat, $to)) { throw new NotFoundHttpException(); }
				$fields = array('cat_id' => (int) $to['cat_id'], 'legacy_uncategorized' => 0);
				$this->db->sql_query('UPDATE ' . $this->prefix . 'comments SET cat_id=' . (int) $to['cat_id'] . $where);
			}
			elseif ($action === 'lock' || $action === 'unlock') { $fields = array('locked' => $action === 'lock' ? 1 : 0); }
			else { $fields = array('approved' => $action === 'approve' ? 1 : 0); }
			$this->db->sql_query('UPDATE ' . $this->prefix . 'pics SET ' . $this->db->sql_build_array('UPDATE', $fields) . $where);
			return 0;
		});
	}

	/** Called while the gallery write lock is held, after authorizing picture or category deletion. */
	public function discard($pic)
	{
		$name = $pic['storage_name'];
		if (preg_match('/^gallery_[a-f0-9]{64}$/D', $name))
		{
			$table = $this->prefix . 'trash';
			$result = $this->db->sql_query("SELECT storage_name FROM $table WHERE storage_name='$name'");
			$queued = $this->db->sql_fetchfield('storage_name') !== false;
			$this->db->sql_freeresult($result);
			if (!$queued) { $this->db->sql_query("INSERT INTO $table " . $this->db->sql_build_array('INSERT', array('storage_name' => $name, 'removed_at' => time()))); }
		}
		$where = ' WHERE pic_id=' . (int) $pic['pic_id'];
		foreach (array('comments','ratings','pics') as $table) { $this->db->sql_query('DELETE FROM ' . $this->prefix . $table . $where); }
	}
}
