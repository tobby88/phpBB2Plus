<?php
namespace phpbb2plus\community\attachment;

/**
 * Narrow the native forum/PM group selection for new attachments. The shared
 * cache and the native attachment download service retain the original groups.
 */
class upload_types extends \phpbb\cache\service
{
	private $blocks;

	public function set_blocks(forbidden_extensions $blocks) { $this->blocks = $blocks; }

	public function obtain_attach_extensions($forum_id)
	{
		$extensions = parent::obtain_attach_extensions($forum_id);
		foreach ($this->blocks->blocked_types(array_keys($extensions['_allowed_'] ?? array())) as $type => $unused)
		{
			unset($extensions['_allowed_'][$type], $extensions[$type]);
		}
		return $extensions;
	}
}
