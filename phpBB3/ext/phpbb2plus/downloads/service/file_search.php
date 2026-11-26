<?php
namespace phpbb2plus\downloads\service;

class file_search
{
	private $db;
	private $access;
	private $prefix;
	private $config;

	public function __construct($db,$access,$prefix,$config)
	{
		$this->db=$db;
		$this->access=$access;
		$this->prefix=$prefix;
		$this->config=$config;
	}

	public static function bounded($value,$bytes)
	{
		if (!is_string($value) || !preg_match('//u',$value)) { return ''; }
		$value = preg_replace('/[\x00-\x1f\x7f]+/u',' ',$value);
		return mb_strcut(trim($value),0,$bytes,'UTF-8');
	}

	public function categories($selected)
	{
		$menu = $files = $comments = $in_scope = array();
		$cats = $this->access->categories();
		foreach ($this->access->directory() as $id=>$depth)
		{
			$cat = $cats[$id];
			$menu[$id] = str_repeat('— ',$depth) . access::plain($cat['title']);
			$in_scope[$id] = !$selected || $id === $selected || !empty($in_scope[(int) $cat['parent_id']]);
			if (!$in_scope[$id] || !$cat['allow_files'] || !$this->access->allows($id,'view_file')) { continue; }
			$files[] = $id;
			if ($cat['allow_comments'] && $this->access->allows($id,'view_comment')) { $comments[] = $id; }
		}
		return array($menu,$files,$comments);
	}

	private function like($word,$author = false)
	{
		$parts = array();
		foreach (explode('*',$word) as $piece)
		{
			$text = $author ? utf8_encode_ucr(utf8_strtolower(utf8_htmlspecialchars($piece))) : search_text::encode($piece);
			$parts[] = str_replace(array('!','%','_'),array('!!','!%','!_'),$text);
		}
		$term = implode('%',$parts);
		return "LIKE '" . $this->db->sql_escape($author ? $term : '%' . $term . '%') . "' ESCAPE '!'";
	}

	private function words($keywords)
	{
		// phpBB2 uses the board's language for download searches, independently of the viewer's language.
		$locale = preg_match('/^de(?:[-_]|$)/i',(string) $this->config['default_lang']) ? 'de' : 'en';
		$folder = __DIR__ . '/../language/' . $locale . '/';
		$stops = array_fill_keys(file($folder . 'search_stopwords.txt',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES),true);
		$aliases = array();
		foreach (array_reverse(file($folder . 'search_synonyms.txt',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)) as $line)
		{
			$pair = preg_split('/\s+/u',trim(utf8_strtolower($line)));
			if (count($pair)===2) { $aliases[$pair[1]] = $aliases[$pair[0]] ?? $pair[0]; }
		}
		$keywords = preg_replace(array('/(?:^|\s)\+/u','/(?:^|\s)-/u'),array(' and ',' not '),$keywords);
		$words = array();
		foreach (preg_split('/[\s,;()[\]{}"]+/u',$keywords,-1,PREG_SPLIT_NO_EMPTY) as $part)
		{
			$word = utf8_strtolower(self::bounded($part,100));
			if (in_array($word,array('and','or','not'),true)) { $words[] = $word; }
			elseif (!isset($stops[$word])) { $words[] = $aliases[$word] ?? $word; }
			if (count($words)===50) { break; }
		}
		return $words;
	}

	public function filter($criteria,$files,$comments)
	{
		if (!$files) { return '1=0'; }
		$where = 'f.approved=1 AND ' . $this->db->sql_in_set('f.cat_id',$files);
		if ($criteria['search_author'] !== '')
		{
			$where .= ' AND EXISTS (SELECT 1 FROM ' . USERS_TABLE . ' a WHERE a.user_id=f.owner_id AND a.user_id>' . ANONYMOUS .
				' AND LOWER(a.username) ' . $this->like($criteria['search_author'],true) . ')';
		}
		if ($criteria['search_keywords'] === '') { return $where; }
		$include = $criteria['comments_search'] === 'YES' && $comments;
		$comment_scope = $include ? $this->db->sql_in_set('f.cat_id',$comments) : '';
		$links = $include && (int) $this->access->setting('allow_comment_links',1);
		$operator = $criteria['search_terms'] === 'all' ? 'and' : 'or';
		$expression = '';
		foreach ($this->words($criteria['search_keywords']) as $word)
		{
			if (in_array($word,array('and','or','not'),true)) { $operator=$word; continue; }
			$like = $this->like($word);
			$match = "f.search_title $like OR f.search_creator $like OR f.search_summary $like OR f.search_detail $like";
			if ($include)
			{
				$comment = "c.search_title $like OR c.search_body $like" . ($links ? " OR c.search_links $like" : '');
				$match .= " OR ($comment_scope AND EXISTS (SELECT 1 FROM " . $this->prefix . "comments c WHERE c.file_id=f.file_id AND ($comment)))";
			}
			$match = '(' . $match . ')';
			if ($expression === '') { $expression = $operator === 'not' ? "(NOT $match)" : $match; }
			else { $expression = '(' . $expression . ($operator === 'not' ? ' AND NOT ' : ($operator === 'and' ? ' AND ' : ' OR ')) . $match . ')'; }
		}
		return $where . ' AND ' . ($expression !== '' ? $expression : '1=0');
	}

	public function ids($where)
	{
		$result = $this->db->sql_query_limit('SELECT f.file_id FROM ' . $this->prefix . "files f WHERE $where ORDER BY f.file_id",12000);
		$ids = array();
		while ($r = $this->db->sql_fetchrow($result)) { $ids[] = (int) $r['file_id']; }
		$this->db->sql_freeresult($result);
		return $ids;
	}

	public function candidates($ids)
	{
		return $ids ? $this->db->sql_in_set('f.file_id',array_map('intval',array_slice($ids,0,12000))) : '1=0';
	}
}
