<?php
if (!defined('IN_PHPBB')) { exit; }

function plus_credit_visible($value)
{
	return strcasecmp((string) $value, 'No') === 0 ? 1 : 0;
}

function plus_credit_text($value)
{
	return \phpbb2plus\community\service\credit_entries::encode($value);
}

function plus_credits_schema($prefix)
{
	$schema = array('target' => $prefix . 'plus_credits', 'primary' => 'hacks_list.hack_id',
		array('credit_id', 'hacks_list.hack_id', 'intval'), array('visible', 'hacks_list.hack_hide', 'plus_credit_visible'),
		array('file_mtime', 'hacks_list.hack_file_mtime', 'intval'), array('added_time', 'hacks_list.hack_add_date', 'intval'));
	foreach (array('title' => 'hack_name', 'description' => 'hack_desc', 'author' => 'hack_author',
		'contact_email' => 'hack_author_email', 'author_website' => 'hack_author_website', 'version_text' => 'hack_version',
		'download_url' => 'hack_download_url', 'source_file' => 'hack_file') as $dest => $source)
	{
		$schema[] = array($dest, 'hacks_list.' . $source, 'plus_credit_text');
	}
	return $schema;
}
