<?php
namespace phpbb2plus\community\attachment;

/**
 * Narrow the native forum/PM group selection for new attachments. The shared
 * cache and the native attachment download service retain the original groups.
 */
class upload_types extends \phpbb\cache\service
{
	public function obtain_attach_extensions($forum_id)
	{
		$extensions = parent::obtain_attach_extensions($forum_id);
		if ((int) ($this->config['plus_attach_upload_types'] ?? -1) !== 1 || !isset($extensions['_allowed_']))
		{
			return $extensions;
		}
		foreach (array_keys($extensions['_allowed_']) as $type)
		{
			// phpBB2 Plus phpbb_forbidden_upload_extension(), applied before accepting a new file.
			if (!preg_match('/^[a-z0-9]{1,16}$/D', $type) || preg_match('/^php[0-9]*$/D', $type) ||
				in_array($type, array('phtml', 'phar', 'cgi', 'pl', 'py', 'sh', 'shtml', 'shtm', 'asp', 'aspx', 'jsp'), true))
			{
				unset($extensions['_allowed_'][$type], $extensions[$type]);
			}
		}
		return $extensions;
	}
}
