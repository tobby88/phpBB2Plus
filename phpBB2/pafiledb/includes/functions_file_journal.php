<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_file_storage.php';

class PaFileDbFileJournalFailure extends RuntimeException {}

// An upload intent is independently durable BEFORE publication. A deletion
// intent belongs to the metadata transaction. Recovery owns the same namespace
// as publishers and acts only on immutable intents and current references.
class PaFileDbFileJournal
{
    private $storage;
    private $table;
    private $prefix;
    private $actor;
    private $root;
    private $directories;
    private $board_url;
    private $files;
    private $operation;
    private $index = 0;
    private $prepared = array();
    private $queued = array();
    private $resolving = null;
    private $references = null;

    function __construct($storage, $prefix, $actor, $directories, $board_url, $root = null)
    {
        if (!is_object($storage) || !$storage->namespace_matches($prefix) || !is_int($actor) || ($actor !== -1 && ($actor < 1 || $actor > 8388607)) ||
            !is_string($board_url) || !is_array($directories) || !isset($directories['download'], $directories['screenshot'])) { throw new PaFileDbFileJournalFailure('Invalid download file journal context'); }
        if ($root === null) { $root = dirname(dirname(__DIR__)); }
		$origin = @parse_url($board_url);
		if (!is_string($root) || is_link($root) || !is_dir($root) || !$origin || empty($origin['host']) || empty($origin['scheme']) || !in_array(strtolower($origin['scheme']), array('http','https'), true) || isset($origin['user']) || isset($origin['pass']) || isset($origin['query']) || isset($origin['fragment'])) { throw new PaFileDbFileJournalFailure('Invalid download root or board origin'); }
        $this->storage = $storage; $this->prefix = $prefix; $this->table = $prefix . 'pa_file_journal'; $this->actor = $actor;
        $this->root = realpath($root); $this->board_url = rtrim($board_url, '/'); $this->directories = $directories;
        foreach ($directories as $scope => $relative) { if (is_string($relative)) { $this->directories[$scope] = rtrim($relative, '/'); } }
        $this->operation = bin2hex(phpbb_random_bytes(16)); $self = $this;
        $this->files = new PaFileDbFileStorage(function ($context) use ($self) { return $self->file_capability($context); }, $this->directories, $root);
    }

    function operation_id() { return $this->operation; }

    private static function integer($value, $maximum)
    {
        if (!(is_int($value) || is_string($value)) || !preg_match('/^(?:0|[1-9][0-9]*)$/D', (string) $value) || (float) $value > $maximum) { throw new PaFileDbFileJournalFailure('Invalid download journal number'); }
        return (string) $value;
    }

    private static function immutable($row)
    {
        $fields = array('operation_id','item_index','schema_version','operation_kind','actor_id','file_scope','file_dir','filename','file_bytes','file_sha256','created_at'); $result = array();
        foreach ($fields as $field) { if (!isset($row[$field]) || !(is_string($row[$field]) || is_int($row[$field]))) { throw new PaFileDbFileJournalFailure('Incomplete download file intent'); } $result[$field] = (string) $row[$field]; }
        return $result;
    }

    private static function binding($row) { return hash('sha256', serialize(self::immutable($row))); }

