<?php
if (!defined('IN_PHPBB')) { exit; }

use phpbb2plus\community\auth\junior_admin;

function plus_jr_source_present($source_db, $prefix)
{
	$table = $source_db->sql_escape($prefix . 'jr_admin_users');
	$result = $source_db->sql_query("SELECT COUNT(*) AS present FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$table'");
	$present = (bool) $source_db->sql_fetchfield('present');
	$source_db->sql_freeresult($result);
	return $present;
}

function plus_jr_source_directory($root)
{
	$helper = @file_get_contents(plus_media_path($root, 'includes/functions_jr_admin.php'));
	if ($helper === false) { return false; }
	$tokens = array_values(array_filter(token_get_all($helper), static function ($token)
	{
		return !is_array($token) || !in_array($token[0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true);
	}));
	$directory = null;
	for ($i = 0, $limit = count($tokens) - 5; $i < $limit; $i++)
	{
		if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_STRING || strtolower($tokens[$i][1]) !== 'define' ||
			$tokens[$i + 1] !== '(' || jr_admin_registration_value(array($tokens[$i + 2]), '') !== 'JR_ADMIN_DIR') { continue; }
		if ($directory !== null || $tokens[$i + 3] !== ',' || !is_array($tokens[$i + 4]) ||
			$tokens[$i + 4][0] !== T_CONSTANT_ENCAPSED_STRING || $tokens[$i + 5] !== ')') { return false; }
		$directory = jr_admin_registration_value(array($tokens[$i + 4]), '');
	}
	if (!is_string($directory) || !preg_match('~^[a-z0-9_-]+(?:/[a-z0-9_-]+)*/?$~iD', $directory)) { return false; }
	return plus_media_path($root, rtrim($directory, '/'), true);
}

function plus_jr_source_matches($contents, $digest)
{
	return is_string($contents) && hash('sha256', str_replace(array("\r\n", "\r"), "\n", $contents)) === $digest;
}

function plus_jr_reviewed_dependencies($root, $directory, array $dependencies)
{
	foreach ($dependencies as $file => $digest)
	{
		// A sibling controller lives in the installation's actual ACP directory.
		$base = strpos($file, '/') === false ? $directory : $root;
		try { $path = plus_media_path($base, $file); }
		catch (\RuntimeException $e) { return false; }
		$contents = @file_get_contents($path);
		if ($file === 'includes/functions_jr_admin.php' && is_string($contents))
		{
			// A renamed ACP directory changes this literal but leaves the actual file-level grant intact.
			$contents = preg_replace("~^define\\('JR_ADMIN_DIR', '[A-Za-z0-9_/-]+'\\);(?=\\r?$)~m",
				"define('JR_ADMIN_DIR', 'admin/');",str_replace(array("\r\n","\r"),"\n",$contents));
		}
		if (!plus_jr_source_matches($contents, $digest)) { return false; }
	}
	return true;
}

