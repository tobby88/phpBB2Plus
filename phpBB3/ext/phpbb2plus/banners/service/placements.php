<?php
namespace phpbb2plus\banners\service;

class placements extends \Twig\Extension\AbstractExtension
{
	private $catalog;
	public function __construct($catalog) { $this->catalog = $catalog; }
	public function getFunctions()
	{
		return array(new \Twig\TwigFunction('plus_banner_slots', array($this->catalog, 'slots')));
	}
}
