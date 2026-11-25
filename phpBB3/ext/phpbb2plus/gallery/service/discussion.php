<?php
namespace phpbb2plus\gallery\service;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class discussion
{
	private $access;
	private $db;
	private $user;
	private $prefix;
	private $root;
	private $config;

	public function __construct($access, $db, $user, $prefix, $root, $config)
	{
		$this->access = $access;
		$this->db = $db;
		$this->user = $user;
		$this->prefix = $prefix;
		$this->root = $root;
		$this->config = $config;
	}

	private function honour_categories()
	{
		$ids = preg_split('/[^0-9]+/', $this->access->setting('hon_rate_where', '', 'sp'), -1, PREG_SPLIT_NO_EMPTY);
		return array_flip(array_filter(array_map('intval', $ids)));
	}

	private function rating_field($honour)
	{
		return $honour && (int) $this->access->setting('hon_rate_sep', 1, 'sp') ? 'honour_points' : 'points';
	}

	private function repeat_honours()
	{
		return empty($this->user->data['is_registered']) || (int) $this->access->setting('hon_rate_times', 1, 'sp');
	}

	private function eligible($pic, $cat)
	{
		$categories = $this->honour_categories();
		return !$pic['legacy_uncategorized'] && (!$categories || isset($categories[$cat['cat_id']])) &&
			$this->access->may_discuss($pic, $cat, 'rate') &&
			(!empty($this->user->data['is_registered']) || (int) $this->access->setting('hon_rate_users', 1, 'sp')) &&
			pictures::original_path(realpath($this->root . $this->config['upload_path']), $pic['storage_name']);
	}

	public function honour_status($pic, $cat)
	{
		if (!$this->eligible($pic, $cat)) { return 'unavailable'; }
		if (!$this->repeat_honours())
		{
			$field = $this->rating_field(true);
			$row = $this->ballot($pic['pic_id'], $field);
			if ($row && (int) $row[$field] > 0) { return 'rated'; }
		}
		return 'open';
	}

