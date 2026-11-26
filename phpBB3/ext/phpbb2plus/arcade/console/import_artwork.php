<?php
namespace phpbb2plus\arcade\console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class import_artwork extends Command
{
	private $artwork;

	public function __construct($artwork) { $this->artwork = $artwork; parent::__construct(); }

	protected function configure()
	{
		$this->setName('phpbb2plus:arcade:import-artwork')->setDescription('Restore Arcade game and category pictures from an offline phpBB2 Plus board.')
			->addArgument('source-board', InputArgument::REQUIRED, 'Directory containing the original games and design images');
	}

	protected function execute(InputInterface $input, OutputInterface $output)
	{
		$source = $this->artwork->source_root($input->getArgument('source-board'));
		$after = $found = $checked = 0;
		do
		{
			$batch = $this->artwork->batch($after, $source);
			$found += $batch['found'];
			$checked += $batch['checked'];
			$after = $batch['after'];
		}
		while ($after);
		$output->writeln(sprintf('Verified artwork for %d of %d games in the source copy.', $found, $checked));
		$after = $found = $checked = 0;
		do
		{
			$batch = $this->artwork->category_batch($after, $source);
			$found += $batch['found'];
			$checked += $batch['checked'];
			$after = $batch['after'];
		}
		while ($after);
		$output->writeln(sprintf('Verified artwork for %d of %d configured category pictures.', $found, $checked));
		return 0;
	}
}
