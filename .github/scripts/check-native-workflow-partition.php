<?php
// Keep every native suite assigned to exactly one of the isolated CI parts.
function native_workflow_partition($source)
{
	if (strpos($source,"        php: ['5.6', '7.4', '8.5']") === false || strpos($source,'        part: [1, 2, 3, 4, 5, 6]') === false || strpos($source,'name: migration (${{ matrix.php }}, part ${{ matrix.part }})') === false) { throw new RuntimeException('Native CI must cover all three PHP versions and six parts'); }
	$matrix = strpos($source,"  migration:\n");
	if ($matrix === false || !preg_match('/^  [A-Za-z0-9_-]+:/m',$source,$next,PREG_OFFSET_CAPTURE,$matrix+strlen("  migration:\n"))) { throw new RuntimeException('Matrix and reviewed MySQL job required'); }
	$end = $next[0][1]; $extra = substr($source,$end); $matrix_source = substr($source,$matrix,$end-$matrix);
	if (preg_match_all('/^  [A-Za-z0-9_-]+:/m',$extra) !== 1 || strpos($extra,"  pm-download-mysql:\n") !== 0
		|| strpos($extra,'image: mysql:8.4') === false || strpos($extra,"php-version: '8.5'") === false
		|| strpos($extra,"PHPBB_PM_DOWNLOAD_MYSQL: '8.4'") === false || strpos($extra,"PHPBB_PM_NOTIFY_NATIVE: '1'") === false
		|| preg_match('/^\s+if:/m',$extra) || preg_match_all('/^      - uses:/m',$extra) !== 2
		|| preg_match_all('/^        run:/m',$extra) !== 25
		|| preg_match('/^        run: php -d disable_functions=mail \.github\/scripts\/check-pm-download-native\.php$/m',$extra) !== 1
		|| preg_match('/^        run: php -d disable_functions=mail \.github\/scripts\/check-post-download-native\.php$/m',$extra) !== 1
		|| preg_match('/^        run: php -d disable_functions=mail \.github\/scripts\/check-link-registration-native\.php$/m',$extra) !== 1
		|| strpos($extra,"PHPBB_LINK_NATIVE: '1'") === false
		|| preg_match('/^        run: php -d disable_functions=mail \.github\/scripts\/check-link-admin-native\.php$/m',$extra) !== 1
		|| strpos($extra,"PHPBB_LINK_ADMIN_NATIVE: '1'") === false
		|| preg_match('/^        run: php -d disable_functions=mail \.github\/scripts\/check-link-settings-native\.php$/m',$extra) !== 1
		|| strpos($extra,"PHPBB_LINK_SETTINGS_NATIVE: '1'") === false
		|| preg_match('/^        run: php -d disable_functions=mail \.github\/scripts\/check-link-click-native\.php$/m',$extra) !== 1
		|| strpos($extra,"PHPBB_LINK_CLICK_NATIVE: '1'") === false
		|| preg_match('/^        run: php -d disable_functions=mail \.github\/scripts\/check-link-list-native\.php$/m',$extra) !== 1
		|| strpos($extra,"PHPBB_LINK_LIST_NATIVE: '1'") === false
		|| preg_match('/^        run: php -d disable_functions=mail \.github\/scripts\/check-link-rotation-native\.php$/m',$extra) !== 1
		|| strpos($extra,"PHPBB_LINK_ROTATION_NATIVE: '1'") === false
		|| preg_match('/^        run: php -d disable_functions=mail \\.github\\/scripts\\/check-ctracker-rate-storage\\.php$/m',$extra) !== 1
		|| strpos($extra,"PHPBB_RATE_NATIVE: '1'") === false
		|| preg_match('/^        run: php -d disable_functions=mail \\.github\\/scripts\\/check-kb-fixture-seeds-native\\.php$/m',$extra) !== 1
		|| strpos($extra,"PHPBB_KB_COUNTER_NATIVE: '1'") === false
		|| preg_match('/^        run: php -d disable_functions=mail \\.github\\/scripts\\/check-owned-connection-native\\.php$/m',$extra) !== 1
		|| strpos($extra,"PHPBB_OWNED_NATIVE: '1'") === false
		|| preg_match('/^        run: php -d disable_functions=mail \\.github\\/scripts\\/check-absence-native\\.php$/m',$extra) !== 1
		|| strpos($extra,"PHPBB_ABSENCE_NATIVE: '1'") === false
		|| preg_match('/^        run: php -d disable_functions=mail \\.github\\/scripts\\/check-uacp-sorting-native\\.php$/m',$extra) !== 1
		|| strpos($extra,"PHPBB_UACP_NATIVE: '1'") === false
		|| strpos($extra,"PHPBB_UACP_MYSQL: '8.4'") === false
		|| preg_match('/^        run: php -d disable_functions=mail \\.github\\/scripts\\/check-profile-options-native\\.php$/m',$extra) !== 1
		|| strpos($extra,"PHPBB_PROFILE_OPTIONS_NATIVE: '1'") === false
		|| strpos($extra,"PHPBB_PROFILE_OPTIONS_MYSQL: '8.4'") === false
		|| preg_match('/^        run: php -d disable_functions=mail \\.github\\/scripts\\/check-uacp-delete-native\\.php$/m',$extra) !== 1
		|| strpos($extra,"PHPBB_UACP_DELETE_NATIVE: '1'") === false
		|| strpos($extra,"PHPBB_UACP_DELETE_MYSQL: '8.4'") === false
		|| preg_match('/^        run: php -d disable_functions=mail \\.github\\/scripts\\/check-uacp-list-native\\.php$/m',$extra) !== 1
		|| strpos($extra,"PHPBB_UACP_LIST_NATIVE: '1'") === false
		|| strpos($extra,"PHPBB_UACP_LIST_MYSQL: '8.4'") === false
		|| preg_match('/^        run: php -d disable_functions=mail \\.github\\/scripts\\/check-attachment-admin-list-native\\.php$/m',$extra) !== 1
		|| strpos($extra,"PHPBB_ATTACH_ADMIN_LIST_NATIVE: '1'") === false
		|| strpos($extra,"PHPBB_ATTACH_ADMIN_LIST_MYSQL: '8.4'") === false
		|| preg_match('/^        run: php -d disable_functions=mail \\.github\\/scripts\\/check-attachment-admin-rows-native\\.php$/m',$extra) !== 1
		|| strpos($extra,"PHPBB_ATTACH_ADMIN_ROWS_NATIVE: '1'") === false
		|| strpos($extra,"PHPBB_ATTACH_ADMIN_ROWS_MYSQL: '8.4'") === false
		|| preg_match('/^        run: php -d disable_functions=mail \\.github\\/scripts\\/check-attachment-admin-rows-native\\.php --pages$/m',$extra) !== 1
		|| preg_match('/^        run: php -d disable_functions=mail \\.github\\/scripts\\/check-attachment-admin-metadata-native\\.php$/m',$extra) !== 1
		|| strpos($extra,"PHPBB_ATTACH_METADATA_NATIVE: '1'") === false
		|| strpos($extra,"PHPBB_ATTACH_METADATA_MYSQL: '8.4'") === false
		|| preg_match('/^        run: php -d disable_functions=mail \\.github\\/scripts\\/check-attachment-admin-delete-native\\.php$/m',$extra) !== 1
		|| strpos($extra,"PHPBB_ATTACH_ADMIN_DELETE_NATIVE: '1'") === false
		|| strpos($extra,"PHPBB_ATTACH_ADMIN_DELETE_MYSQL: '8.4'") === false
		|| preg_match('/^        run: php -d disable_functions=mail \\.github\\/scripts\\/check-attachment-delete-selection\\.php$/m',$extra) !== 1
		|| strpos($extra,"PHPBB_ATTACH_DELETE_SELECTION_NATIVE: '1'") === false
		|| strpos($extra,"PHPBB_ATTACH_DELETE_SELECTION_MYSQL: '8.4'") === false
		|| preg_match('/^        run: php -d disable_functions=mail \\.github\\/scripts\\/check-attachment-shadow\\.php$/m',$extra) !== 1
		|| strpos($extra,"PHPBB_ATTACH_SHADOW_NATIVE: '1'") === false
		|| strpos($extra,"PHPBB_ATTACH_SHADOW_MYSQL: '8.4'") === false
		|| preg_match('/^        run: php -d disable_functions=mail \\.github\\/scripts\\/check-poll-vote-native\\.php$/m',$extra) !== 1
		|| strpos($extra,"PHPBB_VOTE_MYSQL: '8.4'") === false
		|| preg_match('/^        run: php -d disable_functions=mail \\.github\\/scripts\\/check-attachment-search-native\\.php$/m',$extra) !== 1
		|| strpos($extra,"PHPBB_ATTACH_SEARCH_NATIVE: '1'") === false
		|| strpos($extra,"PHPBB_ATTACH_SEARCH_MYSQL: '8.4'") === false)
	{ throw new RuntimeException('Only the unconditional reviewed MySQL suites may run outside the matrix'); }
	$start = strpos($matrix_source,'    steps:'); if ($start === false) { throw new RuntimeException('Native CI steps missing'); }
	$parts = array(1=>0,2=>0,3=>0,4=>0,5=>0,6=>0); $setup = 0; $database_checks = 0;
	foreach (preg_split('/(?=^      - )/m',substr($matrix_source,$start)) as $step)
	{
		if (strpos($step,'      - ') !== 0) { continue; }
		if (strpos($step,'      - uses:') === 0) { if (preg_match('/^        if:/m',$step)) { throw new RuntimeException('Checkout/PHP setup must run in every isolated part'); } $setup++; continue; }
		if (strpos($step,'      - name: Verify disposable database filesystem and normal commit flushing') === 0)
		{
			if (preg_match('/^        if:/m',$step) || strpos($step,'stat -f -c %T /var/lib/mysql') === false || strpos($step,'innodb_flush_log_at_trx_commit') === false || strpos($step,'php .github/') !== false) { throw new RuntimeException('Database baseline must run everywhere without hiding feature suites'); }
			$database_checks++; continue;
		}
		if (!preg_match('/^        run:/m',$step) || preg_match_all('/^(?:      - |        )if: matrix\.part == ([1-6])$/m',$step,$matches) !== 1) { throw new RuntimeException('Every native suite must run in exactly one existing part'); }
		$parts[(int)$matches[1][0]]++;
	}
	if ($setup !== 2 || $database_checks !== 1 || min($parts) < 1) { throw new RuntimeException('Every native part requires setup, database baseline and at least one suite'); }
	return $parts;
}
$source = file_get_contents(dirname(__DIR__).'/workflows/innodb-migration.yml'); $parts = native_workflow_partition($source);
$variants = array(preg_replace('/^(?:      - |        )if: matrix\.part == [1-6]\n/m','',$source,1),preg_replace('/if: matrix\.part == [1-6]/','if: matrix.part == 7',$source,1),str_replace('part: [1, 2, 3, 4, 5, 6]','part: [1, 2, 3]',$source),str_replace('stat -f -c %T /var/lib/mysql','unverified filesystem',$source),str_replace('normal commit flushing'."\n",'normal commit flushing'."\n        if: matrix.part == 1\n",$source),str_replace('df -h /var/lib/mysql','df -h /var/lib/mysql'."\n          php .github/scripts/check-misplaced-native.php",$source));
$variants[]=str_replace('image: mysql:8.4','image: mariadb:10.11',$source);
$variants[]=str_replace("PHPBB_PM_DOWNLOAD_MYSQL: '8.4'","PHPBB_PM_DOWNLOAD_MYSQL: 'skip'",$source);
$variants[]=preg_replace('/^  pm-download-mysql:[\s\S]*$/m','',$source);
$variants[]=$source."\n  hidden-native:\n    steps:\n      - run: php .github/scripts/check-unassigned-native.php\n";
foreach ($variants as $variant) { $denied = false; try { native_workflow_partition($variant); } catch (RuntimeException $error) { $denied = true; } if (!$denied) { throw new RuntimeException('Malformed/incomplete native CI partition accepted'); } }
echo 'Native CI partitions: ',array_sum($parts),' suites across 3 PHP versions x 6 isolated parts; missing/invalid assignments refused.',"\n";
