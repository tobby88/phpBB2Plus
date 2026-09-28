<?php
// Single-connection loopback fixture. No real credentials or external paths.
if (PHP_SAPI !== 'cli' || count($argv) !== 3 || !in_array($argv[2], array('quoted','zero','raw','refused','commit-failure','lost-ack'), true)) { exit(2); }
$root = realpath($argv[1]);
if ($root === false || basename($root) !== 'ftp-export') { exit(2); }
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if (!$server) { exit(2); }
echo substr(strrchr(stream_socket_get_name($server, false), ':'), 1) . "\n"; flush();
$client = stream_socket_accept($server, 15);
if (!$client) { fclose($server); exit(2); }
stream_set_timeout($client, 15);
fwrite($client, "220 Loopback export fixture\r\n");
$user = false; $authenticated = false; $directory = false; $address = null; $stored = false;
while (($line = fgets($client, 2048)) !== false) {
    $line = rtrim($line, "\r\n"); $space = strpos($line, ' ');
    $command = $space === false ? $line : substr($line, 0, $space);
    $argument = $space === false ? '' : substr($line, $space + 1);
    $response = '500 Unsupported fixture command';
    if ($command === 'USER') { $user = $argument === "fixture'\\user"; $response = '331 Password required'; }
    elseif ($command === 'PASS') { $authenticated = $user && $argument === "fixture'\\pass"; $response = $authenticated ? '230 Logged in' : '530 Fixture authentication refused'; }
    elseif ($command === 'QUIT') { fwrite($client, "221 Bye\r\n"); break; }
    elseif (!$authenticated) { $response = '530 Login required'; }
    elseif ($command === 'CWD') { $directory = $argument === ($argv[2] === 'zero' ? '0' : "folder's"); $response = $directory ? '250 Directory selected' : '550 Fixture directory refused'; }
    elseif ($command === 'TYPE') { $response = '200 Binary mode'; }
    elseif ($command === 'PORT') {
        $parts = explode(',', $argument);
        if (count($parts) === 6 && implode('.', array_slice($parts, 0, 4)) === '127.0.0.1'
            && ctype_digit($parts[4]) && ctype_digit($parts[5]) && (int)$parts[4] < 256 && (int)$parts[5] < 256) {
            $port = (int)$parts[4] * 256 + (int)$parts[5];
            if ($port > 0) { $address = 'tcp://127.0.0.1:' . $port; $response = '200 Data endpoint selected'; }
        }
    }
    elseif ($command === 'STOR' && $argv[2] === 'refused') { $response = '550 Injected fixture transfer refusal'; }
    elseif ($command === 'STOR' && $directory && $address !== null && $argument === 'fisubsilversh.style') {
        $data = stream_socket_client($address, $errno, $error, 10);
        if (!$data) { $response = '425 No fixture data connection'; }
        else {
            fwrite($client, "150 Receiving fixture\r\n"); stream_set_timeout($data, 10);
            $bytes = stream_get_contents($data, 1048577); $state = stream_get_meta_data($data); fclose($data);
            if (is_string($bytes) && strlen($bytes) > 0 && strlen($bytes) <= 1048576 && !$state['timed_out']) {
                $stored = file_put_contents($root . '/received.style', $bytes) === strlen($bytes);
            }
            $response = $stored ? '226 Transfer complete' : '451 Fixture transfer refused';
        }
    }
    fwrite($client, $response . "\r\n");
}
fclose($client); fclose($server); exit($stored ? 0 : 1);
