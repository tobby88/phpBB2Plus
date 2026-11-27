<?php
namespace phpbb2plus\community\console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class recover_news extends Command
{
	private $db, $config;
	public function __construct($db, $config) { $this->db=$db; $this->config=$config; parent::__construct(); }
	protected function configure()
	{
		$this->setName('phpbb2plus:recovery:news')->setDescription('Check earlier converted topics against an original Plus snapshot on this database server.')
			->addArgument('source-schema', InputArgument::REQUIRED, 'Read-only source database containing the original phpBB2 Plus tables.')
			->addOption('source-prefix', null, InputOption::VALUE_REQUIRED, 'Source table prefix.', 'phpbb_')
			->addOption('apply', null, InputOption::VALUE_NONE, 'Restore the list position of matching original type-4 topics.');
	}
	protected function execute(InputInterface $input, OutputInterface $output)
	{
		$schema=$input->getArgument('source-schema');
		$prefix=$input->getOption('source-prefix');
		if (empty($this->config['plus_topic_groups_ready']) || !preg_match('/^[a-z0-9_]{1,64}$/iD',$schema) ||
			!preg_match('/^[a-z0-9_]{0,58}$/iD',$prefix))
		{ $output->writeln('<error>Use an upgraded board and a valid database and table prefix.</error>'); return 1; }
		$source='`'.$schema.'`.`'.$prefix.'topics`';
		$source_posts='`'.$schema.'`.`'.$prefix.'posts`';
		$result=$this->db->sql_query("SELECT MAX(topic_id) AS last_id FROM $source WHERE topic_type=4");
		$last=(int) $this->db->sql_fetchfield('last_id');
		$this->db->sql_freeresult($result);
		$join=' FROM ' . $source . ' s LEFT JOIN ' . $source_posts . ' sp ON sp.post_id=s.topic_first_post_id AND sp.topic_id=s.topic_id
			LEFT JOIN ' . TOPICS_TABLE . ' t ON t.topic_id=s.topic_id LEFT JOIN ' . POSTS_TABLE . ' p ON p.post_id=t.topic_first_post_id AND p.topic_id=t.topic_id';
		$match='t.plus_list_news=-1 AND t.topic_type=' . POST_NORMAL . ' AND t.topic_status=s.topic_status AND t.topic_status<>' . ITEM_MOVED .
			' AND t.topic_visibility=' . ITEM_APPROVED . ' AND s.forum_id=t.forum_id AND t.topic_first_post_id=s.topic_first_post_id
			AND t.topic_time=s.topic_time AND BINARY CONVERT(t.topic_title USING utf8mb4)=BINARY CONVERT(s.topic_title USING utf8mb4)
			AND sp.post_id IS NOT NULL AND p.post_id IS NOT NULL AND p.post_edit_time=COALESCE(sp.post_edit_time,0)
			AND NOT EXISTS (SELECT 1 FROM ' . LOG_TABLE . " l WHERE l.topic_id=t.topic_id AND l.log_operation='LOG_TOPIC_TYPE_CHANGED')";
		$after=$eligible=$restored=$already=$skipped=0;
		while ($after < $last)
		{
			$result=$this->db->sql_query_limit('SELECT s.topic_id,t.plus_list_news,CASE WHEN ' . $match . ' THEN 1 ELSE 0 END AS matched' .
				$join . " WHERE s.topic_type=4 AND s.topic_id>$after AND s.topic_id<=$last ORDER BY s.topic_id",250);
			$rows=$this->db->sql_fetchrowset($result); $this->db->sql_freeresult($result);
			if (!$rows) { break; }
			$ids=array();
			foreach ($rows as $row)
			{
				$after=(int) $row['topic_id'];
				if ((int) $row['matched']) { $ids[]=$after; }
				elseif ((int) $row['plus_list_news']===1) { ++$already; }
				else { ++$skipped; }
			}
			$eligible+=count($ids);
			if ($ids && $input->getOption('apply'))
			{
				// Recheck source identity and the operator's current native decisions in the writing statement.
				$this->db->sql_query('UPDATE ' . TOPICS_TABLE . ' t JOIN ' . $source . ' s ON s.topic_id=t.topic_id
					JOIN ' . $source_posts . ' sp ON sp.post_id=s.topic_first_post_id AND sp.topic_id=s.topic_id
					JOIN ' . POSTS_TABLE . ' p ON p.post_id=t.topic_first_post_id AND p.topic_id=t.topic_id SET t.plus_list_news=1
					WHERE s.topic_type=4 AND ' . $match . ' AND ' . $this->db->sql_in_set('s.topic_id',$ids));
				$restored+=$this->db->sql_affectedrows();
			}
		}
		$output->writeln(json_encode(array('eligible'=>$eligible,'restored'=>$restored,'already'=>$already,'skipped'=>$skipped),JSON_THROW_ON_ERROR));
		return 0;
	}
}