function plus_jr_registry()
{
	global $convert;
	// This parser is shipped from the trusted Plus distribution by the assembler.
	// Source controllers are read strictly as text, including on customised boards.
	require_once __DIR__ . '/plus_jr_registrations.php';
	try { $directory = plus_jr_source_directory($convert->options['forum_path']); }
	catch (\RuntimeException $e) { return false; }
	if ($directory === false) { return false; }
	$files = @scandir($directory);
	if ($files === false) { return false; }
	// A locally modified module can expose fewer actions under the same name. These
	// fingerprints identify the reviewed source controllers; line endings are immaterial.
	$reviewed = array(
		'admin_arcade_cats.php' => '1f783390224f152c354e205f56ec52e4dc47d10e276c7595038498dc0bf03daf',
		'admin_arcade_log.php' => '667e4ab70846777eaddfe4753e820b360b83ea2aa03373ee71106da357350848',
		'admin_links.php' => '653aa54f528987a56e61e2320f4f15aba1562bbc98cc1e64b1c01346cab5bce9',
		'admin_links_cat.php' => 'fc151f2c9eb7087eb75334e046dcaf4516975c159d4ba8d6fc019e79191fffae',
		'admin_links_config.php' => '9d09344b11f3f4bc2f4610db7f9deb101cd1ee510b77bf5d5a39009e68940f66',
		'admin_flags.php' => '9476a8fb3a1bee3a8b856476f70b0957b765de637c889ef683d42052c9d62a41',
		'admin_pa_file.php' => '4993fafef0f0f5702e1bb5f23163da0b5372838f9bb67d856dccce3e729e8adb',
		'admin_pa_settings.php' => 'f8b7ebf1eefe344f2e22bd0eb31097755b4b7f66271ba6ff105bad7837eca6f3',
		'admin_pa_fchecker.php' => 'f1d2f21d23ec2b9d2b59858a0dace8074df9b974c0f38babd17150588112f45d',
		'admin_statistics.php' => '9dae7adc915d806601d0140450537a82d430d8039f06e095ae763b32972980e1',
		'admin_pa_custom.php' => '9237b12da9f50adbc87ff38da3b3550063a92d017dc628b410ee65a69927350b',
		'admin_album_personal.php' => 'd97d4e085e90eac081030134f6894b60b3e4824ee6d1ef88ad82bd1fd36f5d87',
		'admin_album_clearcache.php' => 'd84fe34e3710e149884660098985a99d796695ec1ea0f0424d0802bcd8330b3a',
		'admin_cracker_tracker.php' => '69cb6fe1cc40cf20c5cf0a09fb3d4ca40d849c40d27d83d537db4d0a0d0483eb',
		'admin_words.php' => '5d8a246e3aa046ed04b6e9346336e66ca0a79031ad0c2a8aa81f4b8d8a257e59',
		'admin_disallow.php' => '5da99a11b96be1a5020ceca18693aaa0e6339e10ba40f91f18405b71ae7fc8fd',
		'admin_ranks.php' => 'ae0f662dc79f4e9a203a32e4193d983c33db4666f76e684aa81fc12296fcfd1e',
		'admin_kb_art.php' => '2a92d60fe3c5772a0fce2bd09f10fabbee72e185fc99c3ce1ecc843aaca6f5ff',
		'admin_kb_types.php' => '9176f2a2cea0f652c22e7111bd70442cc29da931f758dd4f882ba0369994fb46',
		'admin_kb_cat.php' => '863dc52eacce55c3c07f348a334afb4b12f5d807b796e390184e416e58b34b39',
		'admin_pa_category.php' => 'e9ce286f24b744c64d27055848046ddd874a23eb99f9bbe3b4e6e8b3f7ed3b00',
		'admin_pa_catauth.php' => '82f9f61690992ab2986c841535e13cd0678d838f0c180b1c8166a2cf043b489e',
		'admin_pa_license.php' => '32dc2e04ca66019c8b2e02cf77a14d2ad15256543844ab154bd915ead4fbb76c',
		'admin_album_cat.php' => '621ff302762cb0bad95c8a0039445bbdc1b94b4d7ee3d01e7fe669b30447c406',
		'admin_album_auth.php' => '6a79dbf47486d217b4660903ef30efa8b35a85c99daa72b04bd88766e2742767',
		'admin_arcade_games.php' => 'b4318ce3863b0ea4d3681d53fd50ea15c1726b47e932368d87aaac12c0e6dd7c',
		'admin_hacks_list.php' => '12e39f97b24f72134803b9d4e82dbf0453240bc5c18ddda038278d4a3d73e9f1',
		'admin_acronyms.php' => '3d455815073f5beb82e843b190a3d9a78488de47f52ee2568803eed8eb04e68b',
	);
	// These controllers share or delegate their actual write authority. A customised
	// or absent helper can narrow the source grant despite an unchanged menu entry.
	$file_authority = array(
		'includes/functions_jr_admin_auth.php' => '66560bc6fe4af98e0d6870c71b5b3682fb131ae6f13d2545aab481b0d5f2c735',
		'pafiledb/includes/functions_file_authority.php' => 'd875f99ae5eafa9a2398fbc89fefd86cc406a7d91633290834c62b77023ba2a1',
	);
	$download_writes = $file_authority + array(
		'pafiledb/pafiledb_common.php' => 'b5af356469606b749cf2c37e25898806e7cdeb7bc9ebd0b3c4a12e8ca0e069dc',
		'pafiledb/includes/pafiledb_constants.php' => '8dc66d466715450c7b8f98d0a434967819b58f3491a5372178ea190508a9b334',
		'pafiledb/includes/functions_pafiledb.php' => '13897bc0b31c57f902c158b91bf3b76961773c450b3c407ca102711d587f9d3e',
		'pafiledb/includes/functions_file_actions.php' => 'be947ea69f52eb0865481ba9c4c0d39417103939fa52ac79ea80c999eab3910e',
		'pafiledb/includes/functions_file_coordinator.php' => 'fc97309b22c5dfa8b947377491b1833e41ba377444057ddac0e5fccbfdec1cc9',
		'pafiledb/includes/functions_file_storage.php' => '912614c637b393a8a2d224358079144310df2f6a11443356a815c260904e07e4',
		'pafiledb/includes/functions_mutation_storage.php' => 'c1aba8ca0e4eda2c68315962d3da42eac709f0ec17cc15f7c6bfb885eac72c15',
		'includes/functions_acl_storage.php' => '5b1cde62b86f837f345299e15b216ba916c9a1cbc64ef2c0a43bc98b9fd76354',
		'includes/functions_owned_storage.php' => 'e9dfcfda0c3c40bd785d60ff897721fdde0cc45ba400af15110c27e1026a6838',
	);
	$tracker = array(
		'ctracker/classes/class_ct_adminfunctions.php' => '5b477176fb5b1ff7120da693755695995e943bf22456103f8b533500da133fa6',
		'ctracker/classes/class_ct_database.php' => 'cf7d05a8520da871abd7a34e20153f53e2d1a0f04466d1549be4e2f9510f8360',
		'ctracker/classes/class_configuration_storage.php' => 'b00675f780f1085097bec974e9d8cbaaa3ff2de4a8f473dd34a078f5f9b0b7e0',
		'ctracker/classes/class_acp_storage.php' => 'c64b0d532650bd92f87a5b9060351078e8e0dc7811b886d989fbd417502e470b',
		'includes/functions_jr_admin_auth.php' => '66560bc6fe4af98e0d6870c71b5b3682fb131ae6f13d2545aab481b0d5f2c735',
		'includes/functions_acl_storage.php' => '5b1cde62b86f837f345299e15b216ba916c9a1cbc64ef2c0a43bc98b9fd76354',
		'includes/functions_owned_storage.php' => 'e9dfcfda0c3c40bd785d60ff897721fdde0cc45ba400af15110c27e1026a6838',
	);
	$link_writes = array(
		'pagestart.php' => '472b9cf9f94bf3ac132a9a4fc101a861ddc74dd374620915840500aa3a18887d',
		'includes/functions_jr_admin.php' => '2b790b97d59b8fe505cbc34d0963a8acf7412a6db865158347c284a2b8bb369e',
		'includes/functions_jr_admin_auth.php' => '66560bc6fe4af98e0d6870c71b5b3682fb131ae6f13d2545aab481b0d5f2c735',
		'includes/functions_link_admin.php' => '71ad3e721e8c852db12dc981da16ac7fc9d7acd93c712ce1c959b7894d704b63',
		'includes/functions_link_submission.php' => 'f7e7ec752fe5dd1b9c2035b9d77ce41a46e3163dbf09357751cd45ea5a5ab5c8',
		'includes/php_compat.php' => 'efc40a50b10beed315b050826a3792a251d877199e818f816f23ecbc5e9517ee',
	);
	$link_settings = $link_writes + array(
		'includes/functions.php' => '53228ac8b7b580c2a61efde2f82684a993e127bccfbcf08a2e17a2111e641091',
		'includes/functions_link_settings.php' => 'd29ceb470891dfc3285e6de7f741e301c6b9625b46da3c840494f2e6fff400b3',
	);
	$board_writes = array(
		'includes/functions_board_config.php' => '6f8bfd8b56abae2ac271ef236b1c916ca0fb96f68b74ca1a58680474a6a09568',
		'includes/functions_acl_storage.php' => '5b1cde62b86f837f345299e15b216ba916c9a1cbc64ef2c0a43bc98b9fd76354',
		'includes/functions_jr_admin_auth.php' => '66560bc6fe4af98e0d6870c71b5b3682fb131ae6f13d2545aab481b0d5f2c735',
		'includes/functions_moderator_identity.php' => '2cd91513548a3754831dc59ee50be5ffccecfbfba2b4f0921e6850b8f89e9c67',
		'includes/functions_connection_ownership.php' => '1c8277a28b18946baaad0fca9f2f64959fe07d09ede4fd6ec2f37223b153576e',
	);
	$dependencies = array(
		'admin_acronyms.php' => $board_writes + array('includes/functions_content_admin.php' => '7b1e555aa1a56dea597f60d910bc46526b17cfdc2020ec41b565f015c0c8e199'),
		'admin_arcade_log.php' => array(
			'pagestart.php'=>'472b9cf9f94bf3ac132a9a4fc101a861ddc74dd374620915840500aa3a18887d',
			'includes/functions_jr_admin.php'=>'2b790b97d59b8fe505cbc34d0963a8acf7412a6db865158347c284a2b8bb369e',
			'includes/functions_jr_admin_auth.php'=>'66560bc6fe4af98e0d6870c71b5b3682fb131ae6f13d2545aab481b0d5f2c735',
			'includes/functions_arcade_log_storage.php'=>'979af8b0855dd200f4cfec58fdbb317d84670b5d89ab2977608e281107cdb744',
			'includes/functions_arcade_import_storage.php'=>'eceb125848d15024831baf5614848aaabba50c985a8fbccc0f2ad1a78184bbca',
			'includes/functions_arcade.php'=>'1a9112338a29f16c662151a46412c4faf745515b50e63231d4ab8cd39e6ffb6f',
			'includes/classes_arcade.php'=>'aaeff39e5e537c0dd97cc90d96d437d27060c3636641085e8bb9404cc10a85df',
			'includes/functions_acl_storage.php'=>'5b1cde62b86f837f345299e15b216ba916c9a1cbc64ef2c0a43bc98b9fd76354',
			'includes/functions_owned_storage.php'=>'e9dfcfda0c3c40bd785d60ff897721fdde0cc45ba400af15110c27e1026a6838',
			'includes/functions_moderator_identity.php'=>'2cd91513548a3754831dc59ee50be5ffccecfbfba2b4f0921e6850b8f89e9c67',
			'attach_mod/includes/functions_quota_storage.php'=>'f477434f927a74278098e942bc3feb293f9829d1defc3c7fb527baf4a4382cab',
			'attach_mod/includes/functions_settings_storage.php'=>'adc3317c1aff635f55386e36c391ca40c1b7882b377b4dd6e181e97d15b93144',
			'includes/functions_board_config.php'=>'6f8bfd8b56abae2ac271ef236b1c916ca0fb96f68b74ca1a58680474a6a09568',
			'includes/functions_connection_ownership.php'=>'1c8277a28b18946baaad0fca9f2f64959fe07d09ede4fd6ec2f37223b153576e',
			'includes/php_compat.php'=>'efc40a50b10beed315b050826a3792a251d877199e818f816f23ecbc5e9517ee',
		),
		'admin_arcade_cats.php' => array(
			'includes/functions_arcade_category_storage.php' => '8008d26e6da64b13ef773a0d1ac91a5dd31681654e075f83813b30a4eba8718e',
			'includes/functions_arcade_last_play.php' => 'a75c6643f49d0c1da75cc3b9924044f4cfce5ce9e9ffc7912fe33e97780074ad',
			'includes/functions_arcade_import_storage.php' => 'eceb125848d15024831baf5614848aaabba50c985a8fbccc0f2ad1a78184bbca',
			'includes/functions_username.php' => 'cf916c3af3a6e7ea2703debdab9fd9e146a4f511222ebc38f6c0f1f540617fa4',
			'includes/functions_post_author.php' => 'b882c580235d597c4171b7534d9e46b167912e6b4ad07af8863f725adbf49049',
			'includes/functions_arcade.php' => '1a9112338a29f16c662151a46412c4faf745515b50e63231d4ab8cd39e6ffb6f',
			'includes/php_compat.php' => 'efc40a50b10beed315b050826a3792a251d877199e818f816f23ecbc5e9517ee',
			'attach_mod/includes/functions_quota_storage.php' => 'f477434f927a74278098e942bc3feb293f9829d1defc3c7fb527baf4a4382cab',
			'includes/functions_moderator_identity.php' => '2cd91513548a3754831dc59ee50be5ffccecfbfba2b4f0921e6850b8f89e9c67',
			'includes/functions_jr_admin_auth.php' => '66560bc6fe4af98e0d6870c71b5b3682fb131ae6f13d2545aab481b0d5f2c735',
			'includes/functions_acl_storage.php' => '5b1cde62b86f837f345299e15b216ba916c9a1cbc64ef2c0a43bc98b9fd76354',
			'includes/functions_owned_storage.php' => 'e9dfcfda0c3c40bd785d60ff897721fdde0cc45ba400af15110c27e1026a6838',
		),
		'admin_links.php' => $link_writes,
		'admin_links_config.php' => $link_settings,
		'admin_links_cat.php' => $link_settings + array('includes/functions_link_categories.php'=>'710e02aa8c62532d567d8fa8dae443daccde41c0ba518298b6223d95152b83d1'),
		'admin_flags.php' => $board_writes + array(
			'includes/functions_flags_storage.php' => '7a445923f0c0a98d4eb7c55ebfd103db3f5f7b1f267d63d7bacc865a029429e8',
		),
		'admin_arcade_games.php?mode=edit_games' => array(
			'admin_arcade_scores.php' => '8d3dbbd349ec9b53a551d191bccc10c1863129475ac40303098fb3234358e48d',
			'attach_mod/includes/functions_quota_storage.php' => 'f477434f927a74278098e942bc3feb293f9829d1defc3c7fb527baf4a4382cab',
			'includes/functions_arcade_score_storage.php' => 'be8661a1d1b4d7744cbb04b22cf796ca75951ccb2be604019acedf7b5bca54c1',
			'includes/functions_arcade_action_storage.php' => 'ff91b3077134d0d04f2c22eaff85907852b985914d261ce749e62bf3009fd92d',
			'includes/functions_arcade_game_storage.php' => 'ef6501856009da12b17583c9d159f8b1355756086376d0c65585254f67a4a25b',
			'includes/functions_arcade.php' => '1a9112338a29f16c662151a46412c4faf745515b50e63231d4ab8cd39e6ffb6f',
			'includes/functions_arcade_rankings.php' => 'd3fbe17e362be61c36ee587a77705ca9be4c2668f9f73242072fadf6345b7d48',
			'includes/functions_arcade_last_play.php' => 'a75c6643f49d0c1da75cc3b9924044f4cfce5ce9e9ffc7912fe33e97780074ad',
			'includes/constants_arcade.php' => '4bfc3b1c8c4f1d242aa507689e106c9aacb5bcff423d1dda6e2ce19824ac8a00',
			'includes/functions_arcade_import_storage.php' => 'eceb125848d15024831baf5614848aaabba50c985a8fbccc0f2ad1a78184bbca',
			'includes/functions_moderator_identity.php' => '2cd91513548a3754831dc59ee50be5ffccecfbfba2b4f0921e6850b8f89e9c67',
			'includes/functions_jr_admin_auth.php' => '66560bc6fe4af98e0d6870c71b5b3682fb131ae6f13d2545aab481b0d5f2c735',
			'includes/functions_acl_storage.php' => '5b1cde62b86f837f345299e15b216ba916c9a1cbc64ef2c0a43bc98b9fd76354',
			'includes/functions_owned_storage.php' => 'e9dfcfda0c3c40bd785d60ff897721fdde0cc45ba400af15110c27e1026a6838',
		),
		'admin_cracker_tracker.php?modu=4' => $tracker + array(
			'ctracker/admin/acp_module_globalmessage.php' => '57340603caf94dffa1143ef5dce694b4669a44cff9a416482c021d877198ca2f',
			'ctracker/classes/class_admin_actions.php' => '388eeed5489880bcb464a13cc70dd887bb730773af346ec86bcf6670acba4654',
		),
		'admin_cracker_tracker.php?modu=9' => $tracker + array(
			'ctracker/admin/acp_module_settings.php' => '644f5fffd4e82bda03c2461574577ca1de105d5d1ca18b5a90e9c78ef225bf58',
			'ctracker/classes/class_settings_storage.php' => '12f33ae9212e04652e8451bb3512c0637ce8f94ccd3e4da644702ecc37f00058',
		),
		'admin_pa_settings.php' => $download_writes + array(
			'pafiledb/includes/functions_settings_storage.php' => '840e087ee3913ebf75a0b98382742926e4840b57f69dccd4ba6b37120b536334',
		),
		'admin_pa_fchecker.php' => $download_writes + array(
			'pafiledb/includes/functions_file_checker.php' => '1c674e517cd43f9002875f2d930241baf6264a2a5e1c7586d6f457ec6aea3212',
			'pafiledb/includes/functions_file_reference.php' => '70fce49b6cbe87232cb86f4dbde01989d8c9eefda95960385656d3f06dc09b04',
		),
		'admin_pa_file.php' => $download_writes + array(
			'pafiledb/includes/functions_file_journal.php' => '72b42922fdc62a44ca30e035375572169c0ea45d9358805d73e4631ad211dbf9',
			'pafiledb/includes/functions_file_reference.php' => '70fce49b6cbe87232cb86f4dbde01989d8c9eefda95960385656d3f06dc09b04',
			'pafiledb/includes/functions_field.php' => '55cda748f5b69160f14244f22d4496a8520f4ab4160150828effb35b4428ea20',
			'pafiledb/includes/functions_mirror_storage.php' => '3e03516c4b245cd8c3c1c75fb561fddfad974603cc9ceb5efbaf969efbcb5304',
			'pafiledb/includes/functions_file_delete.php' => 'bd1eb0e1847cebe74951c40c6b9912b3da298eadeff75036891856c8e6b55b00',
		),
		'admin_statistics.php' => array(
			'includes/functions_stats.php' => 'df16dfb95b1f29bd7afc18b882d5516c0193fa63f0dbc09a640bd7ec5570f602',
			'includes/functions_module.php' => '886c98ef69101689102bcfa5bd9a973214ca4bfaee639d4f031910508ec7d045',
		),
		'admin_pa_custom.php' => $file_authority + array(
			'pafiledb/includes/functions_custom_storage.php' => '148a8b14339e9d4808f5283add8ec0dc6ddc3ad2bef7da0dabdfcd29ade4c0ee',
			'pafiledb/includes/functions_field.php' => '55cda748f5b69160f14244f22d4496a8520f4ab4160150828effb35b4428ea20',
		),
		'admin_pa_catauth.php' => $file_authority + array(
			'admin_pa_ug_auth.php' => 'fa7397cc5100fd2dbeb871477fc5bc166d63a5d3ff789df671ce1a5e09edcd01',
			'pafiledb/includes/functions_permission_storage.php' => '4b9503464cdb3e0e7a5ec3426a116f0c6e0cb6c251a6ad5ebebac1d78900c450',
		),
		'admin_pa_license.php' => $file_authority + array(
			'pafiledb/includes/functions_license_storage.php' => '65f0f934e1574795548a1d9a52add58a77b7a3fb5e72cf59e6f1b62efdf92300',
		),
		'admin_album_cat.php' => array(
			'album_mod/album_admin_category_storage.php' => '872eab0aaf53f1ec8201ff7b91dbfc44d334187c7bc813de5ed43f078f8b5f2f',
			'album_mod/album_category_remove.php' => 'd10eea4bd6208303ec8430c513ae51de64fbfc1dd322a27b8bee3367e45439ba',
			'album_mod/album_lifecycle_authority.php' => '0c7a663f8211eb2f317695d1e384a27f28de1eaaf422d74b250dfd8ced638349',
		),
		'admin_album_clearcache.php' => array(
			'album_mod/album_cache_maintenance.php' => '7dedbe1e48544d307a111054a5877346bf30c1b11e8b387683cf17c549650847',
			'album_mod/album_lifecycle_authority.php' => '0c7a663f8211eb2f317695d1e384a27f28de1eaaf422d74b250dfd8ced638349',
			'album_mod/album_file_storage.php' => '23a4935ca1f223fe34e55b4f2faf3af5db5ac3bc7e64fdfb3163f4e850615fdb',
			'album_mod/album_file_coordinator.php' => '398b792bdaaa7335ad122ef3321020a11ecfa6011d67e46de1039954afb1f7ca',
			'includes/functions_jr_admin_auth.php' => '66560bc6fe4af98e0d6870c71b5b3682fb131ae6f13d2545aab481b0d5f2c735',
		),
	);
	// Creating a game uses the common game writer. The separate score editor
	// is reachable from the original Edit entry only.
	$dependencies['admin_arcade_games.php?mode=add_game'] = array_diff_key($dependencies['admin_arcade_games.php?mode=edit_games'],
		array('admin_arcade_scores.php'=>true,'includes/functions_arcade_score_storage.php'=>true));
	$dependencies['admin_arcade_games.php?mode=import_game'] = $dependencies['admin_arcade_games.php?mode=add_game'];
	$registry = array();
	foreach ($files as $file)
	{
		if (!preg_match('/^admin_[a-z0-9_]+\.php$/iD', $file)) { continue; }
		try { $path = plus_media_path($directory, $file); }
		catch (\RuntimeException $e) { return false; }
		$source = @file_get_contents($path);
		$entries = $source !== false ? jr_admin_file_registrations($source, $file) : false;
		if ($entries === false) { return false; }
		foreach ($entries as $entry)
		{
			list($category, $label, $route) = $entry;
			$hash = md5($category . $label . $route);
			$record = array('category' => $category, 'module' => $label, 'route' => $route);
			$map = junior_admin::MODULES[$route] ?? null;
			if ($map && $file === explode('?', $route, 2)[0] && $category === $map[0] && $label === $map[1])
			{
				if (isset($reviewed[$file]) && plus_jr_source_matches($source, $reviewed[$file])
					&& plus_jr_reviewed_dependencies($convert->options['forum_path'], $directory, $dependencies[$route] ?? $dependencies[$file] ?? array()))
				{
					$options = array_keys($map[2]);
					if (count($options) === 1) { $record['option'] = $options[0]; }
					else { $record['options'] = $options; }
				}
				else { $record['customised'] = true; }
			}
			// A concatenated identity need not identify the same three individual strings.
			if (array_key_exists($hash, $registry) && $registry[$hash] !== $record) { $record = false; }
			$registry[$hash] = $record;
		}
	}
	return $registry;
}

