<?php
// Keep every native suite assigned to exactly one of the isolated CI parts.
function native_workflow_block_hash($source)
{
	return hash('sha256', rtrim(str_replace("\r\n", "\n", $source), "\n") . "\n");
}
function native_mysql_workflow_partition($source)
{
	$inventory = json_decode(file_get_contents(dirname(__DIR__).'/fixtures/mysql-workflow-inventory.json'), true);
	if (!is_array($inventory) || $inventory['version'] !== 1 || count($inventory['setup']) !== 2 || count($inventory['steps']) !== 32) { throw new RuntimeException('Complete reviewed MySQL inventory required'); }
	$start = strpos($source, "    steps:\n");
	if ($start === false || native_workflow_block_hash(substr($source, 0, $start + strlen("    steps:\n"))) !== $inventory['header_sha256']) { throw new RuntimeException('Reviewed two-part MySQL matrix, service and setup required'); }
	$expected = array(); foreach ($inventory['steps'] as $step) { $expected[$step['name']] = $step; }
	$parts = array(1=>0, 2=>0); $seen = array(); $setup = 0;
	foreach (preg_split('/(?=^      - )/m', substr($source, $start + strlen("    steps:\n"))) as $step)
	{
		if (trim($step) === '') { continue; }
		if (strpos($step, '      - uses:') === 0)
		{
			if ($setup >= 2 || native_workflow_block_hash($step) !== $inventory['setup'][$setup]['sha256']) { throw new RuntimeException('Every MySQL part needs the unchanged checkout/PHP setup'); }
			$setup++; continue;
		}
		if (!preg_match('/^      - name: ([^\r\n]+)/', $step, $name) || !isset($expected[$name[1]]) || isset($seen[$name[1]])
			|| preg_match_all('/^        if: matrix\.part == ([12])$/m', $step, $condition) !== 1) { throw new RuntimeException('Every original MySQL step must have exactly one assigned part'); }
		$part = (int)$condition[1][0]; $original = $expected[$name[1]];
		$body = preg_replace('/^        if: matrix\.part == [12]\n/m', '', $step);
		if ($original['part'] !== $part || native_workflow_block_hash($body) !== $original['sha256']) { throw new RuntimeException('Original MySQL commands, environments and failure behavior must remain byte-exact'); }
		preg_match_all('/^\s*(?:run:\s*)?php\b[^\r\n]+/m', $body, $matches); $commands = array();
		foreach ($matches[0] as $command) { $commands[] = preg_replace('/^run:\s*/', '', trim($command)); }
		if ($commands !== $original['commands']) { throw new RuntimeException('Complete original MySQL command inventory required'); }
		$seen[$name[1]] = true; $parts[$part]++;
	}
	if ($setup !== 2 || count($seen) !== 32 || count($expected) !== 32 || min($parts) < 1) { throw new RuntimeException('Both MySQL parts must retain all32 original suites'); }
	return $parts;
}
function native_workflow_partition($source)
{
	if (strpos($source,"        php: ['5.6', '7.4', '8.5']") === false || strpos($source,'        part: [1, 2, 3, 4, 5, 6]') === false || strpos($source,'name: migration (${{ matrix.php }}, part ${{ matrix.part }})') === false) { throw new RuntimeException('Native CI must cover all three PHP versions and six parts'); }
	$matrix = strpos($source,"  migration:\n");
	if ($matrix === false || !preg_match('/^  [A-Za-z0-9_-]+:/m',$source,$next,PREG_OFFSET_CAPTURE,$matrix+strlen("  migration:\n"))) { throw new RuntimeException('Matrix and reviewed MySQL job required'); }
	$end = $next[0][1]; $extra = substr($source,$end); $matrix_source = substr($source,$matrix,$end-$matrix);
	if (preg_match('/^        run: php -d disable_functions=mail \\.github\\/scripts\\/check-pm-form-read-native\\.php$/m',$matrix_source) !== 1
		|| strpos($matrix_source,"PHPBB_PM_FORM_NATIVE: '1'") === false) { throw new RuntimeException('Actual PM form reads must run in the native PHP matrix'); }
	if (preg_match_all('/^        run: php -d disable_functions=mail \\.github\\/scripts\\/check-pm-view-native\\.php (?:body|mailbox)$/m',$matrix_source) !== 2
		|| strpos($matrix_source,"PHPBB_PM_VIEW_NATIVE: '1'") === false) { throw new RuntimeException('Actual PM body and mailbox reads must run in the native PHP matrix'); }
	if (preg_match_all('/^        run: php -d disable_functions=mail \\.github\\/scripts\\/check-topic-preferences-native\\.php (?:watch|bookmark|controllers)$/m',$matrix_source) !== 3
		|| strpos($matrix_source,"PHPBB_TOPIC_PREF_NATIVE: '1'") === false) { throw new RuntimeException('All current topic preference modes must run in the native PHP matrix'); }
	native_mysql_workflow_partition($extra);

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
$variants[]=str_replace('part: [1, 2]', 'part: [1]', $source);
$variants[]=str_replace('max-parallel: 2', 'max-parallel: 1', $source);
$variants[]=str_replace('fail-fast: false'."\n".'      max-parallel: 2', 'fail-fast: true'."\n".'      max-parallel: 2', $source);
$mysql_start = strpos($source,"  pm-download-mysql:\n"); $mysql_source = substr($source,$mysql_start); $prefix = substr($source,0,$mysql_start);
$variants[]=$prefix.preg_replace('/^        if: matrix\.part == [12]\n/m', '', $mysql_source, 1);
$variants[]=$prefix.preg_replace('/if: matrix\.part == [12]/', 'if: matrix.part == 3', $mysql_source, 1);
$variants[]=$prefix.preg_replace('/if: matrix\.part == 1/', 'if: matrix.part == 2', $mysql_source, 1);
$variants[]=$prefix.preg_replace('/if: matrix\.part == [12]/', 'if: matrix.part == 1 && false', $mysql_source, 1);
$variants[]=$prefix.str_replace('disable_functions=mail', 'disable_functions=', $mysql_source);
$variants[]=$prefix.str_replace("PHPBB_TOPIC_PREF_NATIVE: '1'", "PHPBB_TOPIC_PREF_NATIVE: '0'", $mysql_source);
$variants[]=$prefix.preg_replace('/^        run: /m', "        continue-on-error: true\n        run: ", $mysql_source, 1);
$mysql_steps = strpos($mysql_source,"    steps:\n"); $step_blocks=preg_split('/(?=^      - )/m',substr($mysql_source,$mysql_steps+strlen("    steps:\n")));$test_index=null;foreach($step_blocks as$index=>$step){if(strpos($step,'      - name:')===0){$test_index=$index;break;}}
if($test_index===null){throw new RuntimeException('Original MySQL test required for mutation checks');}
$missing=$step_blocks;unset($missing[$test_index]);$variants[]=$prefix.substr($mysql_source,0,$mysql_steps+strlen("    steps:\n")).implode('',$missing);
$duplicate=$step_blocks;$duplicate[]=$step_blocks[$test_index];$variants[]=$prefix.substr($mysql_source,0,$mysql_steps+strlen("    steps:\n")).implode('',$duplicate);
$variants[]=$source."\n  hidden-native:\n    steps:\n      - run: php .github/scripts/check-unassigned-native.php\n";
foreach ($variants as $variant) { $denied = false; try { native_workflow_partition($variant); } catch (RuntimeException $error) { $denied = true; } if (!$denied) { throw new RuntimeException('Malformed/incomplete native CI partition accepted'); } }
echo 'Native CI partitions: ',array_sum($parts),' suites across 3 PHP versions x 6 isolated parts; missing/invalid assignments refused.',"\n";

$mysql_parts=native_mysql_workflow_partition(substr($source,strpos($source,"  pm-download-mysql:\n")));
echo 'MySQL CI partitions: ',array_sum($mysql_parts),' original suites across 2 isolated parts; unchanged command/env/setup inventory verified.',"\n";