    private function parse($row)
    {
        if (!is_array($row) || !isset($row['binding_hash'], $row['item_state'], $row['completed_at'])) { throw new PaFileDbFileJournalFailure('Incomplete download journal row'); }
        $value = self::immutable($row);
        if (!preg_match('/^[a-f0-9]{32}$/D', $value['operation_id']) || $value['schema_version'] !== '1' || !in_array($value['operation_kind'], array('upload','delete'), true) ||
            !isset($this->directories[$value['file_scope']]) || $this->directories[$value['file_scope']] !== $value['file_dir'] ||
            !PaFileDbFileStorage::filename($value['filename']) || !preg_match('/^[a-f0-9]{64}$/D', $value['file_sha256']) ||
            !is_string($row['binding_hash']) || !preg_match('/^[a-f0-9]{64}$/D', $row['binding_hash']) || !hash_equals(self::binding($value), $row['binding_hash'])) { throw new PaFileDbFileJournalFailure('Download file intent binding differs'); }
        self::integer($value['item_index'], 1000000); self::integer($value['file_bytes'], PaFileDbFileStorage::MAX_BYTES); self::integer($value['created_at'], 4294967295);
        if ($value['actor_id'] !== '-1' && (self::integer($value['actor_id'], 8388607) === '0')) { throw new PaFileDbFileJournalFailure('Invalid download intent actor'); }
        $state = self::integer($row['item_state'], 1); $completed = self::integer($row['completed_at'], 4294967295);
        if (($state === '0' && $completed !== '0') || ($state === '1' && $completed === '0') || ($value['operation_kind'] === 'upload' && $value['file_bytes'] === '0')) { throw new PaFileDbFileJournalFailure('Invalid download intent state'); }
        $value['binding_hash'] = $row['binding_hash']; $value['item_state'] = $state; $value['completed_at'] = $completed;
        return $value;
    }

    private function metadata($row) { return array('present'=>true, 'bytes'=>(int)$row['file_bytes'], 'sha256'=>$row['file_sha256']); }

    private function where($row)
    {
        return "HEX(operation_id)='" . strtoupper(bin2hex($row['operation_id'])) . "' AND item_index=" . $row['item_index'];
    }

    private function rows($sql)
    {
        $result = $this->storage->sql_query($sql);
        try { return $this->storage->sql_fetchrowset($result); }
        finally { $this->storage->sql_freeresult($result); }
    }

    private function matches($expected, $durable = false)
    {
        $sql = 'SELECT * FROM ' . $this->table . ' WHERE ' . $this->where($expected) . ($durable ? '' : ' FOR UPDATE');
        $rows = $durable ? $this->storage->durable_rows($sql) : $this->rows($sql);
        return count($rows) === 1 && self::immutable($this->parse($rows[0])) === self::immutable($expected) && $rows[0]['binding_hash'] === $expected['binding_hash'];
    }

    private function file_capability($context)
    {
        if (!$this->storage->publication_ready()) { return false; }
        // Inspecting configured roots does not mutate files or grant publication.
        if ($context['action'] === 'roots') { return true; }
        if (!$this->storage->can_publish()) { return false; }
        if ($this->resolving !== null) {
            $row = $this->resolving;
            if ($context['scope'] !== $row['file_scope'] || $context['name'] !== $row['filename'] || !$this->matches($row)) { return false; }
            if ($context['action'] === 'remove-stage') { return $row['operation_kind'] === 'upload' && $context['operation'] === $row['operation_id']; }
            if ($context['action'] === 'remove') { return $context['metadata'] === $this->metadata($row) && !$this->referenced($row); }
            return $context['action'] === 'snapshot';
        }
        if ($context['action'] === 'snapshot') { return true; }
        if ($context['action'] !== 'publish' || $context['operation'] !== $this->operation) { return false; }
        $key = $context['scope'] . "\0" . $context['name'];
        return isset($this->prepared[$key]) && $context['metadata'] === $this->metadata($this->prepared[$key]) && $this->matches($this->prepared[$key], true);
    }

    private function descriptor($kind, $scope, $name, $metadata)
    {
        if (!isset($this->directories[$scope]) || !PaFileDbFileStorage::filename($name) || !PaFileDbFileStorage::metadata($metadata) || !$metadata['present'] || $this->index > 1000000) { throw new PaFileDbFileJournalFailure('Invalid download file intent'); }
        $row = array('operation_id'=>$this->operation,'item_index'=>(string)$this->index++,'schema_version'=>'1','operation_kind'=>$kind,'actor_id'=>(string)$this->actor,
            'file_scope'=>$scope,'file_dir'=>$this->directories[$scope],'filename'=>$name,'file_bytes'=>(string)$metadata['bytes'],'file_sha256'=>$metadata['sha256'],'created_at'=>(string)time());
        $row['binding_hash'] = self::binding($row); $row['item_state'] = '0'; $row['completed_at'] = '0';
        return $this->parse($row);
    }

