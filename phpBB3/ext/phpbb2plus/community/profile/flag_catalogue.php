<?php
namespace phpbb2plus\community\profile;

class flag_catalogue
{
	private $db;
	private $auth;
	private $user;
	private $table;
	private $delegations;
	private $directory;
	private $write_lock;
	private $held = false;

	// Imported catalogue records have a positive source_id. Additional profile-only
	// selections have source_id zero and were never manageable on the source board.
	private const ACTIVE = '(source_id>0 OR catalogue_state=1) AND catalogue_state<>2';

	public function __construct($db, $auth, $user, $prefix, $root)
	{
		$this->db = $db;
		$this->auth = $auth;
		$this->user = $user;
		$this->table = $prefix . 'plus_flags';
		$this->delegations = $prefix . 'plus_jr_delegations';
		$this->directory = $root . 'images/plusflags/';
		$this->write_lock = "CONCAT('plus-country:',MD5(CONCAT(DATABASE(),':','" . $db->sql_escape($this->table) . "')))";
	}

	private function rows($sql)
	{
		$result = $this->db->sql_query($sql);
		$rows = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);
		return $rows;
	}

	public function authorize($write = false)
	{
		if (!defined('IN_ADMIN') || !IN_ADMIN || empty($this->user->data['is_registered']) || empty($this->user->data['session_admin']) ||
			!empty($this->user->data['user_perm_from']) || !$this->auth->acl_get('a_plus_flags')) { throw new \DomainException('NO_AUTH_OPERATION'); }
		if (!$write) { return; }
		$id = (int) $this->user->data['user_id'];
		$sid = $this->db->sql_escape($this->user->session_id);
		$actor = $this->rows('SELECT u.* FROM ' . USERS_TABLE . ' u JOIN ' . SESSIONS_TABLE . " s ON s.session_user_id=u.user_id
			WHERE u.user_id=$id AND s.session_id='$sid' AND s.session_admin=1 LOCK IN SHARE MODE")[0] ?? null;
		if (!$actor || $actor['user_perm_from'] || !in_array((int) $actor['user_type'], array(USER_NORMAL, USER_FOUNDER), true))
		{ throw new \DomainException('NO_AUTH_OPERATION'); }
		if ((int) $actor['user_type'] === USER_FOUNDER) { return; }
		if (!empty($actor['plus_jr_delegate'])) { $this->rows("SELECT user_id FROM {$this->delegations} WHERE user_id=$id LOCK IN SHARE MODE"); }
		$raw = $this->auth->acl_raw_data($id, array('a_', 'a_plus_flags'), 0);
		$options = $raw[$id][0] ?? array();
		$permission = (int) ($options['a_plus_flags'] ?? ACL_NO);
		if ((int) ($options['a_'] ?? ACL_NO) !== ACL_NEVER && $permission !== ACL_NEVER)
		{
			if ($permission === ACL_YES) { return; }
			$actor['is_registered'] = true;
			$this->auth->acl($actor);
			if ($this->auth->delegated_module('phpbb2plus\community\acp\flags_module', 'manage')) { return; }
		}
		throw new \DomainException('NO_AUTH_OPERATION');
	}

	public function listing()
	{
		return $this->rows("SELECT option_value,label,source_file,image FROM {$this->table} WHERE " . self::ACTIVE . ' ORDER BY label,option_value');
	}

	public function find($id)
	{
		return $this->rows("SELECT * FROM {$this->table} WHERE option_value=" . (int) $id . ' AND ' . self::ACTIVE)[0] ?? null;
	}

	public function revision($lock = false)
	{
		$row = $this->rows('SELECT config_value FROM ' . CONFIG_TABLE . " WHERE config_name='plus_flag_revision'" . ($lock ? ' FOR UPDATE' : ''));
		if (!$row) { throw new \RuntimeException('Missing flag catalogue revision'); }
		return (int) $row[0]['config_value'];
	}

	// Native ACP field editing spans several DBAL statements. Both editors use
	// the same connection-owned MariaDB lock until their respective save ends.
	private function enter()
	{
		$row = $this->rows("SELECT GET_LOCK({$this->write_lock},10) AS acquired");
		if (!$row || (int) $row[0]['acquired'] !== 1) { throw new \DomainException('ACP_PLUS_FLAG_CHANGED'); }
		$this->held = true;
	}

	public function leave()
	{
		if ($this->held)
		{
			$this->held = false;
			$this->rows("SELECT RELEASE_LOCK({$this->write_lock})");
		}
	}

	public function native_edit($revision)
	{
		$this->enter();
		if ($this->revision() !== $revision)
		{
			$this->leave();
			throw new \DomainException('ACP_PLUS_FLAG_CHANGED');
		}
		$this->db->sql_query('UPDATE ' . CONFIG_TABLE . " SET config_value=config_value+1 WHERE config_name='plus_flag_revision'");
	}

	private function image($name, $old)
	{
		if ($name === '') { return ''; }
		if ($old && $name === $old['source_file']) { return $old['image']; }
		if (!country_flags::filename($name)) { throw new \DomainException('ACP_PLUS_FLAG_IMAGE_INVALID'); }
		// Imported and bundled images are content-addressed. A newly staged raster
		// may instead have the original flat name used by the site's operator.
		$literal = $this->db->sql_escape($name);
		$images = $this->rows("SELECT image FROM {$this->table} WHERE BINARY source_file='$literal' AND image<>'' ORDER BY catalogue_state,option_value");
		$bundled = json_decode(file_get_contents(dirname(__DIR__) . '/data/flags.json'), true);
		foreach ($bundled ?? array() as $item) { if ($item['source_file'] === $name) { $images[] = $item; break; } }
		$images[] = array('image' => $name);
		foreach ($images as $entry)
		{
			$file = $entry['image'];
			if ($file !== $name && !preg_match('/^[a-f0-9]{64}\.(?:gif|png|jpe?g)$/D', $file)) { continue; }
			$path = $this->directory . $file;
			if (!is_file($path) || is_link($path)) { continue; }
			$details = @getimagesize($path);
			$types = array('png'=>IMAGETYPE_PNG, 'gif'=>IMAGETYPE_GIF, 'jpg'=>IMAGETYPE_JPEG, 'jpeg'=>IMAGETYPE_JPEG);
			if ($details && $details[2] === $types[strtolower(pathinfo($file, PATHINFO_EXTENSION))]) { return $file; }
		}
		throw new \DomainException('ACP_PLUS_FLAG_IMAGE_INVALID');
	}

	private function retire($field_id, $id)
	{
		$this->db->sql_query('DELETE FROM ' . PROFILE_FIELDS_LANG_TABLE . " WHERE field_id=$field_id AND option_id=" . ($id - 1));
		// Reserve this ID so that an old member profile form cannot select a different country.
		$this->db->sql_query("UPDATE {$this->table} SET catalogue_state=2 WHERE option_value=$id");
	}

	public function save($action, $id, $revision, $label, $name)
	{
		$id = (int) $id;
		$this->enter();
		$this->db->sql_transaction('begin');
		try
		{
			if ($this->revision(true) !== $revision) { throw new \DomainException('ACP_PLUS_FLAG_CHANGED'); }
			$this->authorize(true);
			$field = $this->rows('SELECT field_id,field_novalue FROM ' . PROFILE_FIELDS_TABLE .
				" WHERE field_ident='plus_country' AND field_type='profilefields.type.dropdown' FOR UPDATE")[0] ?? null;
			if (!$field || (int) $field['field_novalue'] !== 1) { throw new \DomainException('ACP_PLUS_FLAG_NATIVE'); }
			$fid = (int) $field['field_id'];
			// Native option_id is one below the value stored in a member's profile.
			$options = $this->rows('SELECT lang_id,option_id,lang_value FROM ' . PROFILE_FIELDS_LANG_TABLE . " WHERE field_id=$fid ORDER BY option_id,lang_id FOR UPDATE");
			$languages = array();
			$labels = array();
			$maximum = 1;
			foreach ($options as $option)
			{
				$value = 1 + (int) $option['option_id'];
				$maximum = max($maximum, $value);
				if ($value === 1) { $languages[] = $option['lang_id']; }
				$labels[$value][$option['lang_id']] = $option['lang_value'];
			}
			$matches = static function ($value, $label) use ($labels, $languages) {
				$current = $labels[$value] ?? array();
				return count($current) === count($languages) && !array_diff($languages, array_keys($current)) && !array_diff($current, array($label));
			};
			$old = $id > 0 ? $this->find($id) : null;
			if (!$languages || !in_array($action, array('create','edit','delete'), true) || ($action === 'create' ? $id !== 0 : !$old))
			{ throw new \DomainException('ACP_PLUS_FLAG_CHANGED'); }
			if ($old && !$matches($id, $old['label'])) { throw new \DomainException('ACP_PLUS_FLAG_NATIVE'); }
			if ($action === 'delete')
			{
				$this->retire($fid, $id);
			}
			else
			{
				if ($label === '' || utf8_strlen($label) > 25 || strpos($label, "\0") !== false) { throw new \DomainException('ACP_PLUS_FLAG_NAME_INVALID'); }
				$encoded = utf8_encode_ucr(utf8_htmlspecialchars($label));
				if (utf8_strlen($encoded) > 255) { throw new \DomainException('ACP_PLUS_FLAG_NAME_INVALID'); }
				$data = array('label' => $encoded, 'source_file' => $name, 'image' => $this->image($name, $old));
				if ($action === 'create')
				{
					$last = $this->rows("SELECT MAX(option_value) AS n FROM {$this->table}");
					$id = 1 + max($maximum, (int) $last[0]['n']);
					$this->db->sql_query("INSERT INTO {$this->table} " . $this->db->sql_build_array('INSERT', $data + array('option_value'=>$id, 'source_id'=>0, 'catalogue_state'=>1)));
					foreach ($languages as $language)
					{
						$this->db->sql_query('INSERT INTO ' . PROFILE_FIELDS_LANG_TABLE . ' ' . $this->db->sql_build_array('INSERT', array(
							'field_id'=>$fid, 'lang_id'=>(int) $language, 'option_id'=>$id - 1, 'field_type'=>'profilefields.type.dropdown', 'lang_value'=>$encoded)));
					}
				}
				else
				{
					$this->db->sql_query("UPDATE {$this->table} SET " . $this->db->sql_build_array('UPDATE', $data) . " WHERE option_value=$id");
					$this->db->sql_query('UPDATE ' . PROFILE_FIELDS_LANG_TABLE . ' SET ' . $this->db->sql_build_array('UPDATE', array('lang_value'=>$encoded)) .
						" WHERE field_id=$fid AND option_id=" . ($id - 1));
				}
				// The converter also preserves filenames found only in member profiles.
				// Once offered again, the source used the same image for these members.
				if ($name !== '' && (!$old || $old['source_file'] !== $name))
				{
					$orphans = $this->rows("SELECT option_value,label FROM {$this->table} WHERE source_id=0 AND catalogue_state=0
						AND BINARY source_file='" . $this->db->sql_escape($name) . "'");
					foreach ($orphans as $orphan)
					{
						$from = (int) $orphan['option_value'];
						if (!$matches($from, $orphan['label'])) { throw new \DomainException('ACP_PLUS_FLAG_NATIVE'); }
						$this->db->sql_query('UPDATE ' . PROFILE_FIELDS_DATA_TABLE . " SET pf_plus_country=$id WHERE pf_plus_country=$from");
						$this->retire($fid, $from);
					}
				}
			}
			if ($old && ($action === 'delete' || $old['source_file'] !== $name))
			{
				$replacement = $action === 'delete' || $name === '' ? 1 : $id;
				if ($old['source_file'] !== '')
				{
					$aliases = $this->rows("SELECT option_value,label FROM {$this->table} WHERE " . self::ACTIVE . ' AND BINARY source_file=' .
						"'" . $this->db->sql_escape($old['source_file']) . "' AND option_value<>$id ORDER BY option_value");
					if ($aliases)
					{
						$replacement = null;
						foreach ($aliases as $alias)
						{
							if ($matches((int) $alias['option_value'], $alias['label'])) { $replacement = (int) $alias['option_value']; break; }
						}
						if ($replacement === null) { throw new \DomainException('ACP_PLUS_FLAG_NATIVE'); }
					}
				}
				if ($replacement !== $id) { $this->db->sql_query('UPDATE ' . PROFILE_FIELDS_DATA_TABLE . " SET pf_plus_country=$replacement WHERE pf_plus_country=$id"); }
			}
			$this->db->sql_query('UPDATE ' . CONFIG_TABLE . ' SET config_value=' . ($revision + 1) . " WHERE config_name='plus_flag_revision'");
			$this->db->sql_transaction('commit');
		}
		catch (\Throwable $e) { $this->db->sql_transaction('rollback'); throw $e; }
		finally { $this->leave(); }
	}
}
