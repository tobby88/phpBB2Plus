<?php
namespace phpbb2plus\community\event;

use phpbb2plus\community\service\former_members;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class former_names implements EventSubscriberInterface
{
	private $names;
	private $db;
	private $prefix;
	private $original_names = array();

	public function __construct($names, $db, $prefix)
	{
		$this->names = $names;
		$this->db = $db;
		$this->prefix = $prefix;
	}

	static public function getSubscribedEvents()
	{
		return array('core.text_formatter_s9e_render_before' => 'quote_attribution',
			'core.delete_posts_in_transaction_before' => 'forget_bylines',
			'core.search_modify_submit_parameters' => 'search',
			'core.search_modify_param_before' => 'search',
			'core.delete_user_before' => array('retain', -100),
			'core.delete_user_after' => array('restore_event_names', 100));
	}

	public function forget_bylines($event)
	{
		$tables = $event['table_ary'];
		$tables[] = $this->prefix . 'plus_bylines';
		$event['table_ary'] = $tables;
	}

	public function quote_attribution($event)
	{
		if (!$this->names->hidden() || strpos($event['xml'], '<QUOTE ') === false) { return; }
		$event['xml'] = \s9e\TextFormatter\Utils::replaceAttributes($event['xml'], 'QUOTE', function ($attributes) {
			if (isset($attributes['author']) && $this->names->known($attributes['author'], $attributes['user_id'] ?? 0))
			{
				$attributes['author'] = $this->names->label();
				unset($attributes['profile_url'], $attributes['user_id'], $attributes['url']);
			}
			return $attributes;
		});
	}

	public function search($event)
	{
		// The later event also includes "search within these results" keywords.
		if ($this->names->hidden() && $this->names->matches_search($event['keywords'])) { trigger_error('NO_SEARCH_RESULTS'); }
	}

	public function retain($event)
	{
		$rows = $event['user_rows'];
		$table = $this->prefix . 'plus_bylines';
		$ledger = $this->prefix . 'plus_former_names';
		foreach ($rows as $id => &$row)
		{
			$id = (int) $id;
			if ($id <= ANONYMOUS) { continue; }
			$name = $row['username'];
			$raw = html_entity_decode($name, ENT_QUOTES, 'UTF-8');
			$clean = former_members::clean($name);
			$this->db->sql_query('INSERT INTO ' . $ledger . ' ' . $this->db->sql_build_array('INSERT', array(
				'source_id' => 0, 'native_id' => $id, 'name_hash' => hash('sha256', $raw), 'name_key' => hash('sha256', $clean),
				'name_clean' => $event['retain_username'] ? utf8_encode_ucr($clean) : '', 'author_name' => $event['retain_username'] ? utf8_encode_ucr($raw) : '', 'recorded_at' => time())));
			// All writes share the transaction phpBB opened for the account deletion.
			// A native request to discard bylines takes precedence over member display.
			if ($event['retain_username'])
			{
				$columns = array('editor_name' => 'post_edit_user');
				if ($event['mode'] === 'retain') { $columns['author_name'] = 'poster_id'; }
				foreach ($columns as $column => $source)
				{
					$this->db->sql_query("INSERT INTO $table (post_id,author_name,editor_name,author_clean)
						SELECT p.post_id,'','','' FROM " . POSTS_TABLE . " p LEFT JOIN $table b ON b.post_id=p.post_id
						WHERE p.$source=$id AND b.post_id IS NULL");
					$change = array($column=>$name);
					if ($column === 'author_name')
					{
						$change['author_clean'] = utf8_encode_ucr($clean);
						$change['author_key'] = $clean === '' ? '' : hash('sha256',$clean);
					}
					$this->db->sql_query("UPDATE $table SET " . $this->db->sql_build_array('UPDATE',$change) .
						' WHERE post_id IN (SELECT post_id FROM ' . POSTS_TABLE . " WHERE $source=$id)");
				}
				// phpBB writes this byline to denormalized native forum/topic caches as well.
				$this->original_names[$id] = $name;
				$row['username'] = $this->names->label();
			}
			$this->db->sql_query('UPDATE ' . $this->prefix . 'plus_shouts SET ' . $this->db->sql_build_array('UPDATE',
				array('author_id' => ANONYMOUS, 'author_removed' => 1, 'author_name' => $event['retain_username'] ? $name : ''))
				. ' WHERE author_id=' . $id);
		}
		unset($row);
		$event['user_rows'] = $rows;
	}

	public function restore_event_names($event)
	{
		$rows = $event['user_rows'];
		foreach ($rows as $id => &$row)
		{
			if (isset($this->original_names[$id]))
			{
				$row['username'] = $this->original_names[$id];
				unset($this->original_names[$id]);
			}
		}
		unset($row);
		$event['user_rows'] = $rows;
	}
}
