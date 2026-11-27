<?php
namespace phpbb2plus\calendar;

use Symfony\Component\Console\ConsoleEvents;

class ext extends \phpbb\extension\base
{
	private function finish_step($next)
	{
		if (!$next && PHP_SAPI === 'cli')
		{
			// The ACP clears Twig and the web container after saving ext_active. The console ends at this event instead.
			$cache = $this->container->get('cache.driver');
			$this->container->get('dispatcher')->addListener(ConsoleEvents::TERMINATE, static function () use ($cache) { $cache->purge(); });
		}
		return $next;
	}

	public function enable_step($old_state)
	{
		return $this->finish_step(parent::enable_step($old_state));
	}

	public function disable_step($old_state)
	{
		return $this->finish_step(parent::disable_step($old_state));
	}

	public function purge_step($old_state)
	{
		return $this->finish_step(parent::purge_step($old_state));
	}
}
