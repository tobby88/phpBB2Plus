<?php
namespace phpbb2plus\arcade;

class ext extends \phpbb\extension\base
{
	public function is_enableable()
	{
		return defined('JSON_THROW_ON_ERROR');
	}
}
