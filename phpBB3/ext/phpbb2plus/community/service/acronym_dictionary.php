<?php
namespace phpbb2plus\community\service;

/** The CMX dictionary decorates rendered text, so edits also apply to existing posts. */
class acronym_dictionary
{
	private $db;
	private $table;
	private $terms;
	private $initials;

	public function __construct($db, $table) { $this->db = $db; $this->table = $table; }

	public static function encode($raw)
	{
		return utf8_encode_ucr(str_replace('&', '&amp;', (string) $raw));
	}

	public static function decode($stored)
	{
		return html_entity_decode((string) $stored, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}

	private static function escape($text)
	{
		return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}

	private function load_terms()
	{
		$this->terms = array();
		$result = $this->db->sql_query('SELECT term_text, definition_text FROM ' . $this->table . ' ORDER BY acronym_id', 3600);
		while ($row = $this->db->sql_fetchrow($result))
		{
			$term = self::decode($row['term_text']);
			if ($term === '') { continue; }
			$node = &$this->terms;
			for ($i = 0, $length = strlen($term); $i < $length; ++$i)
			{
				$byte = $term[$i];
				if (!isset($node[$byte])) { $node[$byte] = array(); }
				$node = &$node[$byte];
			}
			$node[''] = self::decode($row['definition_text']);
			unset($node);
		}
		$this->db->sql_freeresult($result);
		$this->initials = implode('', array_keys($this->terms));
	}

	private function text($encoded)
	{
		$raw = html_entity_decode($encoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		if (strpbrk($raw, $this->initials) === false || !preg_match_all('/\b/u', $raw, $matches, PREG_OFFSET_CAPTURE)) { return $encoded; }
		$boundaries = array_flip(array_column($matches[0], 1));
		$length = strlen($raw);
		$html = '';
		$consumed = 0;
		foreach ($boundaries as $start => $_)
		{
			if ($start < $consumed || $start >= $length || !isset($this->terms[$raw[$start]])) { continue; }
			$node = $this->terms;
			$end = $start;
			$definition = '';
			for ($i = $start; $i < $length && isset($node[$raw[$i]]); ++$i)
			{
				$node = $node[$raw[$i]];
				if (isset($node[''], $boundaries[$i + 1])) { $end = $i + 1; $definition = $node['']; }
			}
			if ($end === $start) { continue; }
			// The longest complete match wins. Inserted definitions never become new input.
			$html .= self::escape(substr($raw, $consumed, $start - $consumed)) . '<abbr title="' . self::escape($definition)
				. '">' . self::escape(substr($raw, $start, $end - $start)) . '</abbr>';
			$consumed = $end;
		}
		return $consumed ? $html . self::escape(substr($raw, $consumed)) : $encoded;
	}

	public function decorate($html)
	{
		if ($html === '') { return $html; }
		if ($this->terms === null) { $this->load_terms(); }
		if (!$this->terms) { return $html; }
		// Preserve the formatter's HTML verbatim, including quoted attributes and comments.
		$parts = preg_split('~(<!--.*?-->|</?[a-z](?:[^>"\']++|"[^"]*"|\'[^\']*\')*+>)~is', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
		if ($parts === false) { return $html; }
		$protected = 0;
		foreach ($parts as $index => $part)
		{
			if ($index % 2)
			{
				if (preg_match('~^<(/?)(?:abbr|acronym|code|pre|script|style|textarea)(?=[\s/>])~i', $part, $match) && substr($part, -2) !== '/>')
				{
					$protected = $match[1] === '/' ? max(0, $protected - 1) : $protected + 1;
				}
			}
			elseif (!$protected && $part !== '') { $parts[$index] = $this->text($part); }
		}
		return implode('', $parts);
	}
}
