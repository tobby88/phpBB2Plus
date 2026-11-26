<?php
namespace phpbb2plus\community\console;

/** Apply the same account rules to both interactive answers and command options. */
class user_add extends \phpbb\console\command\user\add
{
	private $rules;

	public function set_rules($rules) { $this->rules = $rules; }

	protected function validate_user_data()
	{
		if ($this->rules->enabled())
		{
			// The native CLI receives plain strings. Encode them just as the
			// registration form does so that e.g. an ampersand also works at web login.
			$cast = new \phpbb\request\type_cast_helper();
			$original = $this->data['new_password'] ?? '';
			foreach (array('username', 'new_password') as $field)
			{
				$cast->set_var($this->data[$field], $this->data[$field] ?? '', 'string', true);
			}
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
