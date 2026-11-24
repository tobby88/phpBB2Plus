<?php
namespace phpbb2plus\knowledgebase\cron;

class notices extends \phpbb\cron\task\base
{
	private $db;
	private $config;
	private $articles;
	private $prefix;
	private $root;
	private $php_ext;

	public function __construct($db, $config, $articles, $prefix, $root, $php_ext)
	{
		$this->db = $db;
		$this->config = $config;
		$this->articles = $articles;
		$this->prefix = $prefix;
		$this->root = $root;
		$this->php_ext = $php_ext;
	}

	public function should_run() { return (int) $this->config['plus_kb_mail_time'] + 600 < time(); }

	public function run()
	{
		$now = time();
		$lifetime = (int) $this->config['form_token_lifetime'];
		if ($lifetime > 0) { $this->db->sql_query('DELETE FROM ' . $this->prefix . 'submissions WHERE created_at<' . ($now - $lifetime - 86400)); }
		$table = $this->prefix . 'mail';
		$result = $this->db->sql_query_limit("SELECT article_id,revision FROM $table WHERE lease_until<$now ORDER BY queued_at", 20);
		$jobs = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);
		foreach ($jobs as $job)
		{
			$where = 'article_id=' . (int) $job['article_id'] . ' AND revision=' . (int) $job['revision'];
			$token = bin2hex(random_bytes(16));
			$this->db->sql_query("UPDATE $table SET lease_until=" . ($now + 3600) . ",claim_token='$token' WHERE $where AND lease_until<$now");
			if ($this->db->sql_affectedrows() !== 1) { continue; }
			$result = $this->db->sql_query('SELECT article_id,title,approval,revision FROM ' . $this->prefix . 'articles WHERE ' . $where);
			$article = $this->db->sql_fetchrow($result);
			$this->db->sql_freeresult($result);
			if (!$article || (int) $article['approval'] === 1 || (int) $this->articles->setting('notify', 0) !== 2)
			{
				$this->db->sql_query("DELETE FROM $table WHERE $where AND claim_token='$token'");
				continue;
			}
			$recipient = $this->config['board_email'];
			if (!$this->config['email_enable'] || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) { continue; }
			include_once $this->root . 'includes/functions_messenger.' . $this->php_ext;
			$mail = new \messenger();
			$mail->template('@phpbb2plus_knowledgebase/pending_article', $this->config['default_lang']);
			$mail->to($recipient);
			$mail->assign_vars(array('ARTICLE_TITLE' => $article['title'], 'U_ARTICLE' => rtrim(generate_board_url(), '/') . '/app.' . $this->php_ext . '/knowledge/article/' . $article['article_id']));
			if ($mail->send(NOTIFY_EMAIL))
			{
				$mail->save_queue();
				$this->db->sql_query("DELETE FROM $table WHERE $where AND claim_token='$token'");
			}
		}
		$this->config->set('plus_kb_mail_time', $now, true);
	}
}
