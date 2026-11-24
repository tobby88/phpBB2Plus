<?php
namespace phpbb2plus\downloads\controller;

use phpbb2plus\downloads\service\access;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class delivery
{
	private $access;
	private $db;
	private $user;
	private $language;
	private $template;
	private $helper;
	private $request;
	private $http;
	private $config;
	private $prefix;
	private $root;

	public function __construct($access, $db, $user, $language, $template, $helper, $request, $http, $config, $prefix, $root)
	{
		$this->access = $access;
		$this->db = $db;
		$this->user = $user;
		$this->language = $language;
		$this->template = $template;
		$this->helper = $helper;
		$this->request = $request;
		$this->http = $http;
		$this->config = $config;
		$this->prefix = $prefix;
		$this->root = $root;
	}

	private function local_path($name)
	{
		if (!preg_match('/^download_[a-f0-9]{64}$/D', $name)) { throw new NotFoundHttpException(); }
		$path = $this->root . trim($this->config['upload_path'], '/') . '/' . $name;
		if (!is_file($path) || is_link($path)) { throw new NotFoundHttpException(); }
		return $path;
	}

	private function hotlink()
	{
		$ref = $this->http->headers->get('Referer', '');
		if ($ref === '' || !(int) $this->access->setting('hotlink_prevent', 0)) { return; }
		$host = static function ($value) { return preg_replace('/^www\./', '', strtolower(rtrim((string) $value, '.'))); };
		$from = $host(parse_url($ref, PHP_URL_HOST));
		if ($from !== '' && in_array($from, array($host($this->config['server_name']),$host($this->http->getHost())), true)) { return; }
		foreach (preg_split('/[\s,;]+/', (string) $this->access->setting('hotlink_allowed', ''), -1, PREG_SPLIT_NO_EMPTY) as $allowed)
		{
			$item = parse_url(strpos($allowed, '://') !== false ? $allowed : 'http://' . $allowed, PHP_URL_HOST);
			$item = $host($item);
			if ($item !== '' && ($from === $item || substr($from, -strlen($item)-1) === '.' . $item)) { return; }
		}
		throw new AccessDeniedHttpException();
	}

	private function private_response($response)
	{
		$response->headers->set('Cache-Control', 'private, no-store');
		$response->headers->set('X-Content-Type-Options', 'nosniff');
		$response->headers->set('Referrer-Policy', 'no-referrer');
		return $response;
	}

	public function preview($file)
	{
		$f = $this->access->file($file);
		$this->hotlink();
		if (!in_array($f['preview_type'], array('image/jpeg','image/png','image/gif','image/webp'), true)) { throw new NotFoundHttpException(); }
		return $this->private_response(new BinaryFileResponse($this->local_path($f['preview_name']), 200,
			array('Content-Type'=>$f['preview_type'], 'Content-Security-Policy'=>"default-src 'none'; sandbox"), false));
	}

	private function choice($id, $mirror, $f = null)
	{
		if ($f === null) { $f = $this->access->file($id); }
		if (!$this->access->allows((int) $f['cat_id'], 'download')) { throw new NotFoundHttpException(); }
		$selected = $f;
		if ($mirror)
		{
			$result = $this->db->sql_query('SELECT * FROM ' . $this->prefix . "mirrors WHERE file_id=$id AND mirror_id=$mirror");
			$selected = $this->db->sql_fetchrow($result);
			$this->db->sql_freeresult($result);
		}
		if (!$selected || !in_array($selected['asset_status'], array('ready','remote'), true)) { throw new NotFoundHttpException(); }
		$license = null;
		if ($f['license_id'])
		{
			$result = $this->db->sql_query('SELECT title,terms FROM ' . $this->prefix . 'licenses WHERE license_id=' . (int) $f['license_id']);
			$license = $this->db->sql_fetchrow($result);
			$this->db->sql_freeresult($result);
			if (!$license) { throw new NotFoundHttpException(); }
		}
		return array($f,$selected,$license);
	}

	private function form_key($f, $mirror, $terms)
	{
		return 'plus_dl_license_' . $f['file_id'] . '_' . $mirror . '_' . hash('sha256', $f['license_id'] . "\n" . $terms['title'] . "\n" . $terms['terms']);
	}

	private function content($file, $selected)
	{
		$target = json_decode($selected['source_json'], true) ?: array();
		if ($selected['asset_status'] === 'remote')
		{
			$url = access::external($target['file_dlurl'] ?? '');
			if ($url === '') { throw new NotFoundHttpException(); }
			return new RedirectResponse($url, 302);
		}
		$path = $this->local_path($selected['storage_name']);
		if ((string) filesize($path) !== (string) $selected['file_bytes']) { throw new NotFoundHttpException(); }
		$metadata = json_decode($file['source_json'], true) ?: array();
		$name = basename(str_replace('\\','/', (string) (($metadata['real_name'] ?? '') ?: ($target['unique_name'] ?? 'download'))));
		$name = preg_replace('/[\x00-\x1f\x7f]/u', '', $name);
		if ($name === '' || $name === '.' || $name === '..') { $name = 'download'; }
		$fallback = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
		$response = new BinaryFileResponse($path, 200, array('Content-Type'=>'application/octet-stream','Content-Security-Policy'=>"default-src 'none'; sandbox"), false);
		$response->setContentDisposition('attachment', $name, $fallback);
		return $response;
	}

	private function record($id)
	{
		$table = $this->prefix . 'visitors';
		$owner = !empty($this->user->data['is_registered']) ? (int) $this->user->data['user_id'] : 0;
		$ip = $this->user->ip;
		$where = $owner ? "owner_id=$owner" : "ip_address='" . $this->db->sql_escape($ip) . "'";
		$result = $this->db->sql_query_limit("SELECT row_id FROM $table WHERE file_id=$id AND $where", 1);
		$exists = $this->db->sql_fetchfield('row_id') !== false;
		$this->db->sql_freeresult($result);
		if (!$exists)
		{
			$agent = $this->http->headers->get('User-Agent', '');
			preg_match('~(Edg|Firefox|Chrome|Version)/([0-9.]{1,32})~', $agent, $browser);
			preg_match('~Android|iPhone|iPad|Windows|Mac OS|Linux~i', $agent, $os);
			$row = array('file_id'=>$id,'owner_id'=>$owner,'ip_address'=>$ip,'source_json'=>json_encode(array('downloader_browser'=>$browser[1] ?? '',
				'browser_version'=>$browser[2] ?? '', 'downloader_os'=>$os[0] ?? '')));
			$this->db->sql_query("INSERT INTO $table " . $this->db->sql_build_array('INSERT', $row));
		}
		$this->db->sql_query('UPDATE ' . $this->prefix . 'files SET downloads=CASE WHEN downloads<4294967295 THEN downloads+1 ELSE downloads END,last_download=' . time() . " WHERE file_id=$id");
	}

	public function choose($file)
	{
		$id = (int) $file;
		$f = $this->access->file($id);
		if (!$this->access->allows((int) $f['cat_id'], 'download')) { throw new NotFoundHttpException(); }
		$result = $this->db->sql_query_limit('SELECT mirror_id FROM ' . $this->prefix . "mirrors WHERE file_id=$id AND asset_status IN ('ready','remote')", 1);
		$mirrors = $this->db->sql_fetchfield('mirror_id') !== false;
		$this->db->sql_freeresult($result);
		$url = $mirrors ? $this->helper->route('phpbb2plus_downloads_file', array('file'=>$id), false) . '#downloads'
			: $this->helper->route('phpbb2plus_downloads_media', array('file'=>$id,'mirror'=>0), false);
		return $this->private_response(new RedirectResponse($url));
	}

	public function download($file, $mirror = 0)
	{
		$id = (int) $file;
		$mirror = (int) $mirror;
		list($f,$selected,$license) = $this->choice($id, $mirror);
		$this->hotlink();
		$this->language->add_lang('downloads', 'phpbb2plus/downloads');
		if ($license && !$this->http->isMethod('POST'))
		{
			add_form_key($this->form_key($f,$mirror,$license));
			$this->template->assign_vars(array('DL_TITLE'=>access::plain($f['title']), 'DL_LICENSE'=>access::plain($license['title']),
				'DL_TERMS'=>access::plain($license['terms']), 'U_DL_FILE'=>$this->helper->route('phpbb2plus_downloads_file',array('file'=>$id)),
				'U_DL_ACCEPT'=>$this->helper->route('phpbb2plus_downloads_media',array('file'=>$id,'mirror'=>$mirror))));
			return $this->private_response($this->helper->render('@phpbb2plus_downloads/license.html',$this->language->lang('PLUS_DL_LICENSE')));
		}
		if ($this->http->isMethod('HEAD')) { return $this->private_response($this->content($f,$selected)); }
		$response = $this->access->write($id, function ($f) use ($id,$mirror) {
			list(,$selected,$license) = $this->choice($id,$mirror,$f);
			if ($license && (!$this->request->is_set_post('accept') || !check_form_key($this->form_key($f,$mirror,$license)))) { throw new AccessDeniedHttpException(); }
			$response = $this->content($f,$selected);
			$this->record($id);
			return $response;
		});
		return $this->private_response($response);
	}
}
