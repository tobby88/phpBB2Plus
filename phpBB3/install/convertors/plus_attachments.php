<?php
if (!defined('IN_PHPBB')) { exit; }

require_once __DIR__ . '/plus_media.php';

function plus_check_attachment_source()
{
	global $src_db, $convert, $config;
	$prefix = $convert->src_table_prefix;
	$result = $src_db->sql_query_limit("SELECT a.attach_id FROM {$prefix}attachments a
		LEFT JOIN {$prefix}attachments_desc d ON d.attach_id=a.attach_id WHERE d.attach_id IS NULL", 1);
	$missing = $src_db->sql_fetchfield('attach_id');
	$src_db->sql_freeresult($result);
	if ($missing !== false) { throw new \RuntimeException('Missing source description for attachment ' . (int) $missing); }
	$result = $src_db->sql_query("SELECT config_value FROM {$prefix}attachments_config WHERE config_name='disable_mod'");
	$disabled = (int) $src_db->sql_fetchfield('config_value');
	$src_db->sql_freeresult($result);
	if ($disabled)
	{
		$config->set('allow_attachments', 0);
		$config->set('allow_pm_attach', 0);
	}
}

/** Every source association becomes a native attachment owned by its post or PM. */
function plus_attachment_parents($id, $copies)
{
	global $src_db, $convert;
	$p = $convert->src_table_prefix;
	$sql = "SELECT DISTINCT a.post_id,a.privmsgs_id,a.user_id_1,a.user_id_2,m.privmsgs_type FROM {$p}attachments a
		LEFT JOIN {$p}privmsgs m ON m.privmsgs_id=a.privmsgs_id WHERE a.attach_id=" . $id . ' ORDER BY a.privmsgs_id,a.post_id,a.user_id_1,a.user_id_2';
	$result = $src_db->sql_query($sql);
	$parents = array();
	while ($row = $src_db->sql_fetchrow($result))
	{
		$post = (int) $row['post_id'];
		$pm = (int) $row['privmsgs_id'];
		if ($post && $pm) { throw new \RuntimeException('Ambiguous content association for attachment ' . $id); }
		if (!$post && !$pm) { continue; }
		$key = $pm . ':' . $post;
		if (!isset($parents[$key])) { $parents[$key] = array('in_message'=>$pm ? 1 : 0,'parent_id'=>$pm ?: $post,'payers'=>array()); }
		$parents[$key]['payers'][] = array('source_sender'=>(int) $row['user_id_1'],'source_recipient'=>(int) $row['user_id_2'],
			'pm_state'=>$pm && $row['privmsgs_type'] !== null ? (int) $row['privmsgs_type'] : -1);
	}
	$src_db->sql_freeresult($result);
	if (!$parents) { $parents[] = array('in_message'=>0,'parent_id'=>0,'payers'=>array()); }
	if ($copies && count($parents) === 1) { return array(); }
	$matched = $unassigned = array();
	foreach ($parents as $parent)
	{
		$parent['owner'] = plus_attachment_parent($parent);
		if ($parent['owner']) { $matched[] = $parent; }
		else { $unassigned[] = $parent; }
	}
	// A shared file's original ID and download count belong to a surviving
	// association even if a broken reference sorts before it on the source.
	$parents = array_merge($matched, $unassigned);
	return $copies ? array_slice($parents, 1) : array_slice($parents, 0, 1);
}

function plus_attachment_parent($parent)
{
	global $db;
	if (!$parent['parent_id']) { return false; }
	$sql = $parent['in_message']
		? 'SELECT author_id AS poster_id, 0 AS topic_id FROM ' . PRIVMSGS_TABLE . ' WHERE msg_id = '
		: 'SELECT poster_id, topic_id FROM ' . POSTS_TABLE . ' WHERE post_id = ';
	$result = $db->sql_query($sql . $parent['parent_id']);
	$row = $db->sql_fetchrow($result);
	$db->sql_freeresult($result);
	return $row;
}

/** Reserve historical IDs in the first pass before allocating any IDs for shared files. */
function plus_attachment_primary($id)
{
	plus_import_attachment((int) $id, false);
}

function plus_attachment_copies($id)
{
	plus_import_attachment((int) $id, true);
}

function plus_import_attachment($id, $copies)
{
	global $db, $src_db, $convert, $config, $phpbb_root_path, $table_prefix;
	$parents = plus_attachment_parents($id, $copies);
	if (!$parents) { return; }

	$result = $src_db->sql_query('SELECT physical_filename, real_filename, download_count, comment, extension, mimetype, filetime, thumbnail
		FROM ' . $convert->src_table_prefix . 'attachments_desc WHERE attach_id = ' . $id);
	$file = $src_db->sql_fetchrow($result);
	$src_db->sql_freeresult($result);
	if (!$file) { throw new \RuntimeException('Missing source metadata for attachment ' . $id); }
	$name = $file['physical_filename'];
	if ($name === '' || strpbrk($name, "/\\") !== false) { throw new \RuntimeException('Invalid source filename for attachment ' . $id); }
	$directory = plus_media_path($convert->options['forum_path'], rtrim($convert->convertor['upload_path'], '/'), true);
	$target = plus_media_path($phpbb_root_path, trim($config['upload_path'], '/'), true);
	if ($target === $directory || empty($config['plus_media_key'])) { throw new \RuntimeException('Use a separate, initialized target for the attachment conversion.'); }
	$source = plus_media_path($directory, $name);
	$thumb = $file['thumbnail'] ? plus_media_path($directory, 'thumbs/t_' . $name) : null;
	$map = $table_prefix . 'plus_attach_refs';

	foreach ($parents as $parent)
	{
		$reference = array('source_id'=>$id,'in_message'=>$parent['in_message'],'parent_id'=>$parent['parent_id']);
		$owner = $parent['owner'];
		if (!$owner)
		{
			// Keep the historical reference in the map. The native file has
			// no post, mailbox or owner who could claim it as a draft upload.
			$parent['parent_id'] = $parent['in_message'] = 0;
			$parent['payers'] = array();
			$owner = array('poster_id' => 0, 'topic_id' => 0);
		}
		$physical = 'plus_' . hash_hmac('sha256', implode(':', $reference), $config['plus_media_key']);
		plus_copy_media($source, $target . '/' . $physical);
		if ($thumb) { plus_copy_media($thumb, $target . '/thumb_' . $physical); }
		$db->sql_transaction('begin');
		try
		{
			$result = $db->sql_query('SELECT attach_id FROM ' . $map . ' WHERE ' . $db->sql_build_array('SELECT', $reference));
			$exists = $db->sql_fetchfield('attach_id');
			$db->sql_freeresult($result);
			if (!$exists)
			{
				$data = array('post_msg_id' => $parent['parent_id'], 'in_message' => $parent['in_message'],
					'topic_id' => (int) $owner['topic_id'], 'poster_id' => (int) $owner['poster_id'], 'is_orphan' => $parent['parent_id'] ? 0 : 1,
					'physical_filename' => $physical, 'real_filename' => plus_convert_byline(utf8_basename(str_replace('\\', '/', $file['real_filename']))),
					'download_count' => $copies ? 0 : (int) $file['download_count'], 'attach_comment' => plus_convert_byline($file['comment']),
					'extension' => strtolower(trim((string) $file['extension'])),
					'mimetype' => preg_match('~^[\w.+-]+/[\w.+-]+$~D', (string) $file['mimetype']) ? $file['mimetype'] : 'application/octet-stream',
					'filesize' => filesize($source), 'filetime' => (int) $file['filetime'], 'thumbnail' => $thumb ? 1 : 0);
				if (!$copies) { $data['attach_id'] = $id; }
				$db->sql_query('INSERT INTO ' . ATTACHMENTS_TABLE . ' ' . $db->sql_build_array('INSERT', $data));
				$reference['attach_id'] = $copies ? (int) $db->sql_nextid() : $id;
				$db->sql_query('INSERT INTO ' . $map . ' ' . $db->sql_build_array('INSERT', $reference));
				$charges = array();
				foreach ($parent['payers'] as $payer)
				{
					$charges[] = $payer + array('attach_id'=>$reference['attach_id'],'sender'=>plus_quota_member($payer['source_sender']),
						'recipient'=>plus_quota_member($payer['source_recipient']));
				}
				foreach (array_chunk($charges,100) as $batch) { $db->sql_multi_insert($table_prefix . 'plus_attach_payers',$batch); }
				if ($parent['in_message'])
				{
					$db->sql_query('UPDATE ' . PRIVMSGS_TABLE . ' SET message_attachment = 1 WHERE msg_id = ' . $parent['parent_id']);
				}
				elseif ($parent['parent_id'])
				{
					$db->sql_query('UPDATE ' . POSTS_TABLE . ' SET post_attachment = 1 WHERE post_id = ' . $parent['parent_id']);
					$db->sql_query('UPDATE ' . TOPICS_TABLE . ' SET topic_attachment = 1 WHERE topic_id = ' . (int) $owner['topic_id']);
				}
			}
			$db->sql_transaction('commit');
		}
		catch (\Throwable $error)
		{
			$db->sql_transaction('rollback');
			throw $error;
		}
	}
}
