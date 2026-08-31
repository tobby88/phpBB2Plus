<?php

function pafiledb_template_assert($condition, $message)
{
	if (!$condition)
	{
		fwrite(STDERR, "paFileDB template safety test failed: $message\n");
		exit(1);
	}
}

$root = dirname(dirname(__DIR__));
$source = file_get_contents($root . '/phpBB2/pafiledb/includes/template.php');

pafiledb_template_assert(strpos($source, "preg_match('/^[A-Za-z0-9_-]{1,64}$/D', \$template)") !== false, 'style directory names need a strict allowlist');
pafiledb_template_assert(strpos($source, "preg_match('#^(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_.-]+\\.tpl$#D', \$filename)") !== false, 'template filenames must remain relative tpl paths');
pafiledb_template_assert(strpos($source, "strpos('/' . \$filename . '/', '/../')") !== false, 'parent traversal must be rejected');
pafiledb_template_assert(strpos($source, "str_replace('/', '_', \$this->filename[\$handle])") !== false, 'cache keys must not recreate attacker-selected directories');
pafiledb_template_assert(strpos($source, "tempnam(\$this->cachedir, '.pa-tpl-')") !== false, 'compiled templates must be staged in the cache directory');
pafiledb_template_assert(strpos($source, 'file_put_contents($temp, $data, LOCK_EX)') !== false, 'compiled templates need a complete locked write');
pafiledb_template_assert(strpos($source, 'rename($temp, $filename)') !== false, 'compiled templates must be published atomically');
pafiledb_template_assert(strpos($source, 'mkdir($this->cachedir, 0777') === false, 'cache directories must not be created world-writable');
pafiledb_template_assert(strpos($source, "preg_replace('#<!--\\s*PHP") !== false, 'legacy template PHP blocks must remain disabled');

echo "paFileDB template safety tests passed.\n";
