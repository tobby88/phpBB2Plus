<?php
namespace phpbb2plus\arcade\service;

use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class activity
{
	private $db;
	private $catalog;
	private $user;
	private $prefix;

	public function __construct($db,$catalog,$user,$prefix)
	{
		$this->db = $db;
		$this->catalog = $catalog;
		$this->user = $user;
		$this->prefix = $prefix;
	}

	/** Recover name-only source rows without associating ambiguous names or old IDs with unrelated games. */
	public static function link_games($db,$prefix)
	{
		foreach (array('favorites'=>'fav_game_name','ratings'=>'rate_game_name') as $suffix=>$name)
		{
			self::link_game_rows($db,$prefix,$suffix,$name);
		}
	}

	private static function link_game_rows($db,$prefix,$suffix,$name)
	{
		$table = $prefix . $suffix;
		$games = $prefix . 'games';
		if ($db->get_sql_layer() === 'mysqli')
		{
			// Materialise the catalogue once on large MySQL/MariaDB conversions.
			$by_name = "SELECT LOWER(game_name) AS game_key, MIN(game_id) AS id FROM $games
				WHERE game_name<>'' GROUP BY LOWER(game_name) HAVING COUNT(*)=1";
			$join = " LEFT JOIN ($by_name) named ON named.game_key=LOWER(h.$name)";
			$match = 'named.id';
			if ($suffix === 'favorites')
			{
				$join .= " LEFT JOIN $games numbered ON numbered.game_id=h.fav_game_id";
				$match = "CASE WHEN h.$name IS NULL OR h.$name='' THEN numbered.game_id ELSE named.id END";
			}
			$db->sql_query("UPDATE $table h $join SET h.target_game=COALESCE($match,0) WHERE h.target_game=0");
			return;
		}
		$match = "(SELECT MIN(g.game_id) FROM $games g WHERE g.game_name<>'' AND LOWER(g.game_name)=LOWER($table.$name) HAVING COUNT(*)=1)";
		if ($suffix === 'favorites')
		{
			$match = "CASE WHEN $name IS NULL OR $name='' THEN (SELECT g.game_id FROM $games g WHERE g.game_id=$table.fav_game_id) ELSE $match END";
		}
		$db->sql_query("UPDATE $table SET target_game=COALESCE($match,0) WHERE target_game=0");
	}

	private function member()
	{
		return !empty($this->user->data['is_registered']) && empty($this->user->data['is_bot']);
	}

	public function favourites_enabled()
	{
		return $this->member() && (int) $this->catalog->setting('games_show_fav',1) === 1;
	}

	public function favourites_filter()
	{
		return 'EXISTS (SELECT 1 FROM ' . $this->prefix . 'favorites fav WHERE fav.target_game=g.game_id AND fav.owner_id=' .
			(int) $this->user->data['user_id'] . ')';
	}

	public function favourite($id)
	{
		$r = $this->db->sql_query_limit('SELECT row_id FROM ' . $this->prefix . 'favorites WHERE target_game=' . (int) $id .
			' AND owner_id=' . (int) $this->user->data['user_id'],1);
		$value = (bool) $this->db->sql_fetchfield('row_id');
		$this->db->sql_freeresult($r);
		return $value;
	}

	public function rating_scale()
	{
		if ((int) $this->catalog->setting('games_rate',0) !== 1) { return 0; }
		$scale = (int) $this->catalog->setting('games_default_rate',0);
		return $scale > 0 && $scale <= 255 ? $scale : 0;
	}

	public function votes($id)
	{
		$r = $this->db->sql_query('SELECT COUNT(*) AS total,AVG(rate_point) AS mean,
			MAX(CASE WHEN owner_id=' . (int) $this->user->data['user_id'] . ' THEN 1 ELSE 0 END) AS voted
			FROM ' . $this->prefix . 'ratings WHERE target_game=' . (int) $id);
		$row = $this->db->sql_fetchrow($r);
		$this->db->sql_freeresult($r);
		return $row;
	}

	public function may_vote($game)
	{
		return $this->member() && $game['game_avail'] && $this->rating_scale() &&
			(int) $this->user->data['user_posts'] >= (int) $this->catalog->setting('games_posts_required',0) &&
			(int) $this->user->data['user_rank'] >= (int) $this->catalog->setting('games_rank_required',0);
	}

	public function write($game_id,$operation,$value)
	{
		$game_id = (int) $game_id;
		if (!$this->member()) { throw new AccessDeniedHttpException(); }
		$this->db->sql_transaction('begin');
		try
		{
			// Serialize the decision and the write even when a converted list contains duplicate legacy rows.
			$r = $this->db->sql_query('SELECT game_id FROM ' . $this->prefix . 'games WHERE game_id=' . $game_id . ' FOR UPDATE');
			$found = $this->db->sql_fetchrow($r);
			$this->db->sql_freeresult($r);
			if (!$found) { throw new NotFoundHttpException(); }
			$this->catalog->refresh();
			$g = $this->catalog->game($game_id);
			$owner = (int) $this->user->data['user_id'];
			$where = " WHERE owner_id=$owner AND target_game=$game_id";
			if ($operation === 'favourite')
			{
				if (!$this->favourites_enabled() || !in_array($value,array(0,1),true)) { throw new AccessDeniedHttpException(); }
				if (!$value) { $this->db->sql_query('DELETE FROM ' . $this->prefix . 'favorites' . $where); }
				elseif (!$this->favourite($game_id))
				{
					$this->db->sql_query('INSERT INTO ' . $this->prefix . 'favorites ' . $this->db->sql_build_array('INSERT',array(
						'owner_id'=>$owner,'user_id'=>$owner,'target_game'=>$game_id,'fav_game_id'=>$game_id,'fav_game_name'=>$g['game_name'])));
				}
			}
			elseif ($operation === 'rate')
			{
				if (!$this->may_vote($g)) { throw new AccessDeniedHttpException(); }
				if ($value < 1 || $value > $this->rating_scale()) { throw new \DomainException('PLUS_ARC_RATING_INVALID'); }
				$votes = $this->votes($game_id);
				if (!$votes['voted'])
				{
					$packed = @inet_pton($this->user->ip);
					// Preserve the source's four-byte receipt format, including its IPv6 fingerprint convention.
					$ip = $packed === false ? '00000000' : (strlen($packed) === 4 ? bin2hex($packed) : substr(hash('sha256',$packed),0,8));
					$this->db->sql_query('INSERT INTO ' . $this->prefix . 'ratings ' . $this->db->sql_build_array('INSERT',array(
						'owner_id'=>$owner,'rate_user_id'=>$owner,'target_game'=>$game_id,'rate_game_name'=>$g['game_name'],
						'rate_user_ip'=>$ip,'rate_point'=>$value)));
				}
			}
			else { throw new NotFoundHttpException(); }
			$this->db->sql_transaction('commit');
		}
		catch (\Throwable $e) { $this->db->sql_transaction('rollback'); throw $e; }
	}
}
