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

	public function __construct($access, $db, $user, $prefix, $root)
	{
		$this->access = $access;
		$this->db = $db;
		$this->user = $user;
		$this->prefix = $prefix;
		$this->root = $root;
	}

	public function honours($pic, $cat)
	{
		if (!$this->access->may_discuss($pic, $cat, 'rate') ||
			(empty($this->user->data['is_registered']) && !(int) $this->access->setting('hon_rate_users', 1, 'sp'))) { return false; }
		$categories = preg_split('/[^0-9]+/', $this->access->setting('hon_rate_where', '', 'sp'), -1, PREG_SPLIT_NO_EMPTY);
		return !$categories || in_array((int) $cat['cat_id'], array_map('intval', $categories), true);
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

	private function vote($id, $points, $honour)
	{
		if ($points < 1 || $points > $this->scale()) { throw new \DomainException('PLUS_ALBUM_BAD_RATING'); }
		$separate = $honour && (int) $this->access->setting('hon_rate_sep', 1, 'sp');
		$field = $separate ? 'honour_points' : 'points';
		$repeat = $honour && (empty($this->user->data['is_registered']) || (int) $this->access->setting('hon_rate_times', 1, 'sp'));
		$uid = (int) $this->user->data['user_id'];
		$ip = $this->user->ip;
		$where = 'pic_id=' . (int) $id . ' AND user_id=' . $uid;
		if ($uid === ANONYMOUS) { $where .= " AND user_ip='" . $this->db->sql_escape($ip) . "'"; }
		$table = $this->prefix . 'ratings';
		$result = $this->db->sql_query_limit("SELECT rating_id,$field FROM $table WHERE $where ORDER BY $field DESC, rating_id", 1);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		if ($row)
		{
			if (!$repeat && (int) $row[$field] > 0) { throw new \DomainException('PLUS_ALBUM_ALREADY_RATED'); }
			$this->db->sql_query("UPDATE $table SET $field=" . (int) $points . ' WHERE rating_id=' . (int) $row['rating_id']);
		}
		else
		{
			$this->db->sql_query("INSERT INTO $table " . $this->db->sql_build_array('INSERT', array('pic_id' => (int) $id,
				'user_ip' => $ip, 'user_id' => $uid, 'points' => $separate ? 0 : $points, 'honour_points' => $separate ? $points : 0)));
		}
	}

	public function publish($id, $body, $guest, $points, $honour)
	{
		return $this->access->write(function () use ($id, $body, $guest, $points, $honour)
		{
			list($pic, $cat) = $this->access->picture($id);
			if ($honour)
			{
				if (!$this->honours($pic, $cat)) { throw new NotFoundHttpException(); }
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
		list($pic, $cat) = $this->access->picture($picture);
		$table = $this->prefix . 'comments';
		$result = $this->db->sql_query("SELECT * FROM $table WHERE pic_id=" . (int) $picture . ' AND comment_id=' . (int) $id);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		if (!$row || !(int) $this->access->setting('comment', 1) || !$this->access->allows($cat, 'comment') ||
			!$this->access->may_change($cat, $row['author_id'], $mode)) { throw new NotFoundHttpException(); }
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

	public function page_start($picture, $id)
	{
		$result = $this->db->sql_query('SELECT COUNT(*) AS earlier FROM ' . $this->prefix . 'comments c JOIN ' . $this->prefix . 'comments target
			ON target.pic_id=c.pic_id WHERE target.pic_id=' . (int) $picture . ' AND target.comment_id=' . (int) $id . '
			AND (c.created_at<target.created_at OR (c.created_at=target.created_at AND c.comment_id<target.comment_id))');
		$count = (int) $this->db->sql_fetchfield('earlier');
		$this->db->sql_freeresult($result);
		return intdiv($count, 20) * 20;
	}
}
