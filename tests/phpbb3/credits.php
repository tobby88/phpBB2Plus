<?php
function seed_credits($db)
{
	$stmt = $db->prepare('INSERT INTO phpbb_hacks_list (hack_id,hack_name,hack_desc,hack_author,hack_author_email,hack_author_website,
		hack_version,hack_hide,hack_download_url,hack_file,hack_file_mtime,hack_add_date) VALUES (?,?,?,?,?,?,?,?,?,?,987654,123456)');
	$author = 'K. „Jörg“ & Team';
	$description = 'Die Datei C:\Archiv enthält \"Grüße\" 📗 und den Text &copy;.';
	$email = 'credits@example.invalid';
	$website = 'https://example.invalid/credits?a=1&b=2';
	$download = 'https://example.invalid/package.zip';
	$version = '0';
	$source = 'private-fixture-manifest.hl';
	$hide = 'No';
	$stmt->bind_param('isssssssss', $id, $name, $description, $author, $email, $website, $version, $hide, $download, $source);
	for ($i = 0; $i <= 31; ++$i)
	{
		$id = 19000 + $i;
		$name = sprintf('0000 Archive %02d', $i);
		if ($i === 30) { $website = 'unavailable'; $download = 'local-file.zip'; $email = 'unrecorded'; }
		if ($i === 31) { $name = '0000 Hidden archival entry'; $hide = 'Yes'; }
		$stmt->execute();
	}
}

function verify_credits($db)
{
	$row = $db->query('SELECT * FROM p3_plus_credits WHERE credit_id=19000')->fetch_assoc();
	check_fixture($row && (int) $row['visible'] === 1 && $row['version_text'] === '0' &&
		(int) $row['file_mtime'] === 987654 && (int) $row['added_time'] === 123456 &&
		$row['source_file'] === 'private-fixture-manifest.hl' &&
		html_entity_decode($row['description'], ENT_QUOTES, 'UTF-8') === 'Die Datei C:\Archiv enthält \"Grüße\" 📗 und den Text &copy;.',
		'The original Plus credit and its provenance were not preserved');
	$row = $db->query('SELECT visible FROM p3_plus_credits WHERE credit_id=19031')->fetch_row();
	check_fixture($row && (int) $row[0] === 0, 'A private credit entry was discarded or published');
}