function plus_jr_account($id, $level, $active)
{
	if ((int) $id <= 0) { return 'missing-account'; }
	if ((int) $active !== 1) { return 'inactive-account'; }
	if ((int) $level === 1) { return 'full-administrator'; }
	return in_array((int) $level, array(0, 2), true) ? 'active' : 'unknown-account-level';
}

function plus_jr_target_user($id)
{
	return (int) $id > 0 ? phpbb_user_id((int) $id) : 0;
}

function plus_jr_preserve($text)
{
	if (strlen((string) $text) > 12582909) { throw new \RuntimeException('A Junior Admin source field exceeds the destination MEDIUMTEXT capacity.'); }
	return base64_encode((string) $text);
}

function plus_jr_plan($id, $level, $active, $raw)
{
	global $db;
	static $previous = null, $plan = null, $registry = null, $available = null;
	$key = array($id, $level, $active, $raw);
	if ($previous === $key) { return $plan; }
	$previous = $key;
	$account = plus_jr_account($id, $level, $active);
	$rights = $report = array();
	if ($raw !== '')
	{
		if ($registry === null) { $registry = plus_jr_registry(); }
		if ($available === null)
		{
			$result = $db->sql_query('SELECT auth_option FROM ' . ACL_OPTIONS_TABLE . ' WHERE is_global=1 AND ' .
				$db->sql_in_set('auth_option', array_keys(junior_admin::targets())));
			$available = array_flip(array_column($db->sql_fetchrowset($result), 'auth_option'));
			$db->sql_freeresult($result);
		}
		if (strlen($raw) > 1048576 || substr_count($raw, '|') >= 4096)
		{
			$report[] = array('result' => 'oversized-list');
		}
		else
		{
			foreach (explode('|', $raw) as $token)
			{
				if (!preg_match('/^[a-f0-9]{32}$/D', $token)) { $report[] = array('result' => 'invalid-hash'); continue; }
				$entry = $registry[$token] ?? null;
				$entry = is_array($entry) ? $entry : array();
				$options = $entry['options'] ?? (isset($entry['option']) ? array($entry['option']) : array());
				if ($registry === false) { $reason = 'source-registry-unavailable'; }
				elseif (!array_key_exists($token, $registry)) { $reason = 'unresolved-module'; }
				elseif ($registry[$token] === false) { $reason = 'ambiguous-module'; }
				elseif (isset($entry['customised'])) { $reason = 'modified-source-module'; }
				elseif (!$options) { $reason = 'manual-review'; }
				elseif ($account !== 'active') { $reason = $account; }
				elseif (array_diff_key(array_flip($options), $available)) { $reason = 'target-option-unavailable'; }
				elseif (count(array_filter($options, array(junior_admin::class, 'supported_option'))) !== count($options)) { $reason = 'target-module-too-broad'; }
				else
				{
					$reason = 'delegated';
					foreach ($options as $option) { $rights[$option] = true; }
				}
				$report[] = array('hash' => $token, 'result' => $reason) + $entry;
			}
		}
	}
	$plan = array(json_encode($report, JSON_INVALID_UTF8_SUBSTITUTE), json_encode(array_keys($rights)));
	return $plan;
}

