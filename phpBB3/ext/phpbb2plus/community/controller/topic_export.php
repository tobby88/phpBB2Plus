<?php
namespace phpbb2plus\community\controller;

use phpbb2plus\community\service\topic_archive;
use phpbb2plus\community\service\topic_text;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class topic_export
{
	private $reader;
	private $db;
	private $user;
	private $auth;
	private $language;
	private $request;
	private $config;
	private $prefix;

	public function __construct($reader, $db, $user, $auth, $language, $request, $config, $prefix)
	{
		$this->reader = $reader;
		$this->db = $db;
		$this->user = $user;
		$this->auth = $auth;
		$this->language = $language;
		$this->request = $request;
		$this->config = $config;
		$this->prefix = $prefix;
	}

	private function check_storage()
	{
		$tables = array(CONFIG_TABLE, SESSIONS_TABLE, USERS_TABLE, BANLIST_TABLE, FORUMS_TABLE, FORUMS_ACCESS_TABLE,
			TOPICS_TABLE, POSTS_TABLE, ZEBRA_TABLE, WORDS_TABLE, $this->prefix . 'plus_bylines', $this->prefix . 'plus_former_names');
		// Hold shared metadata locks until commit so the checked tables cannot
		// be replaced with nontransactional tables partway through the archive.
		$result = $this->db->sql_query('SELECT 1 FROM ' . implode(',', $tables) . ' LIMIT 0');
		$this->db->sql_freeresult($result);
		$result = $this->db->sql_query("SELECT COUNT(*) AS present FROM information_schema.TABLES
			WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' AND ENGINE='InnoDB' AND " . $this->db->sql_in_set('TABLE_NAME', $tables));
		$count = (int) $this->db->sql_fetchfield('present');
		$this->db->sql_freeresult($result);
		if ($count !== count($tables)) { throw new \RuntimeException('Text archive tables require transactional reads'); }
	}

	private function session()
	{
		$id = (int) $this->user->data['user_id'];
		$result = $this->db->sql_query('SELECT u.* FROM ' . USERS_TABLE . ' u JOIN ' . SESSIONS_TABLE .
			" s ON s.session_user_id=u.user_id WHERE u.user_id=$id AND s.session_id='" . $this->db->sql_escape($this->user->session_id) . "'");
		$current = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		if (!$current || (int) $current['user_type'] === USER_INACTIVE) { throw new AccessDeniedHttpException(); }
		$current['is_registered'] = $id !== ANONYMOUS && in_array((int) $current['user_type'], array(USER_NORMAL,USER_FOUNDER), true);
		$ips = $this->config['forwarded_for_check'] ? array_merge(explode(' ', $this->user->forwarded_for), array($this->user->ip)) : $this->user->ip;
		if ($current['is_registered'] !== (bool) $this->user->data['is_registered'] ||
			((int) $current['user_type'] !== USER_FOUNDER && $this->user->check_ban($id, $ips, false, true)))
		{ throw new AccessDeniedHttpException(); }
		// Native request setup filled this bitstring before the separate forum challenge.
		// An intervening permission change invalidates it. Let a new request initialise it.
		if (!trim($current['user_permissions'])) { throw new AccessDeniedHttpException(); }
		$this->auth->acl($current);
	}

	public function download($topic_id, $post_id)
	{
		$filters = strtolower($this->request->variable('postorder', '')) === 'desc' ? array('postorder' => 'desc') : array();
		$route = $post_id ? 'phpbb2plus_community_post_export' : 'phpbb2plus_community_topic_export';
		$params = ($post_id ? array('post_id' => (int) $post_id) : array('topic_id' => (int) $topic_id)) + $filters;
		$entry = $this->reader->open($topic_id, $post_id, $route, $params);
		$stream = null;
		try
		{
			// On the supported MySQL/MariaDB board the entire file uses one consistent
			// snapshot. Complete it before sending a filename, length or document bytes.
			if ($this->db->get_transaction() || strpos($this->db->get_sql_layer(), 'mysql') !== 0)
			{ throw new \RuntimeException('Text archive requires its own MySQL/MariaDB snapshot'); }
			$this->db->sql_query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
			$this->db->sql_transaction('begin');
			try
			{
				$this->check_storage();
				$this->session();
				$topic = $this->reader->open($topic_id, $post_id, $route, $params, false);
				if ($entry['forum_id'] !== $topic['forum_id'] || $entry['forum_password'] !== $topic['forum_password'])
				{ throw new AccessDeniedHttpException(); }
				list($stream, $bytes) = topic_archive::build($topic, $this->reader->posts($topic, $filters, false), $this->language);
				if (!$this->db->sql_transaction('commit')) { throw new \RuntimeException('Cannot finish text archive'); }
			}
			finally { if ($this->db->get_transaction()) { $this->db->sql_transaction('rollback'); } }
			$headers = array('Content-Type' => 'text/plain; charset=UTF-8', 'Content-Length' => $bytes,
				'Content-Disposition' => topic_text::disposition(censor_text($topic['topic_title']), $topic['topic_id']),
				'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Vary' => 'Cookie');
			if ($this->request->server('REQUEST_METHOD') === 'HEAD') { return new Response('', 200, $headers); }
			$response = new StreamedResponse(static function () use ($stream) {
				try { fpassthru($stream); } finally { fclose($stream); }
			}, 200, $headers);
			$stream = null; // The response owns the finished spool.
			return $response;
		}
		catch (HttpExceptionInterface $error) { throw $error; }
		catch (\LengthException $error) { throw new HttpException(413, $this->language->lang('PLUS_TOPIC_EXPORT_TOO_LARGE')); }
		catch (\UnderflowException $error) { throw new NotFoundHttpException(); }
		catch (\RuntimeException $error) { throw new HttpException(503, $this->language->lang('PLUS_TOPIC_EXPORT_UNAVAILABLE'), $error); }
		finally { if ($stream !== null) { fclose($stream); } }
	}
}
