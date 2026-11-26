<?php
namespace phpbb2plus\arcade\service;

use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class comments
{
	private $db;
	private $catalog;
	private $user;
	private $auth;
	private $config;
	private $prefix;
	private $messages;

	public function __construct($db,$catalog,$user,$auth,$config,$prefix,$messages)
	{
		$this->db = $db;
		$this->catalog = $catalog;
		$this->user = $user;
		$this->auth = $auth;
		$this->config = $config;
		$this->prefix = $prefix;
		$this->messages = $messages;
	}

	public function game($id)
	{
		$game = $this->catalog->game($id);
		$game['comment_limit'] = $this->catalog->comment_limit($game);
		if (!$game['comment_limit']) { throw new NotFoundHttpException(); }
		return $game;
	}

	private function one($sql)
	{
		$result = $this->db->sql_query_limit($sql,1);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		return $row;
	}

	public function entry($game,$id)
	{
		$row = $this->one('SELECT * FROM ' . $this->prefix . 'comments WHERE target_game=' . (int) $game . ' AND comment_id=' . (int) $id);
		if (!$row) { throw new NotFoundHttpException(); }
		return $row;
	}

	public function legacy_target($game, $comment)
	{
		$row = null;
		if ($comment)
		{
			$row = $this->one('SELECT target_game,owner_id FROM ' . $this->prefix . 'comments WHERE comment_id=' . (int) $comment);
			if (!$row || !$row['target_game'] || ($game && $game !== (int) $row['target_game'])) { throw new NotFoundHttpException(); }
			$game = (int) $row['target_game'];
		}
		return array($this->game($game), $row);
	}

	public function manage($game,$comment)
	{
		$id = (int) $this->user->data['user_id'];
		$cats = $this->catalog->categories();
		return $this->catalog->admin() || $id === (int) $comment['owner_id'] ||
			(isset($cats[(int) $game['cat_id']]) && $id === (int) $cats[(int) $game['cat_id']]['moderator_id']);
	}

	public function recently_played($game)
	{
		$now = time();
		$where = " WHERE game_name='" . $this->db->sql_escape($game['game_name']) . "' AND owner_id=" . (int) $this->user->data['user_id'] .
			' AND recorded_at>' . ($now-3600) . ' AND recorded_at<=' . ($now+60);
		foreach (array('scores','all_scores') as $suffix)
		{
			if ($this->one('SELECT row_id FROM ' . $this->prefix . $suffix . $where)) { return true; }
		}
		return false;
	}

	public function count($game,$before = 0,$descending = false)
	{
		$where = ' WHERE target_game=' . (int) $game;
		if ($before) { $where .= ' AND comment_id' . ($descending ? '>' : '<') . (int) $before; }
		$row = $this->one('SELECT COUNT(*) AS total FROM ' . $this->prefix . 'comments' . $where);
		return (int) $row['total'];
	}

	public function page($game,$descending,$size,$start)
	{
		$scores = $this->prefix . 'scores';
		$name = $this->db->sql_escape($game['game_name']);
		// Score and playing time belong to the same result, including reverse-score games and ties.
		$order = ($game['reverse_list'] ? 'ASC' : 'DESC') . ',b.recorded_at,b.time_taken,b.row_id';
		$sql = 'SELECT c.*,u.username,u.user_colour,e.username AS edited_by,e.user_colour AS edit_colour,s.score_units,s.time_taken
			FROM ' . $this->prefix . 'comments c LEFT JOIN ' . USERS_TABLE . ' u ON c.owner_id>0 AND u.user_id=c.owner_id
			LEFT JOIN ' . USERS_TABLE . ' e ON c.editor_id>0 AND e.user_id=c.editor_id
			LEFT JOIN ' . $scores . " s ON s.row_id=(SELECT b.row_id FROM $scores b
				WHERE c.owner_id>0 AND b.owner_id=c.owner_id AND b.game_name='$name' ORDER BY b.score_units $order LIMIT 1)
			WHERE c.target_game=" . (int) $game['game_id'] . ' ORDER BY c.comment_id ' . ($descending ? 'DESC' : 'ASC');
		$r = $this->db->sql_query_limit($sql,$size,$start);
		$rows = $this->db->sql_fetchrowset($r);
		$this->db->sql_freeresult($r);
		return $rows;
	}

	public static function render($stored)
	{
		$message = utf8_htmlspecialchars(catalog::plain($stored));
		$uid = $bits = ''; $flags = 0;
		generate_text_for_storage($message,$uid,$bits,$flags,false,false,true);
		return generate_text_for_display($message,$uid,$bits,$flags);
	}

	public static function revision($row)
	{
		return hash('sha256',serialize(array($row['comment_id'],$row['comment_edit_count'],$row['comment_text'],$row['editor_id'])));
	}

	private function notify_first($owner,$game,$comment)
	{
		if (!(int) $this->catalog->setting('games_pm_comment',0) || !(int) $this->catalog->setting('games_use_pms',0) || !$this->config['allow_privmsg'] ||
			empty($this->user->data['user_allow_pm']) || !$this->auth->acl_get('u_sendpm')) { return; }
		$row = $this->one('SELECT c.owner_id,u.user_type,u.user_allow_pm,u.user_lang,m.block_pm FROM ' . $this->prefix . 'comments c
			LEFT JOIN ' . USERS_TABLE . ' u ON u.user_id=c.owner_id LEFT JOIN ' . $this->prefix . 'members m ON m.user_id=c.owner_id
			WHERE c.target_game=' . (int) $game . ' ORDER BY c.comment_id');
		if (!$row || (int) $row['owner_id'] <= ANONYMOUS || (int) $row['owner_id'] === $owner || empty($row['user_allow_pm']) ||
			$row['block_pm'] === null || (int) $row['block_pm'] !== 0 ||
			!in_array((int) $row['user_type'],array(USER_NORMAL,USER_FOUNDER),true)) { return; }
		$this->messages->send($owner, $this->user->data['username'], $this->user->ip, $row['owner_id'], $row['user_lang'],
			'game/' . (int) $game . '/comments?focus=' . (int) $comment, 'PLUS_ARC_COMMENT_PM', 'PLUS_ARC_COMMENT_PM_TEXT');
	}

	public function write($game_id,$action,$id,$raw,$receipt,$revision)
	{
		$game_id = (int) $game_id;
		$id = (int) $id;
		$owner = (int) $this->user->data['user_id'];
		$table = $this->prefix . 'comments';
		$this->db->sql_transaction('begin');
		try
		{
			$result = $this->db->sql_query('SELECT game_id FROM ' . $this->prefix . 'games WHERE game_id=' . $game_id . ' FOR UPDATE');
			$this->db->sql_freeresult($result);
			$this->catalog->refresh();
			$game = $this->game($game_id);
			if ($action !== 'add')
			{
				$row = $this->entry($game_id,$id);
				if (!$this->manage($game,$row)) { throw new NotFoundHttpException(); }
				if (!hash_equals(self::revision($row),$revision)) { throw new \DomainException('PLUS_ARC_COMMENT_CHANGED'); }
			}
			else
			{
				if (!preg_match('/^[a-f0-9]{32}$/D',$receipt)) { throw new AccessDeniedHttpException(); }
				$saved = $this->one('SELECT comment_id FROM ' . $this->prefix . "comment_receipts WHERE owner_id=$owner AND target_game=$game_id AND submit_key='$receipt'");
				if ($saved)
				{
					$id = (int) $saved['comment_id'];
					$current = $this->one("SELECT comment_id FROM $table WHERE target_game=$game_id AND comment_id=$id");
					$this->db->sql_transaction('commit');
					return $current ? $id : 0;
				}
				if (!$this->recently_played($game)) { throw new AccessDeniedHttpException(); }
			}
			if ($action !== 'delete' && (trim($raw) === '' || strlen($raw) > $game['comment_limit']))
			{ throw new \DomainException('PLUS_ARC_COMMENT_LENGTH'); }
			$now = time();
			if ($action === 'add')
			{
				$values = array('target_game'=>$game_id,'owner_id'=>$owner,'comment_game_name'=>$game['game_name'],'comment_user_id'=>$owner,
					'comment_username'=>$this->user->data['username'],'author_ip'=>$this->user->ip,'comment_time'=>$now,'comment_text'=>utf8_encode_ucr(utf8_htmlspecialchars($raw)));
				$this->db->sql_query("INSERT INTO $table " . $this->db->sql_build_array('INSERT',$values));
				$id = (int) $this->db->sql_nextid();
				$this->db->sql_query('INSERT INTO ' . $this->prefix . 'comment_receipts ' . $this->db->sql_build_array('INSERT',array(
					'target_game'=>$game_id,'owner_id'=>$owner,'submit_key'=>$receipt,'comment_id'=>$id)));
				$this->notify_first($owner,$game_id,$id);
			}
			elseif ($action === 'edit')
			{
				if ((int) $row['comment_edit_count'] >= 65535) { throw new \DomainException('PLUS_ARC_COMMENT_CHANGED'); }
				$change = array('comment_text'=>utf8_encode_ucr(utf8_htmlspecialchars($raw)),'comment_edit_count'=>(int) $row['comment_edit_count']+1,
					'editor_id'=>$owner,'comment_edit_user_id'=>$owner,'comment_edit_time'=>$now);
				$this->db->sql_query("UPDATE $table SET " . $this->db->sql_build_array('UPDATE',$change) . " WHERE comment_id=$id AND target_game=$game_id");
			}
			elseif ($action === 'delete')
			{
				$this->db->sql_query("DELETE FROM $table WHERE comment_id=$id AND target_game=$game_id");
				$id = 0;
			}
			else { throw new NotFoundHttpException(); }
			$this->db->sql_transaction('commit');
			return $id;
		}
		catch (\Throwable $e) { $this->db->sql_transaction('rollback'); throw $e; }
	}
}
