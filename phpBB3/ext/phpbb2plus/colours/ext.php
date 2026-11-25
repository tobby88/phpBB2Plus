<?php
namespace phpbb2plus\colours;

class ext extends \phpbb\extension\base
{
	private function step($after, $enabled)
	{
		// phpBB can run this hook while the extension's services are unavailable.
		$palette = new service\palette($this->container->get('dbal.conn'), $this->container->get('config'),
			$this->container->getParameter('core.table_prefix') . 'plus_colours');
		$last = $palette->batch($after,$enabled);
		return $last ? array('after'=>$last) : false;
	}

	public function enable_step($old_state)
	{
		if (!is_array($old_state) && parent::enable_step($old_state)) { return true; }
		return $this->step(is_array($old_state) ? $old_state['after'] : 0, true);
	}

	public function disable_step($old_state)
	{
		return $this->step(is_array($old_state) ? $old_state['after'] : 0, false);
	}
}
