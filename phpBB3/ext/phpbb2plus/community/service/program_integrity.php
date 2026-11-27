<?php
namespace phpbb2plus\community\service;

class program_integrity
{
	private $db;
	private $table;
	private $files;
	public function __construct($db, $table, program_files $files) { $this->db = $db; $this->table = $table; $this->files = $files; }

	public function state()
	{
		$result = $this->db->sql_query("SELECT * FROM {$this->table} WHERE state_id=1");
		$row = $this->db->sql_fetchrow($result); $this->db->sql_freeresult($result);
		if (!$row) { throw new \DomainException('PLUS_CT_FILES_STATE'); }
		foreach (array('reference_data', 'scan_data') as $field)
		{
			$data = $row[$field] === '' ? array() : json_decode($row[$field], true);
			if (!is_array($data) || count($data) > 30000) { throw new \DomainException('PLUS_CT_FILES_STATE'); }
			foreach ($data as $path => $value)
			{
				if (!program_files::program_name($path) || ($value !== null && !($field === 'scan_data' && $value === false) &&
					(!is_string($value) || !preg_match('/^[a-f0-9]{64}$/D', $value)))) { throw new \DomainException('PLUS_CT_FILES_STATE'); }
			}
			$row[$field] = $data;
		}
		return $row;
	}

	public function catalog() { return $this->files->catalog(); }
	public function can_adopt(array $row, array $catalog)
	{
		return $row['scan_end'] && $row['package_id'] === $catalog['package'] &&
			(int) $row['file_cursor'] === count($catalog['paths']) && array_keys($row['scan_data']) === $catalog['paths'] &&
			!in_array(false, $row['scan_data'], true) && (bool) array_filter($row['scan_data']) &&
			(!$row['reference_time'] || $row['reference_data'] !== $row['scan_data']);
	}

	public function change($action, int $revision, callable $allowed)
	{
		$catalog = $this->catalog(); $row = $this->state();
		if ((int) $row['revision'] !== $revision) { throw new \DomainException('PLUS_CT_FILES_STALE'); }
		switch ($action)
		{
			case 'start':
				$fields = array('package_id' => $catalog['package'], 'scan_start' => time(), 'scan_end' => 0, 'file_cursor' => 0, 'scan_data' => '');
				break;
			case 'advance':
				$cursor = (int) $row['file_cursor'];
				if ($row['package_id'] !== $catalog['package'] || $row['scan_end'] || $cursor !== count($row['scan_data']) ||
					$cursor >= count($catalog['paths'])) { throw new \DomainException('PLUS_CT_FILES_STALE'); }
				// No database lock is held while reading the program files. The revision below protects concurrent progress.
				$work = $row['scan_data'] + $this->files->sample($cursor, 400);
				$fields = array('file_cursor' => count($work), 'scan_data' => json_encode($work, JSON_UNESCAPED_SLASHES),
					'scan_end' => count($work) === count($catalog['paths']) ? time() : 0);
				break;
			case 'adopt':
				if (!$this->can_adopt($row, $catalog)) { throw new \DomainException('PLUS_CT_FILES_NOT_READY'); }
				$fields = array('reference_time' => time(), 'reference_data' => json_encode($row['scan_data'], JSON_UNESCAPED_SLASHES));
				break;
			default: throw new \DomainException('FORM_INVALID');
		}
		if (!$allowed()) { throw new \DomainException('NO_AUTH_OPERATION'); }
		$fields['revision'] = $revision + 1;
		$this->db->sql_query("UPDATE {$this->table} SET " . $this->db->sql_build_array('UPDATE', $fields) . " WHERE state_id=1 AND revision=$revision");
		if (!$this->db->sql_affectedrows()) { throw new \DomainException('PLUS_CT_FILES_STALE'); }
	}

	public function report(array $row, $all, int $offset)
	{
		$counts = array_fill_keys(array('present', 'same', 'changed', 'added', 'missing', 'absent', 'unreadable', 'retired'), 0);
		$list = array(); $total = 0;
		$previous = $row['reference_data']; $scan = $row['scan_data'];
		$paths = array_keys($scan + $previous); sort($paths, SORT_STRING);
		foreach ($paths as $path)
		{
			if (!array_key_exists($path, $scan)) { $status = 'retired'; }
			elseif ($scan[$path] === false) { $status = 'unreadable'; }
			elseif ($scan[$path] === null) { $status = isset($previous[$path]) ? 'missing' : 'absent'; }
			elseif (!$row['reference_time']) { $status = 'present'; }
			elseif (!isset($previous[$path])) { $status = 'added'; }
			else { $status = hash_equals($previous[$path], $scan[$path]) ? 'same' : 'changed'; }
			++$counts[$status];
			if (!$all && ($status === 'same' || $status === 'present' || ($status === 'absent' && $row['reference_time']))) { continue; }
			if ($total >= $offset && $total < $offset + 50) { $list[$path] = $status; }
			++$total;
		}
		return array('counts' => $counts, 'paths' => $list, 'total' => $total);
	}
}
