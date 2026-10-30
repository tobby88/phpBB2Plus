<?php
// RFC 2047 unstructured text. Keep each encoded word independently valid UTF-8
// and leave room for Subject / X-AntiAbuse names within the 76-byte line limit.
function phpbb_mail_header_value($value)
{
 if (!is_string($value)) { throw new RuntimeException('Invalid email header text'); }
 $value = str_replace(array("\0", "\r", "\n"), '', $value);
 if (preg_match('//u', $value) !== 1 || preg_match('/[\x01-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value))
 { throw new RuntimeException('Invalid email header text'); }
 if (strlen($value) <= 63 && !preg_match('/[^\x20-\x7e]/', $value) && strpos($value, '=?') === false)
 { return $value; }
 $words = array(); $offset = 0; $bytes = strlen($value);
 while ($offset < $bytes)
 {
  $length = min(36, $bytes - $offset);
  while ($offset + $length < $bytes && (ord($value[$offset + $length]) & 0xc0) === 0x80) { $length--; }
  $words[] = '=?UTF-8?B?' . base64_encode(substr($value, $offset, $length)) . '?=';
  $offset += $length;
 }
 return implode("\r\n ", $words);
}

// Diagnostic values are unstructured text too. Address and MIME fields have
// their own syntax and must never be passed through an encoded-word encoder.
function phpbb_mail_transport_headers($headers)
{
 $lines = preg_split('/\r\n|\r|\n/', (string) $headers);
 foreach ($lines as &$line)
 {
  if (preg_match('/^X-AntiAbuse:[ \t]*(.*)$/iD', $line, $match))
  { $line = 'X-AntiAbuse: ' . phpbb_mail_header_value($match[1]); }
 }
 unset($line);
 return implode("\r\n", $lines);
}

function phpbb_mail_transport_body($message)
{
 // Canonical text line breaks before quoted-printable wrapping. The resulting
 // wire body needs neither SMTPUTF8 nor an 8BITMIME capability.
 return quoted_printable_encode(str_replace(array("\r\n", "\r", "\n"), array("\n", "\n", "\r\n"), (string) $message));
}