    private function insert($row, $durable)
    {
        $values = array(); foreach ($row as $value) { $values[] = "'" . $this->storage->sql_escape($value) . "'"; }
        $sql = 'INSERT INTO ' . $this->table . ' (' . implode(',', array_keys($row)) . ')VALUES(' . implode(',', $values) . ')';
        if ($durable) { $this->storage->durable_query($sql); } else { $this->storage->sql_query($sql); }
    }

    private function source_metadata($input)
    {
        if (!is_resource($input) || get_resource_type($input) !== 'stream' || !$this->storage->can_publish()) { throw new PaFileDbFileJournalFailure('Invalid download source capability'); }
        $before = fstat($input); $position = ftell($input);
        if (!$before || ($before['mode'] & 0170000) !== 0100000 || $before['size'] < 1 || $before['size'] > PaFileDbFileStorage::MAX_BYTES || !is_int($position) || fseek($input, 0) !== 0) { throw new PaFileDbFileJournalFailure('Invalid download source'); }
        try {
            $hash = hash_init('sha256'); $bytes = 0;
            while (!feof($input)) { $chunk = fread($input, 65536); if ($chunk === false || ($chunk === '' && !feof($input))) { throw new PaFileDbFileJournalFailure('Download source read failed'); } $bytes += strlen($chunk); if ($bytes > $before['size']) { throw new PaFileDbFileJournalFailure('Download source grew'); } hash_update($hash, $chunk); }
            $after = fstat($input); foreach (array('dev','ino','mode','size','mtime','ctime') as $field) { if ($before[$field] !== $after[$field]) { throw new PaFileDbFileJournalFailure('Download source changed'); } }
            if ($bytes !== $before['size'] || !$this->storage->can_publish()) { throw new PaFileDbFileJournalFailure('Download source or capability changed'); }
            return array('present'=>true,'bytes'=>$bytes,'sha256'=>hash_final($hash));
        } finally { fseek($input, $position); }
    }

    function publish($scope, $name, $input)
    {
        $metadata = $this->source_metadata($input);
        if ($this->files->snapshot($scope, $name)['present']) { throw new PaFileDbFileJournalFailure('Download destination already exists'); }
        $row = $this->descriptor('upload', $scope, $name, $metadata);
        $this->storage->durable_begin(); $this->insert($row, true); $this->storage->durable_commit();
        $this->prepared[$scope . "\0" . $name] = $row;
        $this->files->publish($this->operation, $scope, $name, $input, $metadata);
        return $metadata;
    }

    function queue_delete($scope, $name)
    {
        $metadata = $this->files->snapshot($scope, $name); if (!$metadata['present']) { return false; }
        $key = $this->physical_key($scope, $name); if (isset($this->queued[$key])) { return true; }
        $row = $this->descriptor('delete', $scope, $name, $metadata); $this->insert($row, false); $this->queued[$key] = true;
        return true;
    }

    function queue_path($path)
    {
        if (!is_string($path) || $path === '' || preg_match('/[\x00-\x1f\x7f]/', $path)) { return false; }
        $directory = realpath(dirname($path)); $name = basename($path);
        foreach ($this->directories as $scope => $relative) { if ($this->same_directory($directory, realpath($this->root . '/' . $relative))) { return $this->queue_delete($scope, $name); } }
        return false;
    }

    function queue_url($url)
    {
        $key = $this->url_reference($url); if ($key === null) { return false; }
        list($directory, $name) = explode("\0", $key, 2);
        foreach ($this->directories as $scope => $relative) { if ($directory === str_replace('\\', '/', realpath($this->root . '/' . $relative))) { return $this->queue_delete($scope, $name); } }
        return false;
    }

    private function physical_key($scope, $name)
    {
        return str_replace('\\', '/', realpath($this->root . '/' . $this->directories[$scope])) . "\0" . $name;
    }

