<?php
// Execute the complete image endpoint in isolated processes: it deliberately
// exits after its binary response. Only synthetic read-only database rows.
$root = dirname(dirname(__DIR__)).'/phpBB2/';
function ci_assert($condition, $message) {
    if (!$condition) { throw new RuntimeException($message); }
}
if (isset($argv[1]) && strpos($argv[1], '--fixture=') === 0) {
    $fixture = json_decode(base64_decode(substr($argv[1], 10), true), true);
    ci_assert(is_array($fixture), 'Explicit image fixture required');
    define('IN_PHPBB', true); define('CONFIRM_TABLE', 'fixture_confirm');
    $HTTP_GET_VARS = array('id'=>$fixture['id']);
    $userdata = array('session_id'=>str_repeat('b', 32));
    class ConfirmationImageDatabase {
        public $queries=0;
        function sql_query($sql) {
            ci_assert(strpos($sql, 'SELECT code') === 0 &&
                strpos($sql, "session_id = '".str_repeat('b', 32)."'") !== false,
                'Actual image query remains read-only and session-scoped');
            $this->queries++; return true;
        }
        function sql_fetchrow($result) {
            return $GLOBALS['fixture']['found'] ? array('code'=>$GLOBALS['fixture']['code']) : false;
        }
        function sql_freeresult($result) {}
    }
    $db = new ConfirmationImageDatabase();
    error_reporting(E_ALL);
    set_error_handler(function($severity, $message) { throw new RuntimeException($message); });
    ob_start();
    register_shutdown_function(function() use ($db) {
        $image = ob_get_clean();
        echo json_encode(array('image'=>base64_encode($image), 'queries'=>$db->queries)), "\n";
    });
    require $root.'includes/usercp_confirm.php';
    exit(3); // The real endpoint must terminate its own response.
}
function ci_render($fixture) {
    // Retain this runtime's normal extension configuration. On Linux PHP 5/7,
    // even JSON may be a shared extension and disappears under a forced -n.
    $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' '.
        escapeshellarg('--fixture='.base64_encode(json_encode($fixture)));
    $process = proc_open($command, array(0=>array('pipe','r'), 1=>array('pipe','w'), 2=>array('pipe','w')),
        $pipes, null, null, array('bypass_shell'=>true));
    ci_assert(is_resource($process), 'Actual image child starts'); fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $errors = stream_get_contents($pipes[2]); fclose($pipes[2]); $exit = proc_close($process);
    ci_assert($exit === 0 && $errors === '', 'Actual image endpoint exits without warnings (exit '.$exit.'): '.$errors.substr($output,0,500));
    $result = json_decode($output, true);
    ci_assert(is_array($result) && isset($result['image'], $result['queries']), 'Completed binary response receipt');
    $result['image'] = base64_decode($result['image'], true);
    ci_assert(is_string($result['image']), 'Unmodified binary response');
    return $result;
}
function ci_png($image) {
    ci_assert(substr($image, 0, 8) === "\x89PNG\r\n\x1a\n", 'Actual PNG signature');
    $offset=8; $types=array(); $compressed='';
    while ($offset < strlen($image)) {
        ci_assert(strlen($image)-$offset >= 12, 'Complete chunk header');
        $size=unpack('Nlength', substr($image,$offset,4)); $length=$size['length'];
        ci_assert($length <= strlen($image)-$offset-12, 'Complete chunk payload');
        $type=substr($image,$offset+4,4); $data=substr($image,$offset+8,$length);
        ci_assert(substr($image,$offset+8+$length,4) === pack('N',crc32($type.$data)), 'Valid chunk checksum');
        $types[]=$type;
        if ($type==='IHDR') {
            ci_assert($length===13, 'Standard image header');
            $header=unpack('Nwidth/Nheight/Cdepth/Ccolor/Ccompression/Cfilter/Cinterlace',$data);
            ci_assert($header===array('width'=>320,'height'=>50,'depth'=>8,'color'=>0,
                'compression'=>0,'filter'=>0,'interlace'=>0), 'Unchanged greyscale dimensions and format');
        } elseif ($type==='IDAT') { $compressed.=$data; }
        elseif ($type==='IEND') { ci_assert($length===0,'Empty image terminator'); }
        $offset+=12+$length;
    }
    ci_assert($types===array('IHDR','IDAT','IEND'), 'Complete image with no warning text or trailing bytes');
    $raw=gzuncompress($compressed);
    ci_assert(is_string($raw) && strlen($raw)===321*50, 'Complete decompressed raster');
    for($row=0;$row<50;$row++){ci_assert($raw[$row*321]==="\0",'Unchanged row filter');}
}
$cases=0;
// Cover every legacy glyph as well as the current generator alphabet; odd and
// even random crop widths must both render without float string offsets.
foreach(array('012345','6789AB','CDEFGH','IJKLMN','OPQRST','UVWXYZ') as $code) {
    $result=ci_render(array('id'=>str_repeat('a',32),'code'=>$code,'found'=>true));
    ci_assert($result['queries']===1,'One current-session image lookup'); ci_png($result['image']); $cases++;
}
foreach(array(null, array('invalid'), '', str_repeat('a',32)) as $id) {
    $result=ci_render(array('id'=>$id,'code'=>'ABC234','found'=>false));
    ci_assert($result['image']==='' && $result['queries']===($id===str_repeat('a',32)?1:0),
        'Missing or another session\'s challenge emits no image'); $cases++;
}
echo 'Confirmation image: '.$cases." complete endpoint cases, warning-free PNG checksums and rasters passed.\n";
