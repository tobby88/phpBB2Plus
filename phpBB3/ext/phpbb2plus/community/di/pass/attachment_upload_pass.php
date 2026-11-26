<?php
namespace phpbb2plus\community\di\pass;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

class attachment_upload_pass implements CompilerPassInterface
{
	public function process(ContainerBuilder $container)
	{
		if ($container->hasDefinition('phpbb2plus.community.attachment_types') && $container->hasDefinition('attachment.upload'))
		{
			// Keep phpBB's uploader and its decorators. Only the uploader's view of the group cache changes.
			$container->getDefinition('attachment.upload')->setArgument('$cache', new Reference('phpbb2plus.community.attachment_types'));
		}
	}
}
