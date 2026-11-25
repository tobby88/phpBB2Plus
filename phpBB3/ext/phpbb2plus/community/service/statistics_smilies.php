<?php
namespace phpbb2plus\community\service;

class statistics_smilies
{
	private $db;
	private $config;
	private $user;
	private $paths;

	public function __construct($db, $config, $user, $paths)
	{
		$this->db = $db;
		$this->config = $config;
		$this->user = $user;
		$this->paths = $paths;
	}

	private function count_post($text, $codes, &$totals)
	{
		if (preg_match('#^<[rt][ >]#', $text))
		{
			// Native rich text stores actual parsed emoticons in E elements.
			// Literal code, URLs and escaped user markup are ordinary text.
			preg_match_all('#<E>([^<]*)</E>#', $text, $matches);
			foreach ($matches[1] as $encoded)
			{
				$code = html_entity_decode($encoded, ENT_QUOTES | ENT_XML1, 'UTF-8');
				if (isset($codes[$code])) { ++$totals[$codes[$code]]; }
			}
		}
		else
		{
			// phpBB also renders its paired, pre-3.2 storage markers until a native reparse.
			preg_match_all('#<!-- s(.{1,200}?) --><img src="\{SMILIES_PATH\}/([^"]+)" [^<>]*?/><!-- s\1 -->#', $text, $matches, PREG_SET_ORDER);
			foreach ($matches as $match)
			{
				$code = htmlspecialchars_decode($match[1], ENT_QUOTES);
				if (isset($codes[$code]) && $codes[$code] === htmlspecialchars_decode($match[2], ENT_QUOTES)) { ++$totals[$codes[$code]]; }
			}
		}
	}

	public function ranking($post_scope, $limit)
	{
		if (!$this->config['allow_smilies']) { return array(); }
		$codes = $labels = $totals = array();
		$result = $this->db->sql_query('SELECT code,smiley_url FROM ' . SMILIES_TABLE . ' ORDER BY smiley_id');
		while ($row = $this->db->sql_fetchrow($result))
		{
			$code = htmlspecialchars_decode($row['code'], ENT_QUOTES);
			$image = htmlspecialchars_decode($row['smiley_url'], ENT_QUOTES);
			if ($code === '' || !preg_match('~^[^/\\\\:\x00-\x1f]+\.(gif|png|jpe?g|webp|svg)$~iD', $image)) { continue; }
			$codes[$code] = $image;
			$totals[$image] = 0;
			if (!isset($labels[$image])) { $labels[$image] = $code; }
		}
		$this->db->sql_freeresult($result);
		if (!$codes) { return array(); }

		// Bound each scan by the next real primary keys before applying the visibility joins.
		// A result limit after those joins can rescan the remaining board for every page.
		if ($this->db->get_sql_layer() === 'mysqli')
		{
			// Parser tags are exact ASCII, independent of the board's Unicode collation.
			$marked = "LOCATE(BINARY '<E>',p.post_text)>0 OR LOCATE(BINARY '<!-- s',p.post_text)>0";
		}
		else
		{
			$any = $this->db->get_any_char();
			$marked = 'p.post_text ' . $this->db->sql_like_expression($any . '<E>' . $any) . ' OR p.post_text ' .
				$this->db->sql_like_expression($any . '<!-- s' . $any);
		}
		$sql = 'SELECT p.post_text FROM ' . POSTS_TABLE . ' p JOIN ' . TOPICS_TABLE . ' t ON t.topic_id=p.topic_id AND t.forum_id=p.forum_id
			WHERE ' . $post_scope . ' AND p.enable_smilies=1 AND (' . $marked . ')';
		$after = 0;
		$batch = 256;
		do
		{
			$cursor = $this->db->sql_query_limit('SELECT post_id FROM ' . POSTS_TABLE . ' WHERE post_id>' . $after . ' ORDER BY post_id', 1, $batch - 1);
			$upper = (int) $this->db->sql_fetchfield('post_id');
			$this->db->sql_freeresult($cursor);
			$result = $this->db->sql_query($sql . ' AND p.post_id>' . $after . ($upper ? ' AND p.post_id<=' . $upper : ''));
			while ($row = $this->db->sql_fetchrow($result))
			{
				$this->count_post($row['post_text'], $codes, $totals);
			}
			$this->db->sql_freeresult($result);
			$after = $upper;
		} while ($upper);

		$totals = array_filter($totals);
		if (!$totals) { return array(); }
		uksort($totals, static function ($left, $right) use ($totals, $labels) {
			return ($totals[$right] <=> $totals[$left]) ?: strcmp($labels[$left], $labels[$right]);
		});
		$sum = array_sum($totals);
		$max = reset($totals);
		$directory = trim(str_replace('\\', '/', $this->config['smilies_path']), '/');
		$parts = explode('/', $directory);
		$image_base = '';
		if ($this->user->optionget('viewsmilies') && $directory !== '' && !in_array('..', $parts, true) && !preg_match('/[:\x00-\x1f]/', $directory))
		{
			$image_base = $this->paths->get_web_root_path() . implode('/', array_map('rawurlencode', $parts)) . '/';
		}
		$rows = array();
		foreach (array_slice($totals, 0, $limit, true) as $image => $count)
		{
			$rows[] = array('LABEL' => $labels[$image], 'COUNT' => $count, 'PERCENT' => (int) round(100 * $count / $sum), 'BAR' => $count, 'MAX' => $max,
				'IMAGE' => $image_base !== '' ? $image_base . rawurlencode($image) : '');
		}
		return $rows;
	}
}
