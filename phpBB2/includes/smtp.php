<?php
/***************************************************************************
 *                              smtp.php
 *                       -------------------
 *   begin                : Wed May 09 2001
 *   copyright            : (C) 2001 The phpBB Group
 *   email                : support@phpbb.com
 *
 *   $Id: smtp.php,v 1.16.2.9 2003/07/18 16:34:01 acydburn Exp $
 *
 ***************************************************************************/

/***************************************************************************
 *
 *   This program is free software; you can redistribute it and/or modify
 *   it under the terms of the GNU General Public License as published by
 *   the Free Software Foundation; either version 2 of the License, or
 *   (at your option) any later version.
 *
 ***************************************************************************/

// Tell the Security Scanner that this constant is allowed
define('SMTP_INCLUDED', 1);

function smtp_envelope_address($address)
{
	$address = trim(preg_replace('/[\r\n]+/', '', (string) $address));
	if (preg_match('/<([^<>]+)>/', $address, $match))
	{
		$address = trim($match[1]);
	}

	return filter_var($address, FILTER_VALIDATE_EMAIL) !== false ? $address : '';
}

function smtp_dot_stuff($data)
{
	$data = str_replace(array("\r\n", "\r"), "\n", (string) $data);
	$data = preg_replace('/^\./m', '..', $data);
	return str_replace("\n", "\r\n", $data);
}

class PhpbbSmtpException extends RuntimeException {}

// RFC 5321 replies: bounded CRLF lines, consistent multiline codes and a
// terminal line that may contain only the code. Never expose remote reply text.
function smtp_read_response($socket, $expected)
{
	$expected = array_map('strval', (array) $expected);
	$deadline = microtime(true) + 20;
	$code = null;
	for ($response_line = 0; $response_line < 100; $response_line++)
	{
		$remaining = (int) ceil($deadline - microtime(true));
		if ($remaining <= 0) { throw new PhpbbSmtpException('Mail server response timed out'); }
		stream_set_timeout($socket, $remaining);
		$reply = @fgets($socket, 513);
		if ($reply === false)
		{
			$metadata = stream_get_meta_data($socket);
			throw new PhpbbSmtpException(!empty($metadata['timed_out']) ? 'Mail server response timed out' : 'Mail server connection closed before reply');
		}
		if (strlen($reply) > 512 || !preg_match('/^([2-5][0-5][0-9])(?:([- ])[^\\r\\n]*)?\\r\\n$/D', $reply, $match))
		{
			throw new PhpbbSmtpException('Invalid mail server response');
		}
		if ($code !== null && $code !== $match[1]) { throw new PhpbbSmtpException('Inconsistent multiline mail server response'); }
		$code = $match[1];
		if (!isset($match[2]) || $match[2] !== '-')
		{
			if (!in_array($code, $expected, true)) { throw new PhpbbSmtpException('Mail server rejected command (SMTP ' . $code . ')'); }
			return $code;
		}
	}
	throw new PhpbbSmtpException('Mail server sent an excessive multiline response');
}

// Compatibility wrapper: existing direct callers keep their visible error.
function server_parse($socket, $response, $line = __LINE__)
{
	try { return smtp_read_response($socket, $response); }
	catch (PhpbbSmtpException $error) { message_die(GENERAL_ERROR, $error->getMessage(), '', $line, __FILE__); }
}

function smtp_write_all($socket, $data)
{
	$offset = 0; $length = strlen($data); $deadline = microtime(true) + 20;
	while ($offset < $length)
	{
		$remaining = (int) ceil($deadline - microtime(true));
		if ($remaining <= 0) { throw new PhpbbSmtpException('Mail server write timed out'); }
		stream_set_timeout($socket, $remaining);
		$written = @fwrite($socket, substr($data, $offset, 8192));
		if ($written === false || $written === 0) { throw new PhpbbSmtpException('Could not write complete mail server command'); }
		$offset += $written;
	}
}
function smtp_command($socket, $command, $expected)
{
	smtp_write_all($socket, $command . "\r\n");
	return smtp_read_response($socket, $expected);
}

