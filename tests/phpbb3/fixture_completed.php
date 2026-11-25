<?php
// Runs as the auto-append file of a CLI fixture after its last assertion.
if (PHP_SAPI === 'cli')
{
	fwrite(STDERR, "phpBB Plus fixture completed\n");
}
