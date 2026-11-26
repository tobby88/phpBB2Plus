<?php
namespace phpbb2plus\community\console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class orphan_posts extends Command
{
	private $db, $table, $references;

	public function __construct($db, $prefix)
	{
		$this->db = $db;
		$this->table = $prefix . 'plus_orphan_posts';
		$this->references = $prefix . 'plus_attach_refs';
		parent::__construct();
	}

	protected function configure()
	{
		$this->setName('phpbb2plus:recovery:posts')->setDescription('List source post IDs whose forum and author could not be recovered.')
			->addArgument('post-id', InputArgument::OPTIONAL, 'Show original markup and surviving attachment IDs for one source post as JSON.');
	}

	protected function execute(InputInterface $input, OutputInterface $output)
	{
		$id = $input->getArgument('post-id');
		if ($id === null)
		{
			$result = $this->db->sql_query('SELECT source_id FROM ' . $this->table . ' ORDER BY source_id');
			while ($row = $this->db->sql_fetchrow($result)) { $output->writeln((string) $row['source_id']); }
			$this->db->sql_freeresult($result);
			return 0;
		}
		if (!ctype_digit($id) || (string) (int) $id !== $id)
		{
			$output->writeln('<error>Enter a numeric source post ID.</error>');
			return 1;
		}
		$result = $this->db->sql_query('SELECT source_json FROM ' . $this->table . ' WHERE source_id = ' . (int) $id);
		$payload = $this->db->sql_fetchfield('source_json');
		$this->db->sql_freeresult($result);
		if ($payload === false)
		{
			$output->writeln('<error>No recovered text has this ID.</error>');
			return 1;
		}
		$row = array('post_id' => (int) $id) + json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
		$row['attachments'] = array();
		$result = $this->db->sql_query('SELECT r.source_id, a.attach_id FROM ' . $this->references . ' r JOIN ' . ATTACHMENTS_TABLE . ' a
			ON a.attach_id=r.attach_id WHERE r.in_message=0 AND r.parent_id=' . (int) $id . '
			AND a.is_orphan=1 AND a.post_msg_id=0 AND a.in_message=0 AND a.poster_id=0 ORDER BY r.source_id, a.attach_id');
		while ($file = $this->db->sql_fetchrow($result)) { $row['attachments'][] = array_map('intval', $file); }
		$this->db->sql_freeresult($result);
		$output->writeln(json_encode($row, JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
		return 0;
	}
}
