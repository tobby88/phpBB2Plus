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

	private function field_values($input)
	{
		if (!is_array($input)) { throw new \DomainException('PLUS_DL_BAD_FIELD'); }
		$stored = array();
		$fields = $this->fields();
		if (array_diff_key($input, $fields)) { throw new \DomainException('PLUS_DL_BAD_FIELD'); }
		foreach ($fields as $id => $field)
		{
			if (!array_key_exists($id, $input) && $field['type'] >= 2) { continue; }
			$value = $input[$id] ?? '';
			$multiple = in_array($field['type'], array(4,5), true);
			if ($multiple !== is_array($value) || $field['type'] < 0 || $field['type'] > 5) { throw new \DomainException('PLUS_DL_BAD_FIELD'); }
			$values = array();
			foreach ($multiple ? $value : array($value) as $choice)
			{
				if (!is_string($choice) || strlen($choice) > 65535 || !preg_match('//u', $choice) || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $choice))
				{ throw new \DomainException('PLUS_DL_BAD_FIELD'); }
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
		}
		ksort($stored);
		return $stored;
	}

	private function validate($form, $file, $preview)
	{
		if ($form['title'] === '' || $form['description'] === '') { throw new \DomainException('PLUS_DL_BAD_SUBMISSION'); }
		foreach ($form as $value)
		{
			if (is_string($value) && (strlen($value) > 65535 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)))
			{ throw new \DomainException('PLUS_DL_BAD_SUBMISSION'); }
		}
		if (($file !== null) === ($form['url'] !== '') || ($preview && $form['preview_url'] !== '')) { throw new \DomainException('PLUS_DL_CHOOSE_SOURCE'); }
		foreach (array('url','preview_url','documentation') as $key)
		{
			if ($form[$key] !== '' && (strlen($form[$key]) > 2048 || access::external($form[$key]) === '')) { throw new \DomainException('PLUS_DL_BAD_URL'); }
		}
		if ($form['preview_url'] !== '' && !preg_match('/\.(?:jpe?g|png|gif)$/iD', (string) parse_url($form['preview_url'], PHP_URL_PATH)))
		{ throw new \DomainException('PLUS_DL_BAD_PREVIEW'); }
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
			if ($form['license'])
			{
				$result = $this->db->sql_query('SELECT license_id FROM ' . $this->prefix . 'licenses WHERE license_id=' . (int) $form['license']);
				$existing_license = $this->db->sql_fetchfield('license_id') !== false;
				$this->db->sql_freeresult($result);
				if (!$existing_license) { throw new \DomainException('PLUS_DL_BAD_LICENSE'); }
			}
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
				$metadata = array('real_name'=>$file ? $file['name'] : '', 'file_dlurl'=>$form['url'], 'file_ssurl'=>$form['preview_url'],
					'file_longdesc'=>utf8_htmlspecialchars($form['description']), 'file_creator'=>utf8_htmlspecialchars($form['author']),
					'file_version'=>utf8_htmlspecialchars($form['version']), 'file_docsurl'=>$form['documentation'], 'poster_ip'=>$this->user->ip);
				$summary = $form['summary'] === '' ? utf8_substr($form['description'], 0, 50) : $form['summary'];
				$row = array('cat_id'=>$category, 'owner_id'=>$owner, 'license_id'=>$form['license'], 'approved'=>(int) $approved,
					'title'=>utf8_encode_ucr(utf8_htmlspecialchars($form['title'])), 'summary'=>utf8_encode_ucr(utf8_htmlspecialchars($summary)),
					'added_at'=>time(), 'updated_at'=>time(), 'file_bytes'=>$file ? $file['bytes'] : 0, 'storage_name'=>$assets['file']['storage'] ?? '',
					'asset_status'=>$file ? 'ready' : 'remote', 'preview_name'=>$assets['preview']['storage'] ?? '', 'preview_type'=>$preview['mime'] ?? '',
					'source_json'=>json_encode($metadata));
				$this->db->sql_query("INSERT INTO $files " . $this->db->sql_build_array('INSERT', $row));
				$id = (int) $this->db->sql_nextid();
				foreach ($fields as $field => $json)
				{
					$this->db->sql_query('INSERT INTO ' . $this->prefix . 'field_values ' . $this->db->sql_build_array('INSERT',
						array('file_id'=>$id, 'field_id'=>$field, 'values_json'=>$json, 'source_json'=>'{}')));
				}
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
			$names = array($file['storage_name'], $file['preview_name']);
			$result = $this->db->sql_query('SELECT storage_name FROM ' . $this->prefix . 'mirrors' . $where);
			while ($mirror = $this->db->sql_fetchrow($result)) { $names[] = $mirror['storage_name']; }
			$this->db->sql_freeresult($result);
			foreach (array_unique($names) as $name) { $this->media->queue($name); }
			foreach (array('mirrors','field_values','comments','votes','visitors','files') as $table)
			{ $this->db->sql_query('DELETE FROM ' . $this->prefix . $table . $where); }
			// Keep submission receipts through their form lifetime so a retry cannot recreate a rejected item.
			return $cat;
		});
	}
}
