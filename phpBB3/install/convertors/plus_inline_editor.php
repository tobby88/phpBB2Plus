<?php
if (!defined('IN_PHPBB')) { exit; }

function plus_inline_edit_settings()
{
	global $config;
	$source = get_config();
	foreach (array('use_ajax_edit' => 1, 'use_ajax_edit_over' => 0) as $key => $default)
	{
		$setting = $key === 'use_ajax_edit' ? 'plus_inline_edit' : 'plus_inline_edit_over';
		$config->set($setting, isset($source[$key]) ? (int) ((string) $source[$key] === '1') : $default);
	}
}