// Explicit opt-in exception mode lets optional notification callers recover.
// Direct legacy callers still receive message_die, after the socket is closed.
function smtpmail($mail_to, $subject, $message, $headers = '', $throw_exception = false)
{
	try { return phpbb_smtp_deliver($mail_to, $subject, $message, $headers); }
	catch (PhpbbSmtpException $error)
	{
		if ($throw_exception) { throw $error; }
		message_die(GENERAL_ERROR, $error->getMessage(), '', __LINE__, __FILE__);
	}
}

function phpbb_smtp_deliver($mail_to, $subject, $message, $headers)
{
	global $board_config;
	$subject = trim(preg_replace('/[\\x00\\r\\n]+/', '', (string) $subject));
	if ($subject === '') { throw new PhpbbSmtpException('No email subject specified'); }
	if (trim((string) $message) === '') { throw new PhpbbSmtpException('Email message was blank'); }
	$from_address = smtp_envelope_address($board_config['board_email']);
	if ($from_address === '') { throw new PhpbbSmtpException('Invalid board email address'); }
	$to_address = smtp_envelope_address($mail_to);
	$recipients = $to_address !== '' ? array($to_address) : array();
	$visible_headers = array(); $has_to = false;
	$headers = is_array($headers) ? implode("\n", $headers) : (string) $headers;
	$headers = preg_replace('/(?:\r\n|\r|\n)[ \t]+/', ' ', $headers);
	foreach (preg_split('/\\r\\n|\\r|\\n/', $headers) as $header)
	{
		if (preg_match('/^(bcc|cc):\\s*(.*)$/iD', $header, $match))
		{
			foreach (explode(',', $match[2]) as $recipient)
			{
				$recipient = smtp_envelope_address($recipient);
				if ($recipient !== '') { $recipients[] = $recipient; }
			}
			if (strtolower($match[1]) === 'bcc') { continue; }
		}
		if (preg_match('/^to:/i', $header)) { $has_to = true; }
		if ($header !== '') { $visible_headers[] = $header; }
	}
	$recipients = array_values(array_unique($recipients));
	if (!$recipients) { throw new PhpbbSmtpException('No valid email recipient specified'); }
	$smtp_host = trim((string) $board_config['smtp_host']);
	if ($smtp_host === '' || preg_match('/[\\x00-\\x20\\x7f]/', $smtp_host)) { throw new PhpbbSmtpException('No SMTP host configured'); }
	$socket = @fsockopen($smtp_host, 25, $errno, $errstr, 20);
	if (!$socket) { throw new PhpbbSmtpException('Could not connect to SMTP host'); }
	try
	{
		stream_set_timeout($socket, 20);
		smtp_read_response($socket, '220');
		$identity = preg_replace('/[^a-z0-9.-]/i', '', (string) $board_config['server_name']);
		if ($identity === '') { $identity = 'localhost'; }
		if (!empty($board_config['smtp_username']) && !empty($board_config['smtp_password']))
		{
			smtp_command($socket, 'EHLO ' . $identity, '250');
			smtp_command($socket, 'AUTH LOGIN', '334');
			smtp_command($socket, base64_encode($board_config['smtp_username']), '334');
			smtp_command($socket, base64_encode($board_config['smtp_password']), '235');
		}
		else { smtp_command($socket, 'HELO ' . $identity, '250'); }
		smtp_command($socket, 'MAIL FROM: <' . $from_address . '>', '250');
		foreach ($recipients as $recipient)
		{
			// Forwarding acceptance (251) does not change the stored address.
			smtp_command($socket, 'RCPT TO: <' . $recipient . '>', array('250', '251'));
		}
		smtp_command($socket, 'DATA', '354');
		$data = 'Subject: ' . $subject . "\r\n";
		if (!$has_to) { $data .= 'To: ' . ($to_address !== '' ? $to_address : 'Undisclosed-recipients:;') . "\r\n"; }
		$data .= implode("\r\n", $visible_headers) . "\r\n\r\n" . $message;
		smtp_write_all($socket, smtp_dot_stuff($data) . "\r\n.\r\n");
		smtp_read_response($socket, '250');
		// Acceptance is final: a failed QUIT must not cause duplicate retries.
		@fwrite($socket, "QUIT\r\n");
		return true;
	}
	finally { if (is_resource($socket)) { fclose($socket); } }
}
