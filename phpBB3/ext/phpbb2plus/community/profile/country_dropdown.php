<?php
namespace phpbb2plus\community\profile;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

// phpBB edits option labels as lines of text. Keep the original native IDs
// when an administrator edits a country dropdown containing retired options.
class country_dropdown extends \phpbb\profilefields\type\type_dropdown implements EventSubscriberInterface
{
	private $db;
	private $config;
	private $table;
	private $option_ids;
	private $labels;
	private $last;
	private $catalogue;
	private $revision;

	public function set_storage($db, $config, $prefix, $catalogue)
	{
		$this->db = $db;
		$this->config = $config;
		$this->table = $prefix . 'plus_flags';
		$this->catalogue = $catalogue;
	}

	public static function getSubscribedEvents()
	{
		return array('core.acp_profile_create_edit_init' => 'open_country_field', 'core.acp_profile_create_edit_after' => 'revision_field',
			'core.acp_profile_create_edit_save_before' => 'start_write');
	}

	public function open_country_field($event)
	{
		$row = $event['field_row'];
		if ($event['action'] !== 'edit' || ($row['field_ident'] ?? '') !== 'plus_country' ||
			$event['field_type'] !== 'profilefields.type.dropdown' || !isset($this->config['plus_flag_revision'])) { return; }
		$this->revision = $this->request->is_set_post('plus_country_revision') ? $this->request->variable('plus_country_revision', -1) : $this->catalogue->revision();
		$labels = array();
		$result = $this->db->sql_query('SELECT option_id,lang_value FROM ' . PROFILE_FIELDS_LANG_TABLE . ' WHERE field_id=' . (int) $row['field_id'] .
			' AND lang_id=' . (int) $row['lang_id'] . ' ORDER BY option_id');
		while ($option = $this->db->sql_fetchrow($result)) { $labels[(int) $option['option_id']] = $option['lang_value']; }
		$this->db->sql_freeresult($result);
		if (!$labels) { return; }
		$this->labels = $labels;
		$this->option_ids = array_keys($labels);
		$result = $this->db->sql_query("SELECT MAX(option_value) AS last_value FROM {$this->table}");
		$this->last = max(end($this->option_ids), (int) $this->db->sql_fetchfield('last_value') - 1);
		$this->db->sql_freeresult($result);
	}

	public function revision_field($event)
	{
		if ($this->revision !== null) { $event['s_hidden_fields'] .= build_hidden_fields(array('plus_country_revision' => $this->revision)); }
	}

	public function start_write($event, $name, $dispatcher)
	{
		if ($this->revision === null) { return; }
		try { $this->catalogue->native_edit($this->request->variable('plus_country_revision', -1, false, \phpbb\request\request_interface::POST)); }
		catch (\DomainException $e)
		{
			$this->user->add_lang_ext('phpbb2plus/community', 'info_acp_flags');
			trigger_error('ACP_PLUS_FLAG_CHANGED', E_USER_WARNING);
		}
		$dispatcher->addListener('core.garbage_collection', array($this->catalogue, 'leave'));
	}

	private function numbered($options)
	{
		if (!$options || $this->option_ids === null) { return $options; }
		$labels = is_array($options) ? array_values($options) : explode("\n", $options);
		$next = $this->last + 1;
		$numbered = array();
		foreach ($labels as $position => $label)
		{
			$id = $this->option_ids[$position] ?? $next++;
			$numbered[$id] = $label;
		}
		return $numbered;
	}

	public function prepare_options_form(&$exclude_options, &$visibility_options)
	{
		$input = parent::prepare_options_form($exclude_options, $visibility_options);
		return $input === '' && $this->option_ids !== null ? $this->labels : $this->numbered($input);
	}

	public function get_language_options_input($field_data)
	{
		$data = parent::get_language_options_input($field_data);
		if ($this->option_ids !== null)
		{
			foreach ($data['l_lang_options'] as $locale => $options) { $data['l_lang_options'][$locale] = $this->numbered($options); }
		}
		return $data;
	}
}
