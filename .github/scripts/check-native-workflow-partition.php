<?php
// Keep every native suite assigned to exactly one of the isolated CI parts.
function native_workflow_partition($source)
{
	if (strpos($source,"        php: ['5.6', '7.4', '8.5']") === false || strpos($source,'        part: [1, 2, 3, 4]') === false || strpos($source,'name: migration (${{ matrix.php }}, part ${{ matrix.part }})') === false) { throw new RuntimeException('Native CI must cover all three PHP versions and four parts'); }
	$start = strpos($source,'    steps:'); if ($start === false) { throw new RuntimeException('Native CI steps missing'); }
	$parts = array(1=>0,2=>0,3=>0,4=>0); $setup = 0; $database_checks = 0;
	foreach (preg_split('/(?=^      - )/m',substr($source,$start)) as $step)
	{
		if (strpos($step,'      - ') !== 0) { continue; }
		if (strpos($step,'      - uses:') === 0) { if (preg_match('/^        if:/m',$step)) { throw new RuntimeException('Checkout/PHP setup must run in every isolated part'); } $setup++; continue; }
		if (strpos($step,'      - name: Verify disposable database filesystem and normal commit flushing') === 0)
		{
			if (preg_match('/^        if:/m',$step) || strpos($step,'stat -f -c %T /var/lib/mysql') === false || strpos($step,'innodb_flush_log_at_trx_commit') === false || strpos($step,'php .github/') !== false) { throw new RuntimeException('Database baseline must run everywhere without hiding feature suites'); }
			$database_checks++; continue;
		}
		if (!preg_match('/^        run:/m',$step) || preg_match_all('/^(?:      - |        )if: matrix\.part == ([1-4])$/m',$step,$matches) !== 1) { throw new RuntimeException('Every native suite must run in exactly one existing part'); }
		$parts[(int)$matches[1][0]]++;
	}
	if ($setup !== 2 || $database_checks !== 1 || min($parts) < 1) { throw new RuntimeException('Every native part requires setup, database baseline and at least one suite'); }
	return $parts;
}
$source = file_get_contents(dirname(__DIR__).'/workflows/innodb-migration.yml'); $parts = native_workflow_partition($source);
$variants = array(preg_replace('/^(?:      - |        )if: matrix\.part == [1-4]\n/m','',$source,1),preg_replace('/if: matrix\.part == [1-4]/','if: matrix.part == 5',$source,1),str_replace('part: [1, 2, 3, 4]','part: [1, 2, 3]',$source),str_replace('stat -f -c %T /var/lib/mysql','unverified filesystem',$source),str_replace('normal commit flushing'."\n",'normal commit flushing'."\n        if: matrix.part == 1\n",$source),str_replace('df -h /var/lib/mysql','df -h /var/lib/mysql'."\n          php .github/scripts/check-misplaced-native.php",$source));
foreach ($variants as $variant) { $denied = false; try { native_workflow_partition($variant); } catch (RuntimeException $error) { $denied = true; } if (!$denied) { throw new RuntimeException('Malformed/incomplete native CI partition accepted'); } }
echo 'Native CI partitions: ',array_sum($parts),' suites across 3 PHP versions x 4 isolated parts; missing/invalid assignments refused.',"\n";
