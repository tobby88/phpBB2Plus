<?php
namespace phpbb2plus\knowledgebase\service;

class categories
{
	private $articles;
	private $editor;
	private $publication;
	private $db;
	private $auth;
	private $prefix;

	public function __construct($articles, $editor, $publication, $db, $auth, $prefix)
	{
		$this->articles = $articles;
		$this->editor = $editor;
		$this->publication = $publication;
		$this->db = $db;
		$this->auth = $auth;
		$this->prefix = $prefix;
	}

	public function tree()
	{
		$categories = $this->articles->category_list();
		$children = array();
		foreach ($categories as $id => $row)
		{
			if ($id <= 0) { throw new \DomainException('ACP_PLUS_KB_TREE_INVALID'); }
			$children[(int) $row['parent_id']][] = $id;
		}
		$tree = array();
		$stack = array_reverse($children[0] ?? array());
		while ($stack)
		{
			$id = array_pop($stack);
			$row = $categories[$id];
			$row['depth'] = $row['parent_id'] ? $tree[(int) $row['parent_id']]['depth'] + 1 : 0;
			$row['children'] = count($children[$id] ?? array());
			$tree[$id] = $row;
			foreach (array_reverse($children[$id] ?? array()) as $child) { $stack[] = $child; }
		}
		if (count($categories) !== count($tree)) { throw new \DomainException('ACP_PLUS_KB_TREE_INVALID'); }
		return $tree;
	}

	public function counts()
	{
		$result = $this->db->sql_query('SELECT cat_id,COUNT(*) AS total FROM ' . $this->prefix . 'articles GROUP BY cat_id');
		$counts = array();
		while ($row = $this->db->sql_fetchrow($result)) { $counts[(int) $row['cat_id']] = (int) $row['total']; }
		$this->db->sql_freeresult($result);
		return $counts;
	}

	public function review($tree, $action, $id)
	{
		if ($action !== 'create' && !isset($tree[$id])) { throw new \DomainException('ACP_PLUS_KB_CAT_CONFLICT'); }
		$hash = hash_init('sha256');
		foreach ($tree as $row)
		{
			hash_update($hash, serialize(array($row['cat_id'], $row['parent_id'], $row['sort_order'], $row['title'], $row['description'])));
		}
		$count = 0;
		if ($action === 'delete')
		{
			$result = $this->db->sql_query('SELECT article_id,revision,type_id,topic_id FROM ' . $this->prefix . 'articles WHERE cat_id=' . (int) $id . ' ORDER BY article_id');
			while ($row = $this->db->sql_fetchrow($result)) { hash_update($hash, serialize($row)); ++$count; }
			$this->db->sql_freeresult($result);
		}
		return array('state' => hash_final($hash), 'count' => $count);
	}

	private function order($siblings, $tree)
	{
		foreach ($siblings as $index => $id)
		{
			$order = ($index + 1) * 10;
			if ((int) $tree[$id]['sort_order'] !== $order)
			{ $this->db->sql_query('UPDATE ' . $this->prefix . 'cats SET sort_order=' . $order . ' WHERE cat_id=' . (int) $id); }
		}
	}

	public function change($action, $id, $input, $state)
	{
		if (!$this->auth->acl_gets('a_plus_kb', 'a_plus_kb_categories')) { throw new \DomainException('NO_AUTH_OPERATION'); }
		$this->editor->locked(function () use ($action, $id, $input, $state)
		{
			$tree = $this->tree();
			$review = $this->review($tree, $action, $id);
			if (!hash_equals($review['state'], $state)) { throw new \DomainException('ACP_PLUS_KB_CAT_CONFLICT'); }
			$table = $this->prefix . 'cats';
			$parent = $id ? (int) $tree[$id]['parent_id'] : 0;
			switch ($action)
			{
				case 'create':
				case 'edit':
					if ($input['title'] === '' || utf8_strlen($input['title']) > 255 || utf8_strlen($input['description']) > 255)
					{ throw new \DomainException('ACP_PLUS_KB_CAT_TEXT_INVALID'); }
					if ($input['parent'] !== '0' && !isset($tree[$input['parent']])) { throw new \DomainException('ACP_PLUS_KB_CAT_PARENT_INVALID'); }
					$parent = (int) $input['parent'];
					for ($ancestor = $parent; $ancestor; $ancestor = (int) $tree[$ancestor]['parent_id'])
					{
						if ($ancestor === $id) { throw new \DomainException('ACP_PLUS_KB_CAT_PARENT_INVALID'); }
					}
					$fields = array('parent_id' => $parent, 'title' => articles::encode_label($input['title']),
						'description' => articles::encode_label($input['description']), 'label_encoded' => 1);
					if ($action === 'edit') { $this->db->sql_query("UPDATE $table SET " . $this->db->sql_build_array('UPDATE', $fields) . ' WHERE cat_id=' . (int) $id); return; }
					$last = 0;
					foreach ($tree as $cat) { if ((int) $cat['parent_id'] === $parent) { $last = max($last, (int) $cat['sort_order']); } }
					if ($last > 16777205) { throw new \DomainException('ACP_PLUS_KB_CAT_ORDER_LIMIT'); }
					$fields['sort_order'] = $last + 10;
					$this->db->sql_query("INSERT INTO $table " . $this->db->sql_build_array('INSERT', $fields));
					return;
				case 'delete':
					$dest = $input['destination'];
					if ($dest === (string) $id || ($dest !== '0' && !isset($tree[$dest]))) { throw new \DomainException('ACP_PLUS_KB_CAT_DEST_INVALID'); }
					$articles = $this->prefix . 'articles';
					if ($dest === '0')
					{
						do
						{
							$result = $this->db->sql_query_limit("SELECT * FROM $articles WHERE cat_id=" . (int) $id . ' ORDER BY article_id', 25);
							$rows = $this->db->sql_fetchrowset($result);
							$this->db->sql_freeresult($result);
							foreach ($rows as $article) { $this->publication->remove_article($article); }
						}
						while (count($rows) === 25);
					}
					else { $this->db->sql_query("UPDATE $articles SET cat_id=" . (int) $dest . ' WHERE cat_id=' . (int) $id); }
					$this->db->sql_query("UPDATE $table SET parent_id=$parent WHERE parent_id=" . (int) $id);
					$this->db->sql_query("DELETE FROM $table WHERE cat_id=" . (int) $id);
					return;
				case 'up':
				case 'down':
					$siblings = array();
					foreach ($tree as $cat_id => $cat) { if ((int) $cat['parent_id'] === $parent) { $siblings[] = $cat_id; } }
					$at = array_search($id, $siblings, true);
					$next = $at + ($action === 'up' ? -1 : 1);
					if ($at === false || !isset($siblings[$next])) { throw new \DomainException('ACP_PLUS_KB_CAT_CONFLICT'); }
					$siblings[$at] = $siblings[$next];
					$siblings[$next] = $id;
					$this->order($siblings, $tree);
					return;
			}
			throw new \DomainException('FORM_INVALID');
		});
	}
}
