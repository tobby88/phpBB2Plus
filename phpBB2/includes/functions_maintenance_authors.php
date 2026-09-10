<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_maintenance_dates.php';

function dbmtnc_repair_authors($database, $request)
{
	global $userdata;
	if (!is_array($request) || !isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST'
		|| empty($userdata['session_id']) || !is_string($userdata['session_id']) || !isset($request['sid']) || !is_string($request['sid'])
		|| !hash_equals($userdata['session_id'], $request['sid'])) { phpbb_acl_error('Session_invalid'); }
	$lock = new attach_mutation_lock($database);
	if (!$lock->acquired) { phpbb_acl_error('Attachment_storage_busy'); }
	try
	{
		$db = new PhpbbAclDatabase($lock->connection, 'Maintenance_author_failed');
		dbmtnc_date_actor($db);
		$output = array('posts' => 0, 'topics' => 0, 'skipped' => 0);
		foreach (array('posts' => array(POSTS_TABLE, 'post_id', 'poster_id'), 'topics' => array(TOPICS_TABLE, 'topic_id', 'topic_poster')) as $kind => $fields)
		{
			list($table, $id_field, $author_field) = $fields;
			// Guest/deleted attribution remains valid even if its reserved user row
			// needs separate repair. Never erase a stored guest author name.
			$invalid = $table . '.' . $author_field . ' <> ' . DELETED
				. ' AND NOT EXISTS (SELECT 1 FROM ' . USERS_TABLE . ' current_author WHERE current_author.user_id = ' . $table . '.' . $author_field . ')';
			$bound = phpbb_acl_rows($db, 'SELECT COALESCE(MAX(' . $id_field . '),0) AS last_id FROM ' . $table);
			$last = (int) $bound[0]['last_id']; $after = 0;
			do
			{
				$rows = phpbb_acl_rows($db, 'SELECT ' . $id_field . ' AS id,' . $author_field . ' AS author FROM ' . $table
					. ' WHERE ' . $id_field . ' > ' . $after . ' AND ' . $id_field . ' <= ' . $last . ' AND ' . $invalid . ' ORDER BY ' . $id_field . ' LIMIT 100');
				foreach ($rows as $row)
				{
					$after = phpbb_acl_id($row['id']); $actor = dbmtnc_date_actor($db);
					$replacement = (string) DELETED;
					if ($kind === 'topics')
					{
						// Resolve the current first post inside the UPDATE, not from a
						// stale SELECT. A missing/invalid source author stays anonymous.
						$replacement = 'COALESCE((SELECT CASE WHEN first_post.poster_id = ' . DELETED
							. ' OR EXISTS (SELECT 1 FROM ' . USERS_TABLE . ' first_author WHERE first_author.user_id = first_post.poster_id)'
							. ' THEN first_post.poster_id ELSE ' . DELETED . ' END FROM ' . POSTS_TABLE . ' first_post'
							. ' WHERE first_post.topic_id = ' . $table . '.topic_id ORDER BY first_post.post_id LIMIT 1),' . DELETED . ')';
					}
					$db->sql_query('UPDATE ' . $table . ' SET ' . $author_field . ' = ' . $replacement . ' WHERE ' . $id_field . ' = ' . $after
						. ' AND ' . $author_field . ' = ' . (int) $row['author'] . ' AND ' . $invalid . ' AND ' . $actor['guard']);
					if ((int) $db->sql_affectedrows() === 1) { $output[$kind]++; } else { $output['skipped']++; }
					dbmtnc_date_actor($db);
				}
			} while (count($rows) === 100 && $after < $last);
		}
		dbmtnc_date_actor($db);
		return $output;
	}
	finally { $lock->release(); }
}
