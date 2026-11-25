<?php
if (!defined('IN_PHPBB')) { exit; }

function plus_news_image($id, $reference)
{
	if ($reference === '' || $reference === null) { return ''; }
	$relative = trim((string) get_config_value('news_path'),'/');
	$picture = plus_profile_image('templates/fisubsilversh/' . $relative, $reference);
	if (!$picture) { plus_profile_media_issue('news', $id, $reference, 'unavailable'); return ''; }
	return plus_public_bitmap($picture,'plusnews');
}

function plus_finish_news()
{
	global $db, $table_prefix, $config, $convert;
	$cats = $table_prefix . 'plus_news_cats';
	// Reserve a missing original ID without publishing the topic under a later category.
	$db->sql_query("INSERT INTO $cats (category_id,name,image,active)
		SELECT DISTINCT t.plus_news_cat,'','',0 FROM " . TOPICS_TABLE . " t LEFT JOIN $cats c ON c.category_id=t.plus_news_cat
		WHERE t.plus_news_cat>0 AND c.category_id IS NULL");
	// Unassigned artwork was available in the source category editor too.
	$catalogue = $table_prefix . 'plus_news_images';
	$directory = 'templates/fisubsilversh/' . trim((string) get_config_value('news_path'),'/');
	try { $source_dir = plus_media_path($convert->options['forum_path'],$directory,true); }
	catch (\RuntimeException $e) { $source_dir = null; }
	$images = array();
	foreach ($source_dir ? scandir($source_dir) : array() as $entry)
	{
		$image = plus_profile_image($directory,$entry);
		if (!$image) { continue; }
		$asset = plus_public_bitmap($image,'plusnews');
		if (!isset($images[$asset])) { $images[$asset] = array('asset'=>$asset,'label'=>truncate_string(utf8_htmlspecialchars($entry),255,255,false)); }
	}
	$db->sql_transaction('begin');
	try
	{
		$db->sql_query("DELETE FROM $catalogue");
		foreach (array_chunk($images,100) as $batch) { $db->sql_multi_insert($catalogue,array_values($batch)); }
		$db->sql_transaction('commit');
	}
	catch (\Throwable $e) { $db->sql_transaction('rollback'); throw $e; }

	// Some historical ACP versions saved entities, others saved plain text.
	foreach (array('description'=>'news_rss_desc','category'=>'news_rss_cat','image_desc'=>'news_rss_image_desc') as $new=>$old)
	{
		$config->set('plus_news_feed_' . $new,truncate_string(plus_convert_byline(get_config_value($old)),255,255,false));
	}
	$image = html_entity_decode(trim((string) get_config_value('news_rss_image')),ENT_QUOTES,'UTF-8');
	if ($image !== '' && !preg_match('~^https?://~i',$image))
	{
		$source = $image;
		$board_path = '/' . trim((string) get_config_value('script_path'),'/');
		if ($board_path !== '/' && strpos($image,$board_path . '/') === 0) { $image = substr($image,strlen($board_path)); }
		if (substr($image,0,2) === './') { $image = substr($image,2); }
		if (substr($image,0,1) === '/' && substr($image,0,2) !== '//') { $image = substr($image,1); }
		$directory = dirname($image);
		$bitmap = $image !== '' && strpos($image,'//') === false ? plus_profile_image($directory === '.' ? '' : $directory,basename($image),true) : false;
		if (!$bitmap) { plus_profile_media_issue('news-feed',0,$source,'unavailable'); }
		$image = $bitmap ? 'images/plusnews/' . plus_public_bitmap($bitmap,'plusnews') : '';
	}
	$config->set('plus_news_feed_image',truncate_string(utf8_htmlspecialchars($image),255,255,false));
}
