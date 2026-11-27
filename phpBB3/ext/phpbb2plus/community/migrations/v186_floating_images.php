<?php
namespace phpbb2plus\community\migrations;

class v186_floating_images extends v162_acronym_formatter
{
	public static function depends_on() { return array('\phpbb2plus\community\migrations\v185_scrolling_text'); }
}
