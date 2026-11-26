<?php
if (!defined('IN_PHPBB')) { exit; }

function plus_inline_edit_settings()
{
	global $config;
	$source = get_config();
	foreach (array('use_ajax_edit' => 'plus_inline_edit', 'use_ajax_preview' => 'plus_full_preview') as $key => $setting)
	{
		foreach (array('' => 1, '_over' => 0) as $suffix => $default)
		{
			$source_key = $key . $suffix;
			$config->set($setting . $suffix, isset($source[$source_key]) ? (int) ((string) $source[$source_key] === '1') : $default);
		}
	}
}