function plus_jr_report($id, $level, $active, $raw) { return plus_jr_plan($id, $level, $active, $raw)[0]; }
function plus_jr_rights($id, $level, $active, $raw) { return plus_jr_plan($id, $level, $active, $raw)[1]; }

function plus_jr_schema($prefix, $truncate)
{
	$table = $prefix . 'plus_jr_records';
	$account = array('users.user_id AS jr_owner_id', 'users.user_level', 'users.user_active');
	$input = array_merge($account, array('jr_admin_users.user_jr_admin'));
	return array('target' => $table, 'primary' => 'jr_admin_users.user_id',
		'query_first' => array('target', $truncate . $table),
		'left_join' => 'jr_admin_users LEFT JOIN users ON users.user_id=jr_admin_users.user_id',
		array('source_user', 'jr_admin_users.user_id', 'intval'), array('user_id', $account[0], 'plus_jr_target_user'),
		array('account_state', $account, 'plus_jr_account'), array('modules_b64', 'jr_admin_users.user_jr_admin', 'plus_jr_preserve'),
		array('notes_b64', 'jr_admin_users.admin_notes', 'plus_jr_preserve'), array('notes_view', 'jr_admin_users.notes_view', 'intval'),
		array('start_date', 'jr_admin_users.start_date', ''), array('update_date', 'jr_admin_users.update_date', ''),
		array('report_json', $input, 'plus_jr_report'), array('rights_json', $input, 'plus_jr_rights'));
}

function plus_jr_finish()
{
	global $db, $table_prefix;
	\phpbb2plus\community\auth\delegations::import($db, $table_prefix);
}
