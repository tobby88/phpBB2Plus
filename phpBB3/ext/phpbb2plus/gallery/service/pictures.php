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

	public function destination($cat, $id)
	{
		$to = $this->access->category($id);
		if (!$this->can_receive($cat, $to)) { throw new NotFoundHttpException(); }
		return $to;
	}

	public function change($picture, $action, $title, $description, $destination)
	{
		return $this->access->write(function () use ($picture, $action, $title, $description, $destination)
		{
			list($pic, $cat) = $this->context($picture, $action);
			if ($action === 'edit')
			{
				if ($title === '' || utf8_strlen($title) > 255 || utf8_strlen($description) > max(0,min(65535,(int) $this->access->setting('desc_length', 512))))
				{ throw new \DomainException('PLUS_ALBUM_BAD_TEXT'); }
				$fields = array('title' => $title, 'description' => $description);
				$this->db->sql_query('UPDATE ' . $this->prefix . 'pics SET ' . $this->db->sql_build_array('UPDATE', $fields) . ' WHERE pic_id=' . (int) $pic['pic_id']);
			}
			else
			{
				$to = $action === 'move' ? $this->destination($cat, $destination) : null;
				$this->apply_moderation(array($pic), $action, $to);
			}
			return $action === 'delete' ? (int) $cat['cat_id'] : 0;
		});
	}

	/** Only after checking every source picture and destination under the gallery write lock. */
	public function apply_moderation($rows, $action, $to)
	{
		if ($action === 'delete')
		{
			$this->discard_many($rows);
			return;
		}
		$where = ' WHERE ' . $this->db->sql_in_set('pic_id', array_map('intval', array_column($rows, 'pic_id')));
		switch ($action)
		{
			case 'move':
				$fields = array('cat_id' => (int) $to['cat_id'], 'legacy_uncategorized' => 0);
				$this->db->sql_query('UPDATE ' . $this->prefix . 'comments SET cat_id=' . (int) $to['cat_id'] . $where);
				break;
			case 'lock': case 'unlock': $fields = array('locked' => (int) ($action === 'lock')); break;
			case 'approve': case 'unapprove': $fields = array('approved' => (int) ($action === 'approve')); break;
			default: throw new \InvalidArgumentException('Unknown moderation action');
		}
		$this->db->sql_query('UPDATE ' . $this->prefix . 'pics SET ' . $this->db->sql_build_array('UPDATE', $fields) . $where);
	}

	/** Called while the gallery write lock is held, after authorizing picture or category deletion. */
	public function discard($pic)
	{
		$this->discard_many(array($pic));
	}

	private function discard_many($rows)
	{
		$names = array();
		foreach ($rows as $pic)
		{
			$name = $pic['storage_name'];
			if (preg_match('/^gallery_[a-f0-9]{64}$/D', $name)) { $names[$name] = $name; }
		}
		if ($names)
		{
			$table = $this->prefix . 'trash';
			$result = $this->db->sql_query("SELECT storage_name FROM $table WHERE " . $this->db->sql_in_set('storage_name', $names));
			while ($queued = $this->db->sql_fetchrow($result)) { unset($names[$queued['storage_name']]); }
			$this->db->sql_freeresult($result);
			$time = time();
			$new = array();
			foreach ($names as $name) { $new[] = array('storage_name' => $name, 'removed_at' => $time); }
			if ($new) { $this->db->sql_multi_insert($table, $new); }
		}
		$where = ' WHERE ' . $this->db->sql_in_set('pic_id', array_map('intval', array_column($rows, 'pic_id')));
		foreach (array('comments','ratings','pics') as $table) { $this->db->sql_query('DELETE FROM ' . $this->prefix . $table . $where); }
	}
}
