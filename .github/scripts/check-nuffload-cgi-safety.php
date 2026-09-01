<?php

$root = dirname(dirname(__DIR__));
$source = file_get_contents($root . '/phpBB2/cgi-bin/nuffload.cgi');
$errors = array();

function nuffload_cgi_assert($condition, $message)
{
	global $errors;
	if (!$condition)
	{
		$errors[] = $message;
	}
}

nuffload_cgi_assert(strpos($source, "uc(\$ENV{'REQUEST_METHOD'}) ne 'POST'") !== false, 'CGI uploader must reject non-POST requests');
nuffload_cgi_assert(strpos($source, '^multipart/form-data') !== false, 'CGI uploader must require multipart form data');
nuffload_cgi_assert(strpos($source, 'length($raw_query) > 2048') !== false, 'CGI uploader must bound query-string input');
nuffload_cgi_assert(substr_count($source, 'binmode(STDIN)') >= 2, 'CGI uploader must read both input streams in binary mode');
nuffload_cgi_assert(strpos($source, 'if ($bRead != $len)') !== false, 'CGI uploader must reject truncated request bodies');
nuffload_cgi_assert(strpos($source, '/^(?:pic_file|pic_thumbnail)(?:-[0-9]{1,2})?$/') !== false, 'CGI uploader must restrict file field names');
nuffload_cgi_assert(strpos($source, '$j >= 50') !== false, 'CGI uploader must cap the number of staged files');
nuffload_cgi_assert(strpos($source, 'length($qstring) > 262144') !== false, 'CGI uploader must cap hand-off metadata');
nuffload_cgi_assert(strpos($source, 'read($fh, $upload_chunk, 8192)') !== false, 'CGI uploader must copy staged binary data in bounded chunks');

if ($errors)
{
	fwrite(STDERR, implode("\n", $errors) . "\n");
	exit(1);
}

echo "Nuffload CGI safety checks passed.\n";