    private function physical_reference($directory, $name)
    {
        if (!is_string($directory) || !is_string($name) || !PaFileDbFileStorage::filename($name) || preg_match('/[\x00-\x1f\x7f]/', $directory)) { return null; }
        $resolved = realpath($this->root . '/' . $directory); if ($resolved === false) { return null; }
        foreach ($this->directories as $scope => $relative) { if ($this->same_directory($resolved, realpath($this->root . '/' . $relative))) { return $this->physical_key($scope, $name); } }
        return null;
    }

    private function same_directory($left, $right)
    {
        return is_string($left) && is_string($right) && (DIRECTORY_SEPARATOR === chr(92) ? strcasecmp($left, $right) === 0 : $left === $right);
    }

    private function url_reference($value)
    {
        if (!is_string($value) || $value === '' || preg_match('/[\x00-\x1f\x7f\\\\]/', $value)) { return null; }
        $parts = @parse_url($value); $board = @parse_url($this->board_url); if (!$parts || !$board || isset($parts['user']) || isset($parts['pass'])) { return null; }
        if (isset($parts['host'])) {
            if (empty($board['host']) || (isset($parts['scheme']) && !in_array(strtolower($parts['scheme']), array('http','https'), true)) ||
                strcasecmp(preg_replace('/^www\./i', '', $parts['host']), preg_replace('/^www\./i', '', $board['host'])) !== 0 ||
                (isset($parts['port']) && !in_array($parts['port'], array(80,443,isset($board['port']) ? $board['port'] : 0), true))) { return null; }
        } elseif (isset($parts['scheme'])) { return null; }
        $path = isset($parts['path']) ? rawurldecode($parts['path']) : '';
        if (preg_match('//u', $path) !== 1 || preg_match('/[\x00-\x1f\x7f\\\\]/', $path)) { return null; }
        $base = isset($board['path']) ? rtrim(rawurldecode($board['path']), '/') : '';
        if ($path === '' || $path[0] !== '/') { $path = $base . '/' . $path; }
        $segments = array(); foreach (explode('/', $path) as $part) { if ($part === '' || $part === '.') { continue; } if ($part === '..') { array_pop($segments); } else { $segments[] = $part; } } $path = '/' . implode('/', $segments);
        foreach ($this->directories as $scope => $relative) {
            $prefixes = array($relative . '/', $base . '/' . $relative . '/');
            foreach ($prefixes as $prefix) { if (strpos($path, $prefix) === 0) { $name = substr($path, strlen($prefix)); if (PaFileDbFileStorage::filename($name)) { return $this->physical_key($scope, $name); } } }
        }
        return null;
    }

    private function collect_references()
    {
        $references = array();
        foreach ($this->rows('SELECT file_id,unique_name,file_dir,file_dlurl,file_ssurl FROM ' . $this->prefix . 'pa_files ORDER BY file_id FOR UPDATE') as $row) {
            $key = $this->physical_reference($row['file_dir'], $row['unique_name']); if ($key !== null) { $references[$key] = true; }
            foreach (array('file_dlurl','file_ssurl') as $field) { $key = $this->url_reference($row[$field]); if ($key !== null) { $references[$key] = true; } }
        }
        foreach ($this->rows('SELECT mirror_id,unique_name,file_dir,file_dlurl FROM ' . $this->prefix . 'pa_mirrors ORDER BY mirror_id FOR UPDATE') as $row) {
            $key = $this->physical_reference($row['file_dir'], $row['unique_name']); if ($key !== null) { $references[$key] = true; }
            $key = $this->url_reference($row['file_dlurl']); if ($key !== null) { $references[$key] = true; }
        }
        return $references;
    }

    private function referenced($row)
    {
        if ($this->references === null) { $this->references = $this->collect_references(); }
        $key = $this->physical_key($row['file_scope'], $row['filename']); if (isset($this->references[$key])) { return true; }
        if (DIRECTORY_SEPARATOR === chr(92)) {
            foreach ($this->references as $reference => $unused) { if (preg_match('/^' . preg_quote($key, '/') . '$/iuD', $reference) === 1) { return true; } }
        }
        return false;
    }

