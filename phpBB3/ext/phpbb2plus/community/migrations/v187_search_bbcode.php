<?php
namespace phpbb2plus\community\migrations;

class v187_search_bbcode extends v162_acronym_formatter
{
	public static function depends_on() { return array('\phpbb2plus\community\migrations\v186_floating_images'); }
}