	public function draw()
	{
		$categories = $this->access->portal_categories(true, $this->honour_categories());
		$where = $this->access->picture_filter($categories) . " AND p.legacy_uncategorized=0 AND p.storage_name<>''";
		$from = ' FROM ' . $this->prefix . 'pics p WHERE ' . $where;
		$result = $this->db->sql_query('SELECT COUNT(*) AS count' . $from);
		$count = (int) $this->db->sql_fetchfield('count');
		$this->db->sql_freeresult($result);
		if (!$count) { return null; }
		$result = $this->db->sql_query_limit('SELECT p.*,u.username,u.user_colour FROM ' . $this->prefix . 'pics p
			LEFT JOIN ' . USERS_TABLE . ' u ON u.user_id=p.author_id WHERE ' . $where . ' ORDER BY p.pic_id', 1, random_int(0, $count - 1));
		$pic = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		if (!$pic) { return null; }
		if (!pictures::original_path(realpath($this->root . $this->config['upload_path']), $pic['storage_name'])) { throw new NotFoundHttpException(); }
		return array($pic, $this->access->category($pic['cat_id']));
	}

	public function rating($id, $honour)
	{
		$field = $this->rating_field($honour);
		$result = $this->db->sql_query("SELECT COUNT(*) AS votes, AVG($field) AS rating FROM " . $this->prefix . "ratings WHERE $field>0 AND pic_id=" . (int) $id);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		return $row;
	}

	public function scale()
	{
		return max(1, min(255, (int) $this->access->setting('rate_scale', 10)));
	}

	public function text_limit()
	{
		return max(1, min(65535, (int) $this->access->setting('desc_length', 512)));
	}

	private function validate_body($body)
	{
		if ($body === '' || utf8_strlen($body) > $this->text_limit()) { throw new \DomainException('PLUS_ALBUM_COMMENT_LENGTH'); }
	}

	private function ballot($id, $field)
	{
		$uid = (int) $this->user->data['user_id'];
		$where = 'pic_id=' . (int) $id . ' AND user_id=' . $uid;
		if ($uid === ANONYMOUS) { $where .= " AND user_ip='" . $this->db->sql_escape($this->user->ip) . "'"; }
		$table = $this->prefix . 'ratings';
		$result = $this->db->sql_query_limit("SELECT rating_id,$field FROM $table WHERE $where ORDER BY $field DESC, rating_id", 1);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		return $row;
	}

	private function vote($id, $points, $honour)
	{
		if ($points < 1 || $points > $this->scale()) { throw new \DomainException('PLUS_ALBUM_BAD_RATING'); }
		$field = $this->rating_field($honour);
		$repeat = $honour && $this->repeat_honours();
		$table = $this->prefix . 'ratings';
		$row = $this->ballot($id, $field);
		if ($row)
		{
			if (!$repeat && (int) $row[$field] > 0) { throw new \DomainException('PLUS_ALBUM_ALREADY_RATED'); }
			$this->db->sql_query("UPDATE $table SET $field=" . (int) $points . ' WHERE rating_id=' . (int) $row['rating_id']);
		}
		else
		{
			$this->db->sql_query("INSERT INTO $table " . $this->db->sql_build_array('INSERT', array('pic_id' => (int) $id,
				'user_ip' => $this->user->ip, 'user_id' => (int) $this->user->data['user_id'], 'points' => $field === 'points' ? $points : 0,
				'honour_points' => $field === 'honour_points' ? $points : 0)));
		}
	}

	public function publish($id, $body, $guest, $points, $honour)
	{
		return $this->access->write(function () use ($id, $body, $guest, $points, $honour)
		{
			list($pic, $cat) = $this->access->picture($id);
			if ($honour)
			{
				if (!$this->eligible($pic, $cat)) { throw new NotFoundHttpException(); }
				$this->vote($id, $points, true);
				return 0;
			}
			if (!$points && $body === '') { throw new \DomainException('PLUS_ALBUM_COMMENT_LENGTH'); }
			if ($points)
			{
				if (!$this->access->may_discuss($pic, $cat, 'rate')) { throw new NotFoundHttpException(); }
				$this->vote($id, $points, false);
			}
			if ($body === '') { return 0; }
			if (!$this->access->may_discuss($pic, $cat, 'comment')) { throw new NotFoundHttpException(); }
			$this->validate_body($body);
			$guest = empty($this->user->data['is_registered']) ? $guest : '';
			if ($guest !== '')
			{
				if (!function_exists('validate_username')) { include_once $this->root . 'includes/functions_user.php'; }
				if (utf8_strlen($guest) > 32 || validate_username(utf8_htmlspecialchars($guest)) !== false) { throw new \DomainException('PLUS_ALBUM_BAD_NAME'); }
			}
			$this->db->sql_query('INSERT INTO ' . $this->prefix . 'comments ' . $this->db->sql_build_array('INSERT',
				array('pic_id' => (int) $pic['pic_id'], 'cat_id' => (int) $cat['cat_id'], 'author_id' => (int) $this->user->data['user_id'],
					'author_name' => $guest, 'author_ip' => $this->user->ip, 'created_at' => time(), 'body' => $body)));
			return (int) $this->db->sql_nextid();
		});
	}

	public function comment($picture, $id, $mode)
	{
		$table = $this->prefix . 'comments';
		$result = $this->db->sql_query("SELECT * FROM $table WHERE comment_id=" . (int) $id .
			($picture === null ? '' : ' AND pic_id=' . (int) $picture));
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		if (!$row || !(int) $this->access->setting('comment', 1)) { throw new NotFoundHttpException(); }
		list($pic, $cat) = $this->access->picture($row['pic_id']);
		if (!$this->access->allows($cat, 'comment') || !$this->access->may_change($cat, $row['author_id'], $mode)) { throw new NotFoundHttpException(); }
		return $row;
	}

	public function change($picture, $id, $mode, $body)
	{
		$this->access->write(function () use ($picture, $id, $mode, $body)
		{
			$row = $this->comment($picture, $id, $mode);
			$table = $this->prefix . 'comments';
			$where = ' WHERE comment_id=' . (int) $id . ' AND pic_id=' . (int) $picture;
			if ($mode === 'delete') { $this->db->sql_query("DELETE FROM $table" . $where); return; }
			$this->validate_body($body);
			if ($body !== $row['body'])
			{
				$update = array('body' => $body, 'edited_at' => time(), 'editor_id' => (int) $this->user->data['user_id'],
					'edit_count' => min(65535, (int) $row['edit_count'] + 1));
				$this->db->sql_query("UPDATE $table SET " . $this->db->sql_build_array('UPDATE', $update) . $where);
			}
		});
	}

	public function per_page()
	{
		return max(1, min(9999, (int) $this->config['posts_per_page']));
	}

	public function page_start($picture, $id, $order = 'ASC')
	{
		$result = $this->db->sql_query('SELECT COUNT(*) AS earlier FROM ' . $this->prefix . 'comments WHERE pic_id=' . (int) $picture .
			' AND comment_id' . ($order === 'DESC' ? '>' : '<') . (int) $id);
		$count = (int) $this->db->sql_fetchfield('earlier');
		$this->db->sql_freeresult($result);
		$limit = $this->per_page();
		return intdiv($count, $limit) * $limit;
	}
}
