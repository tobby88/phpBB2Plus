<?php
namespace phpbb2plus\community\service;

class statistics_modules
{
	// Hash the original controller and template together after normalising line endings.
	public const SOURCES = array(
		'forum_index' => 'cc2141bce4945332fc8aa2200ff0a5b0e215c0b62c2e944045413ca678d1ba5d',
		'most_active_topics' => '1aa9c688b5cb047801d7683118ad8fe69877e24096bcff9339b477351a2a92c7',
		'most_viewed_topics' => '3623a14cb864d44dd5895452643b7e0f5d2597d44fbd4ab5d8b746d58edb00fb',
		'top_posters' => 'c9bd1b3b9907e4b8951492dd5617345d87b89c2e24690e3987f6e0c8e07c6daa',
		'site_hist_week_top_posters' => '358c548f1b29440ac4ed3ffd3334dbfa625bcc63f46bc81761c8bdf830f4bc5f',
		'site_hist_month_top_posters' => '6b13a1a71e9494f6c9ba9ac284b962f6815e86a09b8646da515da10f6d634a4f',
		'most_used_languages' => '166eabfd20e4832f46b56683afd21234c1503bc3be26736592fd440b14238aac',
		'top_attachments' => '81ef3b4b15cd850e783834c5fae41eccfb4fea2f85218b491b2a221369c87594',
		'admin_statistics' => '1607bdd051f289351435aab2ac6508f37f8be58d84565ab174afcac5a2470e87',
		'top_smilies' => '48bb907ef959fd6e90778d79f2f438857e6270f52bab606acffabf2dc8f29b2d',
	);

	public static function recognised($name, $digest)
	{
		return isset(self::SOURCES[$name]) && self::SOURCES[$name] === $digest;
	}
}
