<?php
namespace phpbb2plus\downloads\service;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class submissions
{
	private $access;
	private $media;
	private $db;
	private $user;
	private $config;
	private $prefix;

	public function __construct($access, $media, $db, $user, $config, $prefix)
	{
		$this->access = $access;
		$this->media = $media;
		$this->db = $db;
		$this->user = $user;
		$this->config = $config;
		$this->prefix = $prefix;
	}

	public function licenses()
	{
		$result = $this->db->sql_query('SELECT license_id,title FROM ' . $this->prefix . 'licenses ORDER BY title,license_id');
		$rows = array();
		while ($r = $this->db->sql_fetchrow($result)) { $rows[(int) $r['license_id']] = access::plain($r['title']); }
		$this->db->sql_freeresult($result);
		return $rows;
	}

	public function fields()
	{
		$result = $this->db->sql_query('SELECT * FROM ' . $this->prefix . 'fields ORDER BY position,field_id');
		$fields = array();
		while ($row = $this->db->sql_fetchrow($result))
		{
			$source = json_decode($row['source_json'], true) ?: array();
			$fields[(int) $row['field_id']] = array('type'=>(int) $row['field_type'], 'title'=>access::plain($row['title']),
				'description'=>access::plain($row['description']), 'pattern'=>$source['regex'] ?? '',
				'options'=>array_map(array(access::class, 'plain'), json_decode($row['options_json'], true) ?: array()));
		}
		$this->db->sql_freeresult($result);
		return $fields;
	}

	public function saved_fields($id)
	{
		$result = $this->db->sql_query('SELECT field_id,values_json FROM ' . $this->prefix . 'field_values WHERE file_id=' . (int) $id . ' ORDER BY row_id');
		$values = array();
		while ($row = $this->db->sql_fetchrow($result))
		{
			$field = (int) $row['field_id'];
			$values[$field] = array_merge($values[$field] ?? array(), array_map(array(access::class, 'plain'), json_decode($row['values_json'], true) ?: array()));
		}
		$this->db->sql_freeresult($result);
		return $values;
	}

