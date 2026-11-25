<?php
namespace phpbb2plus\community\event;

use phpbb2plus\community\service\former_members;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class author_search implements EventSubscriberInterface
{
	private $db;
	private $config;
	private $names;
	private $table;
	private $user_ids = array();
	private $condition = '';

	public function __construct($db,$config,$names,$table)
	{
		$this->db = $db;
		$this->config = $config;
		$this->names = $names;
		$this->table = $table;
	}

	public static function getSubscribedEvents()
	{
		$events = array('core.search_modify_submit_parameters'=>'prepare','core.search_modify_param_before'=>'users',
			'core.search_modify_url_parameters'=>'results_url','core.search_modify_rowset'=>'bylines',
			'core.submit_post_end'=>'submitted','core.mcp_change_poster_after'=>'reattributed');
		foreach (array('native','mysql','postgres') as $backend)
		{
			$events['core.search_' . $backend . '_by_author_modify_search_key'] = 'limit_posts';
			$events['core.search_' . $backend . '_by_keyword_modify_search_key'] = 'limit_posts';
		}
		return $events;
	}

	public function prepare($event)
	{
		$this->user_ids = array();
		$this->condition = '';
		$name = $event['author'];
		if ($name === '' || $event['search_id'] ||
			($event['author_id'] && (int) $event['author_id'] !== ANONYMOUS)) { return; }
		// Only select the guest candidate on backends where both the author and keyword
		// query accept the narrow per-post condition below, including on a cache hit.
		$type = ltrim($this->config['search_type'],'\\');
		if (!in_array($type,array('phpbb\search\fulltext_native','phpbb\search\fulltext_mysql','phpbb\search\fulltext_postgres'),true)) { return; }
		$clean = former_members::clean($name);
		if ($clean === '') { return; }
		$wild = strpos($name,'*') !== false;
		if ($wild && utf8_strlen(str_replace(array('*','%'),'', $name)) < $this->config['min_search_author_chars']) { return; }
		if ($wild)
		{
			$match = $this->db->sql_like_expression(str_replace('*',$this->db->get_any_char(),utf8_encode_ucr($clean)));
			$byline = "b.author_key<>'' AND b.author_clean " . $match;
		}
		else
		{
			$byline = "b.author_key='" . hash('sha256',$clean) . "'";
		}
		if ($this->names->hidden()) { $byline .= " AND b.author_name=''"; }
		$r = $this->db->sql_query_limit("SELECT b.post_id FROM $this->table b WHERE $byline",1);
		$exists = $this->db->sql_fetchrow($r);
		$this->db->sql_freeresult($r);
		if (!$exists) { return; }
		// Keep native matches for active names and real guest names, including a reused byline.
		$native = utf8_encode_ucr(utf8_clean_string($name));
		$native_match = $wild ? $this->db->sql_like_expression(str_replace('*',$this->db->get_any_char(),$native)) : "= '" . $this->db->sql_escape($native) . "'";
		$this->user_ids = array(ANONYMOUS);
		$r = $this->db->sql_query_limit('SELECT user_id FROM ' . USERS_TABLE . " WHERE user_type<>" . USER_IGNORE . ' AND username_clean ' . $native_match,100);
		while ($row = $this->db->sql_fetchrow($r)) { $this->user_ids[] = (int) $row['user_id']; }
		$this->db->sql_freeresult($r);
		$this->condition = '(p.poster_id<>' . ANONYMOUS . " OR EXISTS (SELECT 1 FROM $this->table b WHERE b.post_id=p.post_id AND $byline))";
		// The stock preflight otherwise stops before dispatching to the search backend:
		// phpBB deliberately stores only a public label in posts.post_username.
		$event['author_id'] = ANONYMOUS;
	}

	public function submitted($event)
	{
		$data = $event['data'];
		$guest = $event['mode'] === 'edit' ? (isset($data['poster_id']) && (int) $data['poster_id'] === ANONYMOUS) : $event['username'] !== '';
		if (!empty($data['post_id']) && $guest)
		{ $this->sync_guest((int) $data['post_id'],false); }
	}

	public function reattributed($event)
	{
		$this->sync_guest((int) $event['post_info']['post_id'],true);
	}

	private function sync_guest($id,$new_author)
	{
		$this->db->sql_transaction('begin');
		try
		{
			$r = $this->db->sql_query('SELECT p.poster_id,p.post_username,b.post_id AS indexed,b.author_name FROM ' . POSTS_TABLE .
				" p LEFT JOIN $this->table b ON b.post_id=p.post_id WHERE p.post_id=$id FOR UPDATE");
			$row = $this->db->sql_fetchrow($r);
			$this->db->sql_freeresult($r);
			if ($row && ($new_author || $row['author_name'] === null || $row['author_name'] === ''))
			{
				$clean = (int) $row['poster_id'] === ANONYMOUS ? former_members::clean($row['post_username']) : '';
				$fields = array('author_name'=>'','author_clean'=>utf8_encode_ucr($clean),'author_key'=>$clean === '' ? '' : hash('sha256',$clean));
				if ($row['indexed'] !== null)
				{
					$this->db->sql_query("UPDATE $this->table SET " . $this->db->sql_build_array('UPDATE',$fields) . " WHERE post_id=$id");
				}
				elseif ($clean !== '')
				{
					$this->db->sql_query("INSERT INTO $this->table " . $this->db->sql_build_array('INSERT',$fields + array('post_id'=>$id,'editor_name'=>'')));
				}
			}
			$this->db->sql_transaction('commit');
		}
		catch (\Throwable $e) { $this->db->sql_transaction('rollback'); throw $e; }
	}

	public function users($event)
	{
		if ($this->condition !== '') { $event['author_id_ary'] = array_values(array_unique(array_merge($event['author_id_ary'],$this->user_ids))); }
	}

	public function limit_posts($event)
	{
		if ($this->condition === '') { return; }
		$native = $event['post_visibility'];
		$event['post_visibility'] = ($native ? '(' . $native . ') AND ' : '') . $this->condition;
		$key = $event['search_key_array'];
		$key[] = 'plus_byline_' . hash('sha256',$this->condition);
		$event['search_key_array'] = $key;
	}

	public function results_url($event)
	{
		if ($this->condition !== '')
		{
			$event['u_search'] = preg_replace('/&amp;author_id=' . ANONYMOUS . '(?=&amp;|$)/','',$event['u_search']);
		}
	}

	public function bylines($event)
	{
		if ($this->names->hidden()) { return; }
		$rows = $event['rowset'];
		$posts = $event['show_results'] === 'posts';
		$fields = $posts ? array(array('poster_id','post_id','post_username')) :
			array(array('topic_poster','topic_first_post_id','topic_first_poster_name'),array('topic_last_poster_id','topic_last_post_id','topic_last_poster_name'));
		$targets = array();
		foreach ($rows as $key=>$row)
		{
			foreach ($fields as $field)
			{
				if (isset($row[$field[0]],$row[$field[1]]) && (int) $row[$field[0]] === ANONYMOUS)
				{ $targets[(int) $row[$field[1]]][] = array($key,$field[2]); }
			}
		}
		if (!$targets) { return; }
		$r = $this->db->sql_query("SELECT post_id,author_name FROM $this->table WHERE author_name<>'' AND " . $this->db->sql_in_set('post_id',array_keys($targets)));
		while ($found = $this->db->sql_fetchrow($r))
		{
			foreach ($targets[(int) $found['post_id']] as $target) { $rows[$target[0]][$target[1]] = $found['author_name']; }
		}
		$this->db->sql_freeresult($r);
		$event['rowset'] = $rows;
	}
}
