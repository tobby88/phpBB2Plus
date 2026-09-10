<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_pm_staging.php';

// Attachment writers cooperate across web workers/hosts, independently of the
// table engine. Every protected query uses the connection that owns this lock.
// A lost connection therefore cannot continue publishing a stale snapshot.
class attach_mutation_lock
{
	var $connection = null;
	var $acquired = false;

	function __construct($database, $wait = true)
	{
		try
		{
			if (method_exists($database, 'sql_dedicated_connection'))
			{
				$this->connection = $database->sql_dedicated_connection();
			}
			else
			{
				// Compatibility for alternate adapters; never access a removed field.
				if (!isset($database->password)) { return; }
				$this->connection = new sql_db(preg_replace('/^p:/', '', $database->server), $database->user, $database->password, $database->dbname, false);
			}
			if (!$this->connection || !$this->connection->db_connect_id) { $this->connection = null; return; }
			register_shutdown_function(array($this, 'release'));
			$name = 'attachment:' . md5($database->dbname . "\0" . ATTACHMENTS_TABLE);
			// Optional statistics may skip a busy writer without delaying readers.
			$timeout = $wait === false ? 0 : 10;
			$result = $this->connection->sql_query("SELECT GET_LOCK('" . $name . "', " . $timeout . ") AS acquired");
			if ($result)
			{
				$row = $this->connection->sql_fetchrow($result);
				$this->connection->sql_freeresult($result);
				$this->acquired = isset($row['acquired']) && ($row['acquired'] === 1 || $row['acquired'] === '1');
			}
		}
		catch (Exception $exception) { $this->acquired = false; }
		catch (Error $exception) { $this->acquired = false; }
		if (!$this->acquired) { $this->release(); }
	}

	function release()
	{
		$connection = $this->connection;
		$this->connection = null; $this->acquired = false;
		if ($connection !== null)
		{
			try { $connection->sql_close(); }
			catch (Exception $exception) {}
			catch (Error $exception) {}
		}
	}
}

function attach_require_mutation_lock($database)
{
	global $lang;
	$lock = new attach_mutation_lock($database);
	if (!$lock->acquired) { message_die(GENERAL_ERROR, $lang['Attachment_storage_busy']); }
	return $lock;
}

function attach_message_exists($database, $type, $id)
{
	global $lang;
	if ((int) $id <= 0 || !in_array($type, array('post', 'pm'), true)) { return false; }
	$table = $type === 'pm' ? PRIVMSGS_TABLE : POSTS_TABLE;
	$key = $type === 'pm' ? 'privmsgs_id' : 'post_id';
	$result = $database->sql_query('SELECT ' . $key . ' FROM ' . $table . ' WHERE ' . $key . ' = ' . (int) $id);
	if (!$result) { message_die(GENERAL_ERROR, $lang['Attachment_publish_unavailable']); }
	$exists = $database->sql_numrows($result) > 0;
	$database->sql_freeresult($result);
	return $exists;
}

// Must run under the mutation lock. A retained description also reserves its
// physical name during/after failed cleanup: stale forms cannot publish a new
// reference while the old operation is deleting that file, even if its DB
// connection is lost between the reference check and filesystem cleanup.
function attach_require_unpublished_file($database, $filename)
{
	global $lang;
	if (attach_ftp_listing_entry($filename, '0') === false ||
		in_array(strtolower($filename), array('index.php', '.htaccess', '.htpasswd'), true))
	{
		message_die(GENERAL_ERROR, $lang['Attachment_publish_unavailable']);
	}
	$sql = 'SELECT attach_id FROM ' . ATTACHMENTS_DESC_TABLE . " WHERE physical_filename = '" . $database->sql_escape($filename) . "' LIMIT 1";
	$result = $database->sql_query($sql);
	if (!$result) { message_die(GENERAL_ERROR, $lang['Attachment_publish_unavailable']); }
	$exists = $database->sql_numrows($result) > 0;
	$database->sql_freeresult($result);
	if ($exists || !attachment_exists($filename))
	{
		message_die(GENERAL_ERROR, $lang['Attachment_publish_unavailable']);
	}
}

// Synchronize flags before releasing the writer lock, never from a stale form
// after another request may already have removed the last attachment.
function attach_sync_message($database, $message_type, $message_id)
{
	global $lang;
	$message_id = (int) $message_id;
	if ($message_type === 'post')
	{
		$result = $database->sql_query('SELECT topic_id FROM ' . POSTS_TABLE . ' WHERE post_id = ' . $message_id);
		if (!$result) { message_die(GENERAL_ERROR, $lang['Attachment_publish_unavailable']); }
		$row = $database->sql_fetchrow($result);
		$database->sql_freeresult($result);
		if ($row) { attachment_sync_topic($row['topic_id'], $database); }
	}
	else
	{
		$result = $database->sql_query('SELECT attach_id FROM ' . ATTACHMENTS_TABLE . ' WHERE privmsgs_id = ' . $message_id . ' LIMIT 1');
		if (!$result) { message_die(GENERAL_ERROR, $lang['Attachment_publish_unavailable']); }
		$flag = $database->sql_numrows($result) ? 1 : 0;
		$database->sql_freeresult($result);
		if (!$database->sql_query('UPDATE ' . PRIVMSGS_TABLE . ' SET privmsgs_attachment = ' . $flag . ' WHERE privmsgs_id = ' . $message_id))
		{
			message_die(GENERAL_ERROR, $lang['Attachment_publish_unavailable']);
		}
	}
}