    // Invoke on a fresh owning transaction, after the producer released its
    // handles. Journal row locks and the namespace prove no publisher is active.
    // The caller commits completion records; lost completion ACKs are retryable.
    function resolve($operation, $maximum = 50)
    {
        if (!is_string($operation) || !preg_match('/^[a-f0-9]{32}$/D', $operation) || !is_int($maximum) || $maximum < 1 || $maximum > 500) { throw new PaFileDbFileJournalFailure('Invalid download recovery selection'); }
        $this->storage->claim_recovery();
        $rows = $this->rows("SELECT * FROM " . $this->table . " WHERE HEX(operation_id)='" . strtoupper(bin2hex($operation)) . "' AND item_state=0 ORDER BY item_index LIMIT " . $maximum . ' FOR UPDATE');
        return $this->resolve_rows($rows);
    }

    // The cursor advances past attempted receipts as well as completed ones.
    // A changed file must stay pending without starving later operations.
    static function pending_query($prefix, $maximum, $after = '')
    {
        if (!is_string($prefix) || !preg_match('/^[A-Za-z0-9_]{1,40}$/D',$prefix) || !is_int($maximum) || $maximum<1 || $maximum>500 || !is_string($after)) { throw new PaFileDbFileJournalFailure('Invalid download recovery page'); }
        $where='';
        if ($after!=='') {
            if (!preg_match('/^([a-f0-9]{32}):(0|[1-9][0-9]{0,6})$/D',$after,$match) || (int)$match[2]>1000000) { throw new PaFileDbFileJournalFailure('Invalid download recovery cursor'); }
            $where=" AND (operation_id>'".$match[1]."' OR (operation_id='".$match[1]."' AND item_index>".$match[2].'))';
        }
        return 'SELECT * FROM '.$prefix.'pa_file_journal WHERE item_state=0'.$where.' ORDER BY operation_id,item_index LIMIT '.$maximum;
    }

    function resolve_pending($maximum = 50, $after = '')
    {
        $sql=self::pending_query($this->prefix,$maximum,$after);$this->storage->claim_recovery();
        $rows=$this->rows($sql.' FOR UPDATE');$result=$this->resolve_rows($rows);
        $result['next_after']=$after;
        if ($rows) { $last=$this->parse($rows[count($rows)-1]);$result['next_after']=$last['operation_id'].':'.$last['item_index']; }
        return $result;
    }

    private function resolve_rows($rows)
    {
        $this->references = null; $result = array('complete'=>0,'pending'=>0,'items'=>array());
        $result['selected'] = count($rows);
        foreach ($rows as $value) {
            $row = $this->parse($value); $self = $this;
            $this->storage->guard_commit(function () use ($self, $row) { return $self->matches($row); }); $this->resolving = $row;
            try {
                $referenced = $this->referenced($row); $snapshot = $this->files->snapshot($row['file_scope'], $row['filename']);
                if ($referenced) { $status = $snapshot === $this->metadata($row) ? 'retained' : 'changed-reference'; }
                else { $status = $this->files->remove_exact($row['file_scope'], $row['filename'], $this->metadata($row)); }
                $stage = $row['operation_kind'] === 'upload' ? $this->files->remove_stage($row['operation_id'], $row['file_scope'], $row['filename']) : 'missing';
                if (in_array($status, array('removed','missing','retained'), true) && in_array($stage, array('removed','missing'), true)) {
                    $this->storage->sql_query('UPDATE ' . $this->table . ' SET item_state=1,completed_at=' . time() . ' WHERE ' . $this->where($row) . ' AND item_state=0');
                    if ((int)$this->storage->sql_affectedrows() !== 1) { throw new PaFileDbFileJournalFailure('Download recovery completion changed'); } $result['complete']++;
                } else { $result['pending']++; }
                $result['items'][] = array('index'=>(int)$row['item_index'],'status'=>$status,'stage'=>$stage);
            } finally { $this->resolving = null; }
        }
        return $result;
    }
}
