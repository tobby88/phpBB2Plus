<?php
namespace phpbb2plus\community\migrations;

use phpbb2plus\community\service\formatting_codes;

class v172_formatting_codes extends \phpbb\db\migration\migration
{
	public static function depends_on() { return array('\phpbb2plus\community\migrations\v162_acronym_formatter'); }

	public function update_data()
	{
		return array(array('config.add', array('plus_formatting_overflow', '')), array('custom', array(array($this, 'install_codes'))));
	}

	public function install_codes()
	{
		$this->install_definitions(formatting_codes::text_styles());
	}

	protected function install_definitions($definitions)
	{
		global $phpbb_container;
		require_once $this->phpbb_root_path . 'includes/acp/acp_bbcodes.' . $this->php_ext;
		$native = new \acp_bbcodes();
		$table = $this->table_prefix . 'bbcodes';
		$this->db->sql_transaction('begin');
		try
		{
			$result = $this->db->sql_query("SELECT bbcode_id,bbcode_tag FROM $table ORDER BY bbcode_id FOR UPDATE");
			$existing = array();
			$last = NUM_CORE_BBCODES;
			while ($row = $this->db->sql_fetchrow($result))
			{
				$existing[rtrim(strtolower($row['bbcode_tag']), '=')] = true;
				$last = max($last, (int) $row['bbcode_id']);
			}
			$this->db->sql_freeresult($result);
			$previous = explode(',', (string) $this->config['plus_formatting_overflow']);
			$overflow = array_diff($previous, array(''), array_keys($definitions));
			foreach ($definitions as $tag => $values)
			{
				if (isset($existing[$tag])) { continue; }
				if ($last >= BBCODE_LIMIT) { $overflow[] = $tag; continue; }
				list($usage, $template, $example) = $values;
				$row = $native->build_regexp($usage, $template);
				$row += array('bbcode_id' => ++$last, 'bbcode_match' => $usage, 'bbcode_tpl' => $template,
					'bbcode_helpline' => $example, 'display_on_posting' => (int) in_array($tag, array('s','center','hr','poet'), true));
				$this->db->sql_query("INSERT INTO $table " . $this->db->sql_build_array('INSERT', $row));
			}
			$this->config->set('plus_formatting_overflow', implode(',', $overflow));
			$this->db->sql_transaction('commit');
		}
		catch (\Throwable $error) { $this->db->sql_transaction('rollback'); throw $error; }
		$phpbb_container->get('cache.driver')->destroy('sql', $table);
		\phpbb2plus\community\service\smilie_sign::reset_formatter($phpbb_container);
	}
}
