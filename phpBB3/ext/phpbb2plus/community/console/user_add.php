<?php
namespace phpbb2plus\community\console;

/** Apply the same account rules to both interactive answers and command options. */
class user_add extends \phpbb\console\command\user\add
{
	private $rules;

	public function set_rules($rules) { $this->rules = $rules; }

	protected function validate_user_data()
	{
		// Use the same HTML escaping and Unicode normalization as the web forms.
		$cast = new \phpbb\request\type_cast_helper();
		$original = $this->data['new_password'] ?? '';
		$cast->set_var($this->data['new_password'], $original, 'string', true, false);
		if ($this->rules->enabled())
		{
			$cast->set_var($this->data['username'], $this->data['username'] ?? '', 'string', true);
			$name = htmlspecialchars_decode($this->data['username'], ENT_COMPAT);
			$error = $this->rules->error($original, $name) ?:
				$this->rules->name_error(htmlspecialchars_decode($this->data['new_password'], ENT_COMPAT), $name);
			if ($error)
			{
				$this->language->add_lang('password_rules', 'phpbb2plus/community');
				throw new \phpbb\exception\runtime_exception($this->language->lang($error));
			}
		}
		parent::validate_user_data();
	}
}
