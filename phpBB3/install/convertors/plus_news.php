<?php
if (!defined('IN_PHPBB')) { exit; }

function plus_news_image($id, $reference)
{
	global $phpbb_root_path;
	if ($reference === '' || $reference === null) { return ''; }
	$relative = trim((string) get_config_value('news_path'),'/');
	$picture = plus_profile_image('templates/fisubsilversh/' . $relative, $reference);
	if (!$picture) { plus_profile_media_issue('news', $id, $reference, 'unavailable'); return ''; }
	$filename = hash_file('sha256',$picture['source']) . '.' . $picture['ext'];
	$directory = plus_media_path($phpbb_root_path, 'images/plusnews', true);
	plus_copy_media($picture['source'],$directory . '/' . $filename);
	return $filename;
}

function plus_news_orphans()
{
	global $db, $table_prefix;
	$cats = $table_prefix . 'plus_news_cats';
	// Reserve a missing original ID without publishing the topic under a later category.
	$db->sql_query("INSERT INTO $cats (category_id,name,image,active)
		SELECT DISTINCT t.plus_news_cat,'','',0 FROM " . TOPICS_TABLE . " t LEFT JOIN $cats c ON c.category_id=t.plus_news_cat
		WHERE t.plus_news_cat>0 AND c.category_id IS NULL");
}
