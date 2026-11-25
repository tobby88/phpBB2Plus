<?php
namespace phpbb2plus\banners\controller;

use phpbb2plus\banners\service\catalog;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;

class delivery
{
	private $catalog;
	private $db;
	private $config;
	private $user;
	private $request;
	private $prefix;

	public function __construct($catalog, $db, $config, $user, $request, $prefix)
	{
		$this->catalog = $catalog;
		$this->db = $db;
		$this->config = $config;
		$this->user = $user;
		$this->request = $request;
		$this->prefix = $prefix;
	}

	public function media($banner)
	{
		list($file, $mime) = $this->catalog->media((int) $banner);
		$response = new BinaryFileResponse($file);
		$response->headers->set('Content-Type', $mime === 'application/x-shockwave-flash' ? 'application/octet-stream' : $mime);
		$response->headers->set('Cache-Control', 'private, no-store');
		$response->headers->set('Content-Security-Policy', "sandbox; default-src 'none'");
		$response->headers->set('X-Content-Type-Options', 'nosniff');
		return $response;
	}

	public function visit($banner)
	{
		$id = (int) $banner;
		$row = $this->catalog->item($id);
		if ($this->request->server('REQUEST_METHOD') === 'GET' && !$this->catalog->filtered($id) && empty($this->user->data['is_bot']))
		{
			$now = time();
			$visitor = hash('sha256', $this->user->session_id);
			$ip = $this->user->ip;
			$hits = $this->prefix . '_hits';
			$receipts = $this->prefix . '_receipts';
			$this->db->sql_transaction('begin');
			try
			{
				// Serialize the receipt, counter and detail row against this placement.
				$row = $this->catalog->item($id, true);
				$r = $this->db->sql_query("SELECT expires FROM $receipts WHERE banner_id=$id AND visitor_key='$visitor'");
				$expires = (int) $this->db->sql_fetchfield('expires');
				$this->db->sql_freeresult($r);
				if ($expires <= $now)
				{
					$expires = $now + max(1, (int) $row['filter_seconds'] ?: 600);
					// Rotating anonymous sessions should not produce an unbounded stream of click details.
					$r = $this->db->sql_query_limit("SELECT hit_id FROM $hits WHERE ip='" . $this->db->sql_escape($ip) .
						"' AND hit_time>=" . ($now - 300) . ' ORDER BY hit_time DESC', 1, 59);
					$limited = $this->db->sql_fetchfield('hit_id') !== false;
					$this->db->sql_freeresult($r);
					if (!$limited)
					{
						$this->db->sql_query("DELETE FROM $receipts WHERE banner_id=$id AND expires<=$now");
						$this->db->sql_query("INSERT INTO $receipts " . $this->db->sql_build_array('INSERT', array('banner_id'=>$id,'visitor_key'=>$visitor,'expires'=>$expires)));
						$this->db->sql_query('UPDATE ' . $this->prefix . "s SET clicks=LEAST(18446744073709551615,clicks+1) WHERE banner_id=$id");
						$duration = max(0, $this->user->data['session_time'] - $this->user->data['session_start'] + (int) $this->config['session_length']);
						$this->db->sql_query("INSERT INTO $hits " . $this->db->sql_build_array('INSERT', array('banner_id'=>$id, 'hit_time'=>$now,
							'ip'=>$ip,'user_id'=>(int) $this->user->data['user_id'],'source_user'=>0,'duration'=>$duration)));
					}
				}
				if (!$this->db->sql_transaction('commit')) { throw new \RuntimeException('Banner click transaction did not commit'); }
			}
			catch (\Throwable $e) { if ($this->db->get_transaction()) { $this->db->sql_transaction('rollback'); } throw $e; }
			$this->user->set_cookie('plus_b_' . $id, '1', $expires);
		}
		return new RedirectResponse(catalog::external($row['target_url']), 302, array('Cache-Control'=>'private, no-store', 'Referrer-Policy'=>'no-referrer'));
	}
}
