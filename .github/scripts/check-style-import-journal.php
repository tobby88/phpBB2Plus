<?php
define('IN_PHPBB', true);
$source = dirname(dirname(__DIR__)) . '/phpBB2/';
require $source . 'includes/php_compat.php';
require $source . 'includes/functions_acl_storage.php';
require $source . 'includes/functions_style_import_files.php';
require $source . 'includes/functions_style_import_journal.php';

class SijInterruptedFiles extends PhpbbStyleImportLocal
{
	function put($path, $contents, $guard, $tag = null)
	{
		parent::put($path, $contents, $guard, $tag);
		// Exit is intentionally not an exception: neither caller catch nor its
		// finally runs. Parent must reopen from persisted bytes in a new process.
		exit(73);
	}
}
class SijStagedFiles extends PhpbbStyleImportLocal
{
	function put($path, $contents, $guard, $tag = null)
	{
		$boundary = function () use ($path, $tag, $guard) {
			call_user_func($guard);
			if ($this->kind($this->stage_name($path, $tag)) === 'file') { exit(73); }
		};
		parent::put($path, $contents, $boundary, $tag);
	}
}
if (isset($argv[1]) && in_array($argv[1], array('--interrupted-child', '--staged-child'), true))
{
	$journal = PhpbbStyleImportJournal::reopen($argv[2], $argv[3], $argv[4], 'local-fixture');
	$files = $argv[1] === '--staged-child' ? new SijStagedFiles($argv[5]) : new SijInterruptedFiles($argv[5]);
	$journal->apply($files, function () {});
	exit(74);
}
$checks = 0;
function sij_check($ok, $message) { $GLOBALS['checks']++; if (!$ok) { throw new RuntimeException($message); } }
function sij_denied($callback, $message)
{
	$denied = false; try { $callback(); } catch (PhpbbAclException $error) { $denied = true; }
	sij_check($denied, $message);
}
function sij_clean($path, $root)
{
	// Only this test's random temporary directory; never traverse links.
	if ($path !== $root && strpos($path, $root . DIRECTORY_SEPARATOR) !== 0) { throw new RuntimeException('Fixture cleanup escaped root'); }
	if (is_link($path) || is_file($path)) { unlink($path); return; }
	foreach (scandir($path) as $name) { if ($name !== '.' && $name !== '..') { sij_clean($path . DIRECTORY_SEPARATOR . $name, $root); } }
	rmdir($path);
}
$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'codex_style_journal_' . bin2hex(phpbb_random_bytes(8));
mkdir($root, 0700); mkdir($root . '/private', 0700); mkdir($root . '/templates', 0700); mkdir($root . '/templates/fixture', 0700);
try
{
	$base = $root . '/private'; $target = $root . '/templates'; $guard = function () {};
	$files = new PhpbbStyleImportLocal($target);
	$old = "Old Grüße\0"; $new = "New Grüße\0";
	file_put_contents($target . '/fixture/first.tpl', $old);
	file_put_contents($target . '/fixture/last.tpl', 'last-old');
	$desired = array('fixture/first.tpl'=>$new, 'fixture/nested/empty.tpl'=>'', 'fixture/last.tpl'=>'last-new');
	$journal = PhpbbStyleImportJournal::prepare($files, $base, 'local-fixture', $desired, $guard);
	$id = $journal->id(); $seal = $journal->seal();
	sij_check(file_get_contents($target . '/fixture/first.tpl') === $old && !file_exists($target . '/fixture/nested'), 'Preparation has no destination effects');
	sij_check(strlen($id) === 32 && strlen($seal) === 64, 'Opaque operation ID and content seal');
	sij_check($files->read('fixture/first.tpl', strlen($old)) === $old, 'Bounded binary read');
	sij_denied(function () use ($files, $old) { $files->read('fixture/first.tpl', strlen($old) - 1); }, 'Over-limit read refused');

	$command = escapeshellarg(PHP_BINARY) . ' -n ' . escapeshellarg(__FILE__) . ' --interrupted-child '
		. escapeshellarg($base) . ' ' . escapeshellarg($id) . ' ' . escapeshellarg($seal) . ' ' . escapeshellarg($target);
	$process = proc_open($command, array(0=>array('pipe','r'), 1=>array('pipe','w'), 2=>array('pipe','w')), $pipes, null, null, array('bypass_shell'=>true));
	sij_check(is_resource($process), 'Real interrupted subprocess started'); fclose($pipes[0]);
	$output = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
	sij_check(proc_close($process) === 73 && $output === '' && $errors === '', 'Child exits after first publication without caller cleanup: ' . $output . $errors);
	sij_check(file_get_contents($target . '/fixture/first.tpl') === $new && !file_exists($target . '/fixture/nested/empty.tpl')
		&& file_get_contents($target . '/fixture/last.tpl') === 'last-old', 'Actual partial publication reproduced');
	unset($journal);
	$journal = PhpbbStyleImportJournal::reopen($base, $id, $seal, 'local-fixture');
	$journal->apply($files, $guard, true);
	sij_check(file_get_contents($target . '/fixture/first.tpl') === $old && !file_exists($target . '/fixture/nested/empty.tpl'), 'Fresh-process rollback restores originals');
	$journal->apply($files, $guard, true);
	sij_check(file_get_contents($target . '/fixture/last.tpl') === 'last-old', 'Rollback retry is idempotent');
	$journal->apply($files, $guard);
	sij_check(file_get_contents($target . '/fixture/first.tpl') === $new && file_get_contents($target . '/fixture/nested/empty.tpl') === ''
		&& file_get_contents($target . '/fixture/last.tpl') === 'last-new', 'Resume publishes every desired byte including empty file');
	$journal->apply($files, $guard);
	sij_check(file_get_contents($target . '/fixture/first.tpl') === $new, 'Completed file publication retry is idempotent');
	$journal->apply($files, $guard, true);
	sij_check(!file_exists($target . '/fixture/nested/empty.tpl') && is_dir($target . '/fixture/nested'), 'Rollback removes own new bytes but preserves directory without ownership proof');
	$staged_command = str_replace('--interrupted-child', '--staged-child', $command);
	$process = proc_open($staged_command, array(0=>array('pipe','r'), 1=>array('pipe','w'), 2=>array('pipe','w')), $pipes, null, null, array('bypass_shell'=>true));
	sij_check(is_resource($process), 'Staging interruption subprocess started'); fclose($pipes[0]);
	$output = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
	sij_check(proc_close($process) === 73 && $output === '' && $errors === '', 'Real exit between staging and rename: ' . $output . $errors);
	$stage = $files->path($files->stage_name('fixture/first.tpl', $journal->stage_tag(0, 'new')));
	sij_check(is_file($stage) && file_get_contents($target . '/fixture/first.tpl') === $old, 'Process interruption leaves known stage and original target');
	file_put_contents($stage, substr($new, 0, 3)); // Also exercise a short interrupted write.
	$empty_stage = $files->path($files->stage_name('fixture/nested/empty.tpl', $journal->stage_tag(1, 'new')));
	file_put_contents($empty_stage, '');
	$journal = PhpbbStyleImportJournal::reopen($base, $id, $seal, 'local-fixture');
	$journal->apply($files, $guard);
	sij_check(!file_exists($stage) && file_get_contents($target . '/fixture/first.tpl') === $new, 'Resume replaces and cleans owned partial stage');
	sij_check(!file_exists($empty_stage) && file_get_contents($target . '/fixture/nested/empty.tpl') === '', 'Empty stages and payloads recover on PHP 5.6 too');
	$journal->apply($files, $guard, true);
	file_put_contents($stage, 'foreign');
	sij_denied(function () use ($journal, $files, $guard) { $journal->apply($files, $guard); }, 'Foreign stage bytes block recovery');
	sij_check(file_get_contents($stage) === 'foreign' && file_get_contents($target . '/fixture/first.tpl') === $old, 'Foreign stage not removed and target unchanged');
	unlink($stage);
	$changed = false;
	$changing_guard = function () use (&$changed, $stage, $target) {
		if (!$changed && is_file($stage)) { $changed = true; file_put_contents($target . '/fixture/first.tpl', 'changed-during-publication'); }
	};
	sij_denied(function () use ($journal, $files, $changing_guard) { $journal->apply($files, $changing_guard); }, 'Foreign change at final rename boundary refused');
	sij_check($changed && file_get_contents($target . '/fixture/first.tpl') === 'changed-during-publication' && !file_exists($stage), 'Boundary guard preserves foreign target and removes own staging file');
	file_put_contents($target . '/fixture/first.tpl', $old);
	mkdir($root . '/other-templates', 0700);
	sij_denied(function () use ($journal, $root, $guard) { $journal->apply(new PhpbbStyleImportLocal($root . '/other-templates'), $guard); }, 'Transport root is bound to manifest independently of caller label');
	sij_check(count(scandir($root . '/other-templates')) === 2, 'Wrong target remains empty');

	sij_denied(function () use ($base, $id, $seal) { PhpbbStyleImportJournal::reopen($base, $id, $seal, 'other-target'); }, 'Different target refused');
	sij_denied(function () use ($base, $id) { PhpbbStyleImportJournal::reopen($base, $id, str_repeat('0',64), 'local-fixture'); }, 'Wrong manifest seal refused');
	sij_denied(function () use ($base, $seal) { PhpbbStyleImportJournal::reopen($base, '../elsewhere', $seal, 'local-fixture'); }, 'Operation path traversal refused');
	file_put_contents($target . '/fixture/last.tpl', 'foreign-change');
	sij_denied(function () use ($journal, $files, $guard) { $journal->apply($files, $guard); }, 'Late foreign destination fails whole-batch preflight');
	sij_check(file_get_contents($target . '/fixture/first.tpl') === $old && file_get_contents($target . '/fixture/last.tpl') === 'foreign-change', 'No earlier writes when later target diverged');
	sij_denied(function () use ($journal, $files, $guard) { $journal->apply($files, $guard, true); }, 'Rollback also refuses foreign bytes');
	file_put_contents($target . '/fixture/last.tpl', 'last-old');
	$backup = $journal->directory . DIRECTORY_SEPARATOR . '0002-new.backup.php';
	$saved = file_get_contents($backup); file_put_contents($backup, 'corrupt');
	sij_denied(function () use ($journal, $files, $guard) { $journal->apply($files, $guard); }, 'Late corrupt payload caught before destination effects');
	sij_check(file_get_contents($target . '/fixture/first.tpl') === $old, 'Payload corruption does not partially republish');
	sij_denied(function () use ($base, $id, $seal) { PhpbbStyleImportJournal::reopen($base, $id, $seal, 'local-fixture'); }, 'Reopen validates all payloads');
	file_put_contents($backup, $saved);
	$manifest_path = $journal->directory . DIRECTORY_SEPARATOR . 'manifest.backup.php';
	$saved_manifest = file_get_contents($manifest_path); file_put_contents($manifest_path, $saved_manifest . ' ');
	sij_denied(function () use ($base, $id, $seal) { PhpbbStyleImportJournal::reopen($base, $id, $seal, 'local-fixture'); }, 'Persisted manifest modification refused');
	file_put_contents($manifest_path, $saved_manifest);
	sij_denied(function () use ($journal, $files) { $journal->apply($files, function () { phpbb_acl_error('revoked'); }); }, 'Revoked actor has no recovery authority');
	sij_check(file_get_contents($target . '/fixture/first.tpl') === $old, 'Revocation preserves originals');

	foreach (array('fixture/../escape.tpl', 'fixture/bad:stream', 'fixture/CON.tpl', 'fixture/trailing. /x.tpl', '/absolute.tpl', 'single.tpl', 'fixture/run.php', 'fixture/.htaccess', 'fixture/.user.ini', 'fixture/xs_config.cfg', '.hidden/file.tpl') as $bad)
	{
		sij_denied(function () use ($files, $base, $guard, $bad) { PhpbbStyleImportJournal::prepare($files, $base, 'local-fixture', array($bad=>'bad'), $guard); }, 'Unsafe path refused: ' . $bad);
	}
	sij_denied(function () use ($files, $base, $guard) { PhpbbStyleImportJournal::prepare($files, $base, 'local-fixture', array('fixture/a.tpl'=>'a','other/b.tpl'=>'b'), $guard); }, 'Cross-template job refused');
	sij_denied(function () use ($files, $base, $guard) { PhpbbStyleImportJournal::prepare($files, $base, 'local-fixture', array('fixture/a.tpl'=>'a','fixture/A.tpl'=>'b'), $guard); }, 'Case alias refused');
	sij_denied(function () use ($files, $base, $guard) { PhpbbStyleImportJournal::prepare($files, $base, 'local-fixture', array('fixture/a'=>'a','fixture/a/b.tpl'=>'b'), $guard); }, 'File-directory collision refused');
	sij_denied(function () use ($files, $base, $guard) { PhpbbStyleImportJournal::prepare($files, $base, 'local-fixture', array('fixture/xs_import_fake.tmp'=>'a'), $guard); }, 'Staging namespace cannot be imported');
	sij_check(count(glob($base . '/xs-import-*.backup')) === 1, 'Rejected preparations do not accumulate journals');
	$link = $journal->directory . DIRECTORY_SEPARATOR . '0002-new.backup.php'; unlink($link);
	if (@symlink($target . '/fixture/last.tpl', $link))
	{
		sij_denied(function () use ($journal, $files, $guard) { $journal->apply($files, $guard); }, 'Linked backup payload refused');
		unlink($link);
	}
	file_put_contents($link, $saved);
	$journal->apply($files, $guard);
	file_put_contents($target . '/fixture/nested/unrelated.txt', 'keep');
	$journal->apply($files, $guard, true);
	sij_check(file_get_contents($target . '/fixture/nested/unrelated.txt') === 'keep', 'Recovery does not delete unrelated directory contents');
	$guarded = PhpbbStyleImportJournal::prepare($files, $base, 'local-fixture', array('fixture/guarded.tpl'=>"<?php echo 'MUST_NOT_EXECUTE'; ?>"), $guard, array('selection'=>'preserved'), array('fixture/empty-directory'));
	$guarded = PhpbbStyleImportJournal::reopen($base, $guarded->id(), $guarded->seal(), 'local-fixture');
	sij_check($guarded->context() === array('selection'=>'preserved'), 'Selected job metadata survives sealed reopen');
	$guarded->apply($files, $guard); sij_check(is_dir($target . '/fixture/empty-directory'), 'Explicit empty archive directories preserved');
	$command = escapeshellarg(PHP_BINARY) . ' -n ' . escapeshellarg($guarded->directory . DIRECTORY_SEPARATOR . '0000-new.backup.php');
	$process = proc_open($command, array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')), $pipes, null, null, array('bypass_shell'=>true));
	sij_check(is_resource($process), 'Backup execution probe started'); fclose($pipes[0]);
	$output = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
	sij_check(proc_close($process) === 0 && $output === '' && $errors === '', 'Backup envelope emits no payload and never executes embedded PHP');
	echo 'Style import journal: ' . $checks . " assertions; real child-process interruption and filesystem recovery\n";
}
finally { sij_clean($root, $root); }
