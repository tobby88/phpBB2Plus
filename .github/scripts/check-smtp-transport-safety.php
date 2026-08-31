<?php

function smtp_transport_assert($condition, $message)
{
	if (!$condition)
	{
		fwrite(STDERR, "SMTP transport safety test failed: $message\n");
		exit(1);
	}
}

$root = dirname(dirname(__DIR__));
require_once $root . '/phpBB2/includes/smtp.php';
$source = file_get_contents($root . '/phpBB2/includes/smtp.php');

smtp_transport_assert(smtp_envelope_address("User <user@example.org>\r\nBcc: victim@example.org") === 'user@example.org', 'envelope parsing must remove line breaks and ignore injected headers');
smtp_transport_assert(smtp_envelope_address('not an address') === '', 'invalid envelope recipients must be rejected');
smtp_transport_assert(smtp_dot_stuff("first\n.\n..last") === "first\r\n..\r\n...last", 'every leading data dot must be escaped');
smtp_transport_assert(strpos($source, 'stream_set_timeout($socket, 20)') !== false, 'SMTP reads need a finite timeout');
smtp_transport_assert(strpos($source, '$deadline = microtime(true) + 20') !== false, 'multiline responses need one total deadline');
smtp_transport_assert(strpos($source, "!empty(\$metadata['timed_out'])") !== false, 'read failures must distinguish timeouts');
smtp_transport_assert(strpos($source, 'ENT_QUOTES | ENT_SUBSTITUTE') !== false, 'remote SMTP responses must be escaped before HTML output');
smtp_transport_assert(strpos($source, 'smtp_dot_stuff($data)') !== false, 'the complete DATA payload must be dot-stuffed');
smtp_transport_assert(strpos($source, 'MAIL FROM: <" . $board_config') === false, 'the configured sender must not be interpolated directly into SMTP commands');

echo "SMTP transport safety tests passed.\n";
