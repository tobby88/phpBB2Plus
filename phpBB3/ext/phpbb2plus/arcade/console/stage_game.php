<?php
namespace phpbb2plus\arcade\console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class stage_game extends Command
{
	private $media;
	public function __construct($media) { $this->media = $media; parent::__construct(); }

	protected function configure()
	{
		$this->setName('phpbb2plus:arcade:stage-game')->setDescription('Prepare local game media for the Arcade ACP.')
			->addArgument('main-file',InputArgument::OPTIONAL,'Local entry file, for example /offline/games/puzzle/puzzle.swf')
			->addOption('asset',null,InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,'Relative auxiliary file under the entry directory (repeatable)')
			->addOption('icon',null,InputOption::VALUE_REQUIRED,'Optional relative bitmap to use as the game icon')
			->addOption('discard',null,InputOption::VALUE_REQUIRED,'Remove an unassigned preparation using its full preparation ID');
	}

	protected function execute(InputInterface $input, OutputInterface $output)
	{
		$file = $input->getArgument('main-file');
		$discard = $input->getOption('discard');
		$assets = $input->getOption('asset');
		$icon = $input->getOption('icon');
		if ($discard!==null)
		{
			if ($file!==null || $assets || $icon!==null) { throw new \InvalidArgumentException('Pass only --discard and its preparation ID.'); }
			$this->media->discard($discard);
			$output->writeln('Unassigned preparation discarded.');
		}
		else
		{
			if (!$file) { throw new \InvalidArgumentException('Choose a local main file.'); }
			list($key,$game) = $this->media->prepare($file,$assets,(string) $icon);
			$output->writeln('Preparation ID: ' . $key);
			if ($game) { $output->writeln('Already assigned to Arcade game #' . $game . '.'); }
		}
		return 0;
	}
}