	private function field_values($input, $previous = array())
	{
		if (!is_array($input)) { throw new \DomainException('PLUS_DL_BAD_FIELD'); }
		$stored = array();
		$fields = $this->fields();
		if (array_diff_key($input, $fields)) { throw new \DomainException('PLUS_DL_BAD_FIELD'); }
		foreach ($fields as $id => $field)
		{
			if ($field['type'] < 0 || $field['type'] > 5) { continue; }
			$multiple = in_array($field['type'], array(4,5), true);
			$value = $input[$id] ?? ($multiple ? array() : '');
			if ($multiple !== is_array($value)) { throw new \DomainException('PLUS_DL_BAD_FIELD'); }
			$choices = $multiple ? array_values($value) : array($value);
			if (isset($previous[$id]))
			{
				$old = $multiple ? $previous[$id] : array_slice($previous[$id], 0, 1);
				if ($choices === $old) { continue; }
			}
			if (!array_key_exists($id, $input) && !isset($previous[$id]) && $field['type'] >= 2) { continue; }
			$values = array();
			foreach ($choices as $choice)
			{
				if (!is_string($choice) || strlen($choice) > 65535 || !preg_match('//u', $choice) || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $choice))
				{ throw new \DomainException('PLUS_DL_BAD_FIELD'); }
				if ($field['type'] >= 2 && $choice === '' && !$multiple) { continue; }
				if ($field['type'] >= 2 && !in_array($choice, $field['options'], true)) { throw new \DomainException('PLUS_DL_BAD_FIELD'); }
				if ($field['pattern'] !== '' && @preg_match('#(*LIMIT_MATCH=100000)' . $field['pattern'] . '#siU', $choice) !== 1)
				{ throw new \DomainException('PLUS_DL_BAD_FIELD'); }
				$values[] = utf8_htmlspecialchars($choice);
			}
			if ($values && $values !== array(''))
			{
				$json = json_encode(array_values(array_unique($values)));
				if ($json === false || strlen($json) > 65535) { throw new \DomainException('PLUS_DL_BAD_FIELD'); }
				$stored[(int) $id] = $json;
			}
			elseif (isset($previous[$id])) { $stored[(int) $id] = null; }
		}
		ksort($stored);
		return $stored;
	}

	private function validate($form, $file, $preview, $editing = false, $remove_preview = false, $previous_docs = '')
	{
		if ($form['title'] === '' || $form['description'] === '') { throw new \DomainException('PLUS_DL_BAD_SUBMISSION'); }
		foreach ($form as $value)
		{
			if (is_string($value) && (strlen($value) > 65535 || !preg_match('//u', $value) || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)))
			{ throw new \DomainException('PLUS_DL_BAD_SUBMISSION'); }
		}
		if (($file && $form['url'] !== '') || (!$editing && !$file && $form['url'] === '') || ($preview && $form['preview_url'] !== '') ||
			($remove_preview && ($preview || $form['preview_url'] !== ''))) { throw new \DomainException('PLUS_DL_CHOOSE_SOURCE'); }
		foreach (array('url','preview_url','documentation') as $key)
		{
			if ($key === 'documentation' && $editing && $form[$key] === $previous_docs) { continue; }
			if ($form[$key] !== '' && (strlen($form[$key]) > 2048 || access::external($form[$key]) === '')) { throw new \DomainException('PLUS_DL_BAD_URL'); }
		}
		if ($form['preview_url'] !== '' && !preg_match('/\.(?:jpe?g|png|gif)$/iD', (string) parse_url($form['preview_url'], PHP_URL_PATH)))
		{ throw new \DomainException('PLUS_DL_BAD_PREVIEW'); }
	}

	private function check_license($id)
	{
		if (!$id) { return; }
		$result = $this->db->sql_query('SELECT license_id FROM ' . $this->prefix . 'licenses WHERE license_id=' . (int) $id);
		$exists = $this->db->sql_fetchfield('license_id') !== false;
		$this->db->sql_freeresult($result);
		if (!$exists) { throw new \DomainException('PLUS_DL_BAD_LICENSE'); }
	}

	private function save_fields($id, $changes, $editing = false)
	{
		$table = $this->prefix . 'field_values';
		foreach ($changes as $field => $json)
		{
			if ($editing) { $this->db->sql_query("DELETE FROM $table WHERE file_id=" . (int) $id . ' AND field_id=' . (int) $field); }
			if ($json !== null) { $this->db->sql_query("INSERT INTO $table " . $this->db->sql_build_array('INSERT',
				array('file_id'=>$id, 'field_id'=>$field, 'values_json'=>$json, 'source_json'=>'{}'))); }
		}
	}

	private function file_changes($form, $assets, $previous = null, $remove_preview = false)
	{
		$metadata = $previous ? json_decode($previous['source_json'], true) : array('poster_ip'=>$this->user->ip);
		if (!is_array($metadata)) { throw new \RuntimeException('Could not read the existing download metadata.'); }
		$metadata['file_longdesc'] = utf8_htmlspecialchars($form['description']);
		$metadata['file_creator'] = utf8_htmlspecialchars($form['author']);
		$metadata['file_version'] = utf8_htmlspecialchars($form['version']);
		$metadata['file_docsurl'] = $form['documentation'];
		$summary = !$previous && $form['summary'] === '' ? utf8_substr($form['description'], 0, 50) : $form['summary'];
		$row = array('license_id'=>$form['license'], 'title'=>utf8_encode_ucr(utf8_htmlspecialchars($form['title'])),
			'summary'=>utf8_encode_ucr(utf8_htmlspecialchars($summary)), 'updated_at'=>time());
		$file = $assets['file'];
		if (!$previous || $file || $form['url'] !== '')
		{
			$row += array('storage_name'=>$file['storage'] ?? '', 'file_bytes'=>$file['bytes'] ?? 0, 'asset_status'=>$file ? 'ready' : 'remote');
			$metadata['real_name'] = $file['name'] ?? '';
			$metadata['file_dlurl'] = $form['url'];
			if ($previous) { $this->media->queue($previous['storage_name']); }
		}
		$preview = $assets['preview'];
		if (!$previous || $preview || $form['preview_url'] !== '' || $remove_preview)
		{
			$row += array('preview_name'=>$preview['storage'] ?? '', 'preview_type'=>$preview['mime'] ?? '');
			$metadata['file_ssurl'] = $form['preview_url'];
			if ($previous) { $this->media->queue($previous['preview_name']); }
		}
		$row['source_json'] = json_encode($metadata);
		return $row;
	}

	public function submit($category, $nonce, $form, $input, $file, $preview)
	{
		$category = (int) $category;
		if (!$this->access->can_submit($category)) { throw new NotFoundHttpException(); }
		if (!preg_match('/^[a-f0-9]{32}$/D', $nonce)) { throw new \DomainException('FORM_INVALID'); }
		$file = $this->media->inspect($file, false);
		$preview = $this->media->inspect($preview, true);
		$this->validate($form, $file, $preview);
		$owner = empty($this->user->data['is_registered']) ? 0 : (int) $this->user->data['user_id'];
		$key = hash_hmac('sha256', 'submit:' . $this->user->session_id . ':' . $owner . ':' . $category . ':' . $nonce, $this->config['plus_download_media_key']);
		$files = $this->prefix . 'files';
		$receipts = $this->prefix . 'receipts';
		$assets = array();
		try
		{
			foreach (array('file'=>$file, 'preview'=>$preview) as $kind => $item) { $assets[$kind] = $this->media->reserve($item); }
			$this->db->sql_transaction('begin');
			$this->db->sql_query('UPDATE ' . $this->prefix . 'categories SET cat_id=cat_id WHERE cat_id=' . $category);
			$this->access->refresh();
			if (!$this->access->can_submit($category)) { throw new NotFoundHttpException(); }
			$this->check_license($form['license']);
			$fields = $this->field_values($input);
			$identity = array();
			foreach ($assets as $kind => $asset) { $identity[$kind] = $asset ? array($asset['name'], $asset['hash']) : null; }
			$digest = hash('sha256', serialize(array($form, $fields, $identity)));
			$result = $this->db->sql_query("SELECT file_id,payload_hash FROM $receipts WHERE request_key='$key'");
			$saved = $this->db->sql_fetchrow($result);
			$this->db->sql_freeresult($result);
			if ($saved)
			{
				if (!hash_equals($saved['payload_hash'], $digest)) { throw new \DomainException('FORM_INVALID'); }
				$id = (int) $saved['file_id'];
				$result = $this->db->sql_query("SELECT file_id FROM $files WHERE file_id=$id");
				$exists = $this->db->sql_fetchfield('file_id') !== false;
				$this->db->sql_freeresult($result);
				if (!$exists) { throw new \DomainException('PLUS_DL_SUBMISSION_GONE'); }
			}
			else
			{
				foreach ($assets as $asset) { $this->media->publish($asset); }
				$approved = (string) $this->access->setting('need_validation', '1') === '0' || ($owner && $this->access->allows($category, 'mod'));
				$row = $this->file_changes($form, $assets) + array('cat_id'=>$category, 'owner_id'=>$owner, 'approved'=>(int) $approved, 'added_at'=>time());
				$this->db->sql_query("INSERT INTO $files " . $this->db->sql_build_array('INSERT', $row));
				$id = (int) $this->db->sql_nextid();
				$this->save_fields($id, $fields);
				$this->db->sql_query("INSERT INTO $receipts " . $this->db->sql_build_array('INSERT',
					array('request_key'=>$key, 'payload_hash'=>$digest, 'file_id'=>$id, 'item_id'=>$id, 'created_at'=>time())));
			}
			if (!$this->db->sql_transaction('commit')) { throw new \RuntimeException('The download submission did not commit.'); }
			// Published assets and their journal tickets were committed together.
			if (!$saved) { $assets = array(); }
			return $id;
		}
		catch (\Throwable $e) { if ($this->db->get_transaction()) { $this->db->sql_transaction('rollback'); } throw $e; }
		finally { foreach ($assets as $asset) { if ($asset) { $this->media->collect($asset['job']); } } }
	}

	public function edit($id, $category, $revision, $form, $input, $file, $preview, $remove_preview)
	{
		// The revision makes replacements single-use. Only new submissions need content hashes for retries.
		$file = $this->media->inspect($file, false, false);
		$preview = $this->media->inspect($preview, true, false);
		$assets = array();
		try
		{
			foreach (array('file'=>$file,'preview'=>$preview) as $kind => $part) { $assets[$kind] = $this->media->reserve($part); }
			$this->access->write($id, function ($current) use ($id, $category, $revision, $form, $input, $assets, $remove_preview) {
				if (!$this->access->can_manage($current, 'edit_file')) { throw new NotFoundHttpException(); }
				if ((int) $current['review_revision'] !== $revision) { throw new \DomainException('PLUS_DL_EDIT_CHANGED', 409); }
				if ((int) $current['cat_id'] !== $category && !$this->access->can_submit($category)) { throw new \DomainException('PLUS_DL_BAD_DESTINATION'); }
				$metadata = json_decode($current['source_json'], true);
				$this->validate($form, $assets['file'], $assets['preview'], true, $remove_preview, $metadata['file_docsurl'] ?? '');
				if ((int) $current['license_id'] !== $form['license']) { $this->check_license($form['license']); }
				$fields = $this->field_values($input, $this->saved_fields($id));
				foreach ($assets as $asset) { $this->media->publish($asset); }
				$row = $this->file_changes($form, $assets, $current, $remove_preview);
				$row['review_revision'] = $revision + 1;
				$row['cat_id'] = $category;
				$this->db->sql_query('UPDATE ' . $this->prefix . 'files SET ' . $this->db->sql_build_array('UPDATE', $row) . ' WHERE file_id=' . (int) $id);
				$this->save_fields($id, $fields, true);
			});
		}
		catch (\Throwable $e)
		{
			foreach ($assets as $asset) { if ($asset) { $this->media->collect($asset['job']); } }
			throw $e;
		}
	}

	private function purge($file)
	{
		$where = ' WHERE file_id=' . (int) $file['file_id'];
		$names = array($file['storage_name'], $file['preview_name']);
		$result = $this->db->sql_query('SELECT storage_name FROM ' . $this->prefix . 'mirrors' . $where);
		while ($mirror = $this->db->sql_fetchrow($result)) { $names[] = $mirror['storage_name']; }
		$this->db->sql_freeresult($result);
		foreach (array_unique($names) as $name) { $this->media->queue($name); }
		foreach (array('mirrors','field_values','comments','votes','visitors','files') as $table)
		{ $this->db->sql_query('DELETE FROM ' . $this->prefix . $table . $where); }
		// Keep submission receipts through their form lifetime so a retry cannot recreate a removed item.
	}

	public function remove($id, $revision)
	{
		$this->access->write($id, function ($current) use ($revision) {
			if (!$this->access->can_manage($current, 'delete_file')) { throw new NotFoundHttpException(); }
			if ((int) $current['review_revision'] !== $revision) { throw new \DomainException('PLUS_DL_EDIT_CHANGED', 409); }
			$this->purge($current);
		});
	}

	public function review($id, $action, $revision)
	{
		if (!in_array($action, array('approve','withdraw','reject'), true)) { throw new NotFoundHttpException(); }
		return $this->access->write($id, function ($file) use ($action, $revision) {
			$id = (int) $file['file_id'];
			$cat = (int) $file['cat_id'];
			if (!$this->access->can_review($cat)) { throw new NotFoundHttpException(); }
			if ((int) $file['review_revision'] !== $revision || (($action === 'withdraw') !== (bool) $file['approved']))
			{ throw new \DomainException('PLUS_DL_REVIEW_CHANGED'); }
			$where = ' WHERE file_id=' . $id;
			if ($action !== 'reject')
			{
				$this->db->sql_query('UPDATE ' . $this->prefix . 'files SET approved=' . ($action === 'approve' ? 1 : 0) .
					',review_revision=review_revision+1' . $where);
				return $cat;
			}
			$this->purge($file);
			return $cat;
		});
	}
}
