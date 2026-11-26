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
	private $log;

	public function __construct($access, $media, $db, $user, $config, $prefix, $log)
	{
		$this->access = $access;
		$this->media = $media;
		$this->db = $db;
		$this->user = $user;
		$this->config = $config;
		$this->prefix = $prefix;
		$this->log = $log;
	}

	public function licenses()
	{
		$result = $this->db->sql_query('SELECT license_id,title,source_json FROM ' . $this->prefix . 'licenses ORDER BY title,license_id');
		$rows = array();
		while ($r = $this->db->sql_fetchrow($result)) { $rows[(int) $r['license_id']] = license_text::raw($r,'title'); }
		$this->db->sql_freeresult($result);
		return $rows;
	}

	public function fields()
	{
		$result = $this->db->sql_query('SELECT * FROM ' . $this->prefix . 'fields ORDER BY position,field_id');
		$fields = array();
		while ($row = $this->db->sql_fetchrow($result))
		{
			$fields[(int) $row['field_id']] = field_data::definition($row);
		}
		$this->db->sql_freeresult($result);
		return $fields;
	}

	public function saved_fields($id)
	{
		$result = $this->db->sql_query('SELECT v.field_id,v.values_json,v.source_json,d.field_type FROM ' . $this->prefix .
			'field_values v JOIN ' . $this->prefix . 'fields d ON d.field_id=v.field_id WHERE v.file_id=' . (int) $id . ' ORDER BY v.row_id');
		$values = array();
		while ($row = $this->db->sql_fetchrow($result))
		{
			$field = (int) $row['field_id'];
			$values[$field] = array_merge($values[$field] ?? array(),field_data::values($row));
		}
		$this->db->sql_freeresult($result);
		return $values;
	}

	private function field_values($input, $previous, $revision)
	{
		if (!is_array($input)) { throw new \DomainException('PLUS_DL_BAD_FIELD'); }
		$stored = array();
		$fields = $this->fields();
		if (!hash_equals(field_data::revision($fields),$revision)) { throw new \DomainException('PLUS_DL_FIELDS_CHANGED',409); }
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
				if ($multiple)
				{
					// A reordered option list changes the browser's POST order even
					// when the author did not touch the selection.
					$submitted = $choices;
					sort($submitted);
					sort($old);
					if ($submitted === $old) { continue; }
				}
			}
			if (!array_key_exists($id, $input) && !isset($previous[$id]) && $field['type'] >= 2) { continue; }
			$values = array();
			foreach ($choices as $choice)
			{
				if (!is_string($choice) || strlen($choice) > 65535 || !preg_match('//u', $choice) || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $choice))
				{ throw new \DomainException('PLUS_DL_BAD_FIELD'); }
				if ($field['type'] >= 2 && $choice === '' && !$multiple) { continue; }
				if ($field['type'] >= 2 && !in_array($choice,$field['options'],true) && !in_array($choice,$previous[$id] ?? array(),true))
				{ throw new \DomainException('PLUS_DL_BAD_FIELD'); }
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

	private function validate($form, $file, $preview, $editing = false, $remove_preview = false, $metadata = array())
	{
		// Some released paFileDB entries have no long description. Their authors
		// can change a field without having to invent a description for the file.
		if ($form['title'] === '' || ($form['description'] === '' && (!$editing || ($metadata['file_longdesc'] ?? '') !== '')))
		{ throw new \DomainException('PLUS_DL_BAD_SUBMISSION'); }
		foreach ($form as $value)
		{
			if (is_string($value) && (strlen($value) > 65535 || !preg_match('//u', $value) || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)))
			{ throw new \DomainException('PLUS_DL_BAD_SUBMISSION'); }
		}
		if (($file && $form['url'] !== '') || (!$editing && !$file && $form['url'] === '') || ($preview && $form['preview_url'] !== '') ||
			($remove_preview && ($preview || $form['preview_url'] !== ''))) { throw new \DomainException('PLUS_DL_CHOOSE_SOURCE'); }
		foreach (array('url','preview_url','documentation') as $key)
		{
			if ($key === 'documentation' && $editing && $form[$key] === ($metadata['file_docsurl'] ?? '')) { continue; }
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

	private function admin_changes($admin, $current)
	{
		$row = array();
		foreach (array('pinned','preview_link') as $flag)
		{
			if (!in_array($admin[$flag],array('0','1'),true)) { throw new \DomainException('FORM_INVALID'); }
			$row[$flag] = (int) $admin[$flag];
		}
		$icon = $admin['icon'];
		if ($icon !== '' && $icon !== ($current['icon_name'] ?? '') && !isset(file_display::icons()[$icon]))
		{ throw new \DomainException('ACP_PLUS_DL_ICON_INVALID'); }
		$row['icon_name'] = $icon;
		if ($admin['approved'] !== null)
		{
			if (!$this->access->file_admin('approve')) { throw new \DomainException('NO_AUTH_OPERATION'); }
			if (!in_array($admin['approved'],array('1','0'),true)) { throw new \DomainException('FORM_INVALID'); }
			$row['approved'] = (int) $admin['approved'];
		}
		elseif (!$current) { $row['approved'] = 0; }
		if ($admin['downloads'] !== null)
		{
			if (!preg_match('/^(?:0|[1-9][0-9]{0,9})$/D',$admin['downloads']) || $admin['downloads'] > 4294967295)
			{ throw new \DomainException('ACP_PLUS_DL_COUNTER_INVALID'); }
			if ($current && $admin['previous_downloads'] !== (string) $current['downloads'])
			{ throw new \DomainException('ACP_PLUS_DL_COUNTER_CHANGED',409); }
			$row['downloads'] = (int) $admin['downloads'];
		}
		return $row;
	}

	private function file_changes($form, $assets, $previous = null, $remove_preview = false, $admin_row = array())
	{
		$metadata = $previous ? json_decode($previous['source_json'], true) : array('poster_ip'=>$this->user->ip);
		if (!is_array($metadata)) { throw new \RuntimeException('Could not read the existing download metadata.'); }
		if ($admin_row)
		{
			$metadata['plus_posticon'] = $admin_row['icon_name'];
			$metadata['plus_preview_link'] = $admin_row['preview_link'];
		}
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
			if ($file || $form['url'] !== ($metadata['file_dlurl'] ?? '')) { unset($metadata['file_size']); }
			$metadata['real_name'] = $file['name'] ?? '';
			$metadata['file_dlurl'] = $form['url'];
			if ($previous) { $this->media->queue($previous['storage_name']); }
		}
		$preview = $assets['preview'];
		if (!$previous || $preview || $form['preview_url'] !== '' || $remove_preview)
		{
			$row += array('preview_name'=>$preview['storage'] ?? '', 'preview_type'=>$preview['mime'] ?? '', 'preview_unavailable'=>0);
			$metadata['file_ssurl'] = $form['preview_url'];
			if ($previous) { $this->media->queue($previous['preview_name']); }
		}
		$row['source_json'] = json_encode($metadata);
		return $row + $admin_row + search_text::file($row,$metadata);
	}

	public function submit($category, $nonce, $form, $input, $file, $preview, $admin = null)
	{
		$category = (int) $category;
		if ($admin !== null && !$this->access->file_admin('edit')) { throw new \DomainException('NO_AUTH_OPERATION'); }
		if ($admin === null && !$this->access->can_submit($category)) { throw new NotFoundHttpException(); }
		if (!preg_match('/^[a-f0-9]{32}$/D', $nonce)) { throw new \DomainException('FORM_INVALID'); }
		$file = $this->media->inspect($file, false);
		$preview = $this->media->inspect($preview, true);
		$this->validate($form, $file, $preview);
		$owner = empty($this->user->data['is_registered']) ? 0 : (int) $this->user->data['user_id'];
		$key = hash_hmac('sha256', ($admin === null ? 'submit:' : 'acp-submit:') . $this->user->session_id . ':' . $owner . ':' . $category . ':' . $nonce, $this->config['plus_download_media_key']);
		$files = $this->prefix . 'files';
		$receipts = $this->prefix . 'receipts';
		$assets = array();
		try
		{
			foreach (array('file'=>$file, 'preview'=>$preview) as $kind => $item) { $assets[$kind] = $this->media->reserve($item); }
			$this->db->sql_transaction('begin');
			$this->access->lock_catalogue(false);
			$this->db->sql_query('UPDATE ' . $this->prefix . 'categories SET cat_id=cat_id WHERE cat_id=' . $category);
			$this->access->refresh();
			$admin_row = array();
			if ($admin !== null)
			{
				if (!$this->access->file_admin('edit',true)) { throw new \DomainException('NO_AUTH_OPERATION'); }
				if (!$this->access->file_destination($category)) { throw new \DomainException('PLUS_DL_BAD_DESTINATION'); }
				$admin_row = $this->admin_changes($admin,null);
			}
			elseif (!$this->access->can_submit($category)) { throw new NotFoundHttpException(); }
			$this->check_license($form['license']);
			$fields = $this->field_values($input,array(),$form['fields_revision']);
			$identity = array();
			foreach ($assets as $kind => $asset) { $identity[$kind] = $asset ? array($asset['name'], $asset['hash']) : null; }
			$payload = array($form,$fields,$identity);
			if ($admin !== null) { $payload[] = $admin_row; }
			$digest = hash('sha256',serialize($payload));
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
				$row = $this->file_changes($form,$assets,null,false,$admin_row) + array('cat_id'=>$category, 'owner_id'=>$owner, 'approved'=>(int) $approved, 'added_at'=>time());
				$this->db->sql_query("INSERT INTO $files " . $this->db->sql_build_array('INSERT', $row));
				$id = (int) $this->db->sql_nextid();
				$this->save_fields($id, $fields);
				if ($admin !== null) { $this->log->add('admin',$owner,$this->user->ip,'LOG_PLUS_DL_FILE_CREATE',false,array($id)); }
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

	public function edit($id, $category, $revision, $form, $input, $file, $preview, $remove_preview, $admin = null)
	{
		// The revision makes replacements single-use. Only new submissions need content hashes for retries.
		if ($admin !== null && !$this->access->file_admin('edit')) { throw new \DomainException('NO_AUTH_OPERATION'); }
		$file = $this->media->inspect($file, false, false);
		$preview = $this->media->inspect($preview, true, false);
		$assets = array();
		try
		{
			foreach (array('file'=>$file,'preview'=>$preview) as $kind => $part) { $assets[$kind] = $this->media->reserve($part); }
			$this->access->write($id, function ($current) use ($id, $category, $revision, $form, $input, $assets, $remove_preview, $admin) {
				$admin_row = array();
				if ($admin !== null)
				{
					if (!$this->access->file_destination($category)) { throw new \DomainException('PLUS_DL_BAD_DESTINATION'); }
					$admin_row = $this->admin_changes($admin,$current);
				}
				elseif (!$this->access->can_manage($current, 'edit_file')) { throw new NotFoundHttpException(); }
				if ((int) $current['review_revision'] !== $revision) { throw new \DomainException('PLUS_DL_EDIT_CHANGED', 409); }
				if ($admin === null && (int) $current['cat_id'] !== $category && !$this->access->can_submit($category)) { throw new \DomainException('PLUS_DL_BAD_DESTINATION'); }
				$metadata = json_decode($current['source_json'], true);
				$this->validate($form, $assets['file'], $assets['preview'], true, $remove_preview, $metadata);
				if ((int) $current['license_id'] !== $form['license']) { $this->check_license($form['license']); }
				$fields = $this->field_values($input,$this->saved_fields($id),$form['fields_revision']);
				foreach ($assets as $asset) { $this->media->publish($asset); }
				$row = $this->file_changes($form,$assets,$current,$remove_preview,$admin_row);
				$row['review_revision'] = $revision + 1;
				$row['cat_id'] = $category;
				$this->db->sql_query('UPDATE ' . $this->prefix . 'files SET ' . $this->db->sql_build_array('UPDATE', $row) . ' WHERE file_id=' . (int) $id);
				$this->save_fields($id, $fields, true);
				if ($admin !== null) { $this->log->add('admin',$this->user->data['user_id'],$this->user->ip,'LOG_PLUS_DL_FILE_EDIT',false,array($id)); }
			},$admin !== null);
		}
		catch (\Throwable $e)
		{
			foreach ($assets as $asset) { if ($asset) { $this->media->collect($asset['job']); } }
			throw $e;
		}
	}

	public function purge_category_files($ids)
	{
		if (!$this->access->catalogue_admin() || !$this->db->get_transaction()) { throw new NotFoundHttpException(); }
		$this->purge($ids);
	}

	// Include contributions and attached records in a removal confirmation. Routine
	// downloads and anonymous visit counts do not change the content being reviewed.
	public function removal_details($ids, $hash, $lock)
	{
		$tables = array('comments'=>array('comment_id','owner_id,posted_at,title,body'),
			'votes'=>array('row_id','owner_id,points'),'mirrors'=>array('mirror_id','location,storage_name,asset_status,source_json'),
			'field_values'=>array('row_id','field_id,values_json'));
		$counts = array_fill_keys(array_keys($tables),0);
		$current = $lock && in_array($this->db->get_sql_layer(),array('mysql','mysqli'),true) ? ' FOR UPDATE' : '';
		foreach (array_chunk($ids,200) as $batch)
		{
			$where = ' WHERE ' . $this->db->sql_in_set('file_id',$batch);
			foreach ($tables as $name=>$columns)
			{
				list($key,$fields) = $columns;
				hash_update($hash,$name);
				$result = $this->db->sql_query("SELECT $key,file_id,$fields FROM " . $this->prefix . $name . $where . " ORDER BY $key" . $current);
				while ($row = $this->db->sql_fetchrow($result)) { hash_update($hash,serialize($row)); ++$counts[$name]; }
				$this->db->sql_freeresult($result);
			}
		}
		return $counts;
	}

	public function apply_file_action($action, $ids)
	{
		if (!in_array($action,array('approve','withdraw','delete'),true) || !$this->db->get_transaction() || !$this->access->file_admin($action))
		{ throw new \DomainException('NO_AUTH_OPERATION'); }
		if ($action === 'delete') { $this->purge($ids); }
		else { $this->set_approval($ids,$action === 'approve'); }
	}

	private function set_approval($ids, $approved)
	{
		foreach (array_chunk($ids,200) as $batch)
		{
			$this->db->sql_query('UPDATE ' . $this->prefix . 'files SET approved=' . (int) $approved .
				',review_revision=review_revision+1 WHERE ' . $this->db->sql_in_set('file_id',$batch) . ' AND approved<>' . (int) $approved);
		}
	}

	/** Preview or confirm a category moderator's complete selection. A mixed or stale selection changes no files. */
	public function moderate_selection($category, $action, $selection, $revisions, $confirmation = null)
	{
		if (!in_array($action, array('approve','withdraw'), true)) { throw new \DomainException('PLUS_DL_MOD_SELECT', 400); }
		$ids = catalogue_selection::ids($selection, 'PLUS_DL_MOD_SELECT');
		$apply = $confirmation !== null;
		$this->db->sql_transaction('begin');
		try
		{
			$this->access->lock_catalogue(false);
			if (!$this->access->refresh_moderator()) { throw new NotFoundHttpException(); }
			$categories = $category ? array($category) : array_keys($this->access->directory());
			$allowed = array_filter($categories, array($this->access, 'can_review'));
			if (!$allowed) { throw new NotFoundHttpException(); }
			$where = $this->db->sql_in_set('file_id', $ids) . ' AND ' . $this->db->sql_in_set('cat_id', $allowed);
			$table = $this->prefix . 'files';
			$lock = $apply && in_array($this->db->get_sql_layer(), array('mysql','mysqli'), true) ? ' FOR UPDATE' : '';
			if ($apply && !$lock) { $this->db->sql_query("UPDATE $table SET file_id=file_id WHERE $where"); }
			$r = $this->db->sql_query("SELECT file_id,cat_id,title,approved,broken,review_revision FROM $table WHERE $where ORDER BY file_id" . $lock);
			$rows = $this->db->sql_fetchrowset($r);
			$this->db->sql_freeresult($r);
			if (count($rows) !== count($ids)) { throw new NotFoundHttpException(); }
			$changed = array();
			foreach ($rows as $row)
			{
				$id = (int) $row['file_id'];
				if (!isset($revisions[$id]) || (string) $revisions[$id] !== (string) $row['review_revision'])
				{
					throw new \DomainException('PLUS_DL_MOD_CHANGED', 409);
				}
				if ((bool) $row['approved'] !== ($action === 'approve')) { $changed[] = $id; }
			}
			if (!$changed) { throw new \DomainException('PLUS_DL_MOD_UNCHANGED', 400); }
			$state = hash('sha256', serialize(array((int) $this->user->data['user_id'], $category, $action, $rows)));
			if ($apply)
			{
				if (!hash_equals($state, $confirmation)) { throw new \DomainException('PLUS_DL_MOD_CHANGED', 409); }
				$this->set_approval($changed, $action === 'approve');
			}
			if (!$this->db->sql_transaction('commit')) { throw new \RuntimeException('Could not complete download moderation'); }
			return array('rows'=>$rows, 'state'=>$state, 'changed'=>$changed);
		}
		catch (\Throwable $e) { if ($this->db->get_transaction()) { $this->db->sql_transaction('rollback'); } throw $e; }
	}

	private function purge($ids)
	{
		foreach (array_chunk($ids, 200) as $batch)
		{
			$where = ' WHERE ' . $this->db->sql_in_set('file_id', $batch);
			$files = $this->prefix . 'files';
			$result = $this->db->sql_query("SELECT storage_name AS name FROM $files" . $where .
				" UNION SELECT preview_name AS name FROM $files" . $where . ' UNION SELECT storage_name AS name FROM ' . $this->prefix . 'mirrors' . $where);
			$queue = array();
			while ($row = $this->db->sql_fetchrow($result))
			{
				if (preg_match('/^download_[a-f0-9]{64}$/D', $row['name'])) { $queue[] = array('storage_name'=>$row['name'],'queued_at'=>time()); }
			}
			$this->db->sql_freeresult($result);
			foreach (array_chunk($queue,200) as $group) { $this->db->sql_multi_insert($this->prefix . 'media_queue',$group); }
			foreach (array('mirrors','field_values','comments','votes','visitors','files') as $table)
			{ $this->db->sql_query('DELETE FROM ' . $this->prefix . $table . $where); }
			if ((int) $this->db->sql_affectedrows() !== count($batch)) { throw new \RuntimeException('The complete file selection could not be removed.'); }
		}
		// Keep submission receipts through their form lifetime so a retry cannot recreate a removed item.
	}

	public function remove($id, $revision)
	{
		$this->access->write($id, function ($current) use ($revision) {
			if (!$this->access->can_manage($current, 'delete_file')) { throw new NotFoundHttpException(); }
			if ((int) $current['review_revision'] !== $revision) { throw new \DomainException('PLUS_DL_EDIT_CHANGED', 409); }
			$this->purge(array((int) $current['file_id']));
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
			if ($action !== 'reject')
			{
				$this->set_approval(array($id),$action === 'approve');
				return $cat;
			}
			$this->purge(array($id));
			return $cat;
		});
	}
}
