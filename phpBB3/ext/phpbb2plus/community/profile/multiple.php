<?php
namespace phpbb2plus\community\profile;

/** A single native custom profile field with multiple independently selectable options. */
class multiple extends \phpbb\profilefields\type\type_dropdown
{
	public function get_name_short() { return 'plus_multi'; }
	public function get_template_filename() { return '@phpbb2plus_community/profile_multi.html'; }
	public function get_database_column_type() { return 'MTEXT_UNI'; }
	public function get_name()
	{
		$this->user->add_lang_ext('phpbb2plus/community','community');
		return $this->user->lang('FIELD_PLUS_MULTI');
	}

	public function get_default_option_values()
	{
		return array('field_length'=>0,'field_minlen'=>0,'field_maxlen'=>0,'field_validation'=>'',
			'field_novalue'=>'','field_default_value'=>'');
	}

	private function indices($value)
	{
		return (string) $value === '' ? array() : explode(',',(string) $value);
	}

	private function selection($key)
	{
		return implode(',',array_unique($this->request->variable($key,array(0=>0))));
	}

	private function options($field, $preview = false)
	{
		$id = $field['field_id'];
		$lang = $field['lang_id'];
		if ($preview !== false) { $this->lang_helper->load_preview_options($id,$lang,$preview); }
		elseif (!$this->lang_helper->is_set($id,$lang)) { $this->lang_helper->load_option_lang($lang); }
		return $this->lang_helper->is_set($id,$lang) ? $this->lang_helper->get($id,$lang) : array();
	}

	public function get_profile_field($field)
	{
		$key = $this->get_field_ident($field);
		return $this->request->is_set($key . '_present') ? $this->selection($key) :
			($this->user->profile_fields[$key] ?? $field['field_default_value']);
	}

	public function validate_profile_field(&$value, $field)
	{
		$selected = $this->indices($value);
		$name = $this->get_field_name($field['lang_name']);
		if (!$selected && $field['field_required']) { return $this->user->lang('FIELD_REQUIRED',$name); }
		$options = $this->options($field);
		foreach ($selected as $index)
		{
			if (!isset($options[$index])) { return $this->user->lang('FIELD_INVALID_VALUE',$name); }
		}
		return false;
	}

	public function get_profile_value_raw($value, $field)
	{
		return (string) $value === '' ? null : (string) $value;
	}

	public function get_profile_value($value, $field)
	{
		$labels = array();
		$options = $this->options($field);
		foreach ($this->indices($value) as $index)
		{
			if (isset($options[$index])) { $labels[] = $options[$index]; }
		}
		return $labels ? implode(', ',$labels) : null;
	}

	public function generate_field($field, $preview_options = false)
	{
		$key = $field['var_name'] ?? $this->get_field_ident($field);
		$value = $this->request->is_set($key . '_present') ? $this->selection($key) :
			($preview_options === false && isset($this->user->profile_fields[$key]) ? $this->user->profile_fields[$key] : $field['field_default_value']);
		$selected = array_flip($this->indices($value));
		$this->template->assign_block_vars('plus_multi',array('KEY'=>$key));
		foreach ($this->options($field,$preview_options) as $index=>$label)
		{
			$this->template->assign_block_vars('plus_multi.choice',array('ID'=>(int) $index, 'LABEL'=>$label, 'CHECKED'=>isset($selected[$index])));
		}
	}

	public function get_options($lang_id, $field)
	{
		$preview = array('var_name'=>'plus_multi_defaults','field_id'=>$field['field_id'] ?? 0,
			'lang_id'=>$lang_id,'lang_options'=>$field['lang_options'],'field_default_value'=>$field['field_default_value']);
		return array(array('TITLE'=>$this->user->lang('DEFAULT_VALUE'),'FIELD'=>$this->process_field_row('preview',$preview)));
	}

	public function get_excluded_options($key, $action, $value, &$fields, $step)
	{
		if ($key === 'field_default_value' && $this->request->is_set('plus_multi_defaults_present'))
		{
			$options = $fields['lang_options'];
			$count = is_array($options) ? count($options) : count(explode("\n",$options));
			$selected = array_filter($this->indices($this->selection('plus_multi_defaults')),static function ($id) use ($count) {
				return (int) $id > 0 && (int) $id <= $count;
			});
			return implode(',',$selected);
		}
		return parent::get_excluded_options($key,$action,$value,$fields,$step);
	}

	public function prepare_hidden_fields($step, $key, $action, &$fields)
	{
		if ($key === 'field_default_value') { return $fields[$key]; }
		return parent::prepare_hidden_fields($step,$key,$action,$fields);
	}
}
