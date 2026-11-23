<?php
require_once __DIR__ . '/album-native-seed.php';
// Instrument the actual dedicated native connection, not an in-memory model.
class AlbumNativeObservedConnection
{
 var $database; var $observer;
 function __construct($database,$observer){$this->database=$database;$this->observer=$observer;}
 function __get($name){return $this->database->$name;}
 function __isset($name){return isset($this->database->$name);}
 function __call($method,$arguments){return call_user_func_array(array($this->database,$method),$arguments);}
 function sql_query($sql='',$transaction=false){return $this->observer->observed_query($this->database,$sql,$transaction);}
 function sql_numrows($result=0){return method_exists($this->observer,'observed_numrows')?$this->observer->observed_numrows($this->database,$result):$this->database->sql_numrows($result);}
 function sql_freeresult($result=0){return method_exists($this->observer,'observed_free')?$this->observer->observed_free($this->database,$result):$this->database->sql_freeresult($result);}
}

// Closing a mysqli handle can return before MariaDB processes the disconnect.
// Record only handles this fixture actually closed. A subsequent zero-wait
// operation must still fail immediately for any other holder.
class AlbumNativeDisconnectObservedConnection extends AlbumNativeObservedConnection
{
 function sql_close()
 {
  $id = $this->database->db_connect_id ? mysqli_thread_id($this->database->db_connect_id) : 0;
  $closed = $this->database->sql_close();
  if ($id > 0 && !$this->database->db_connect_id) { $this->observer->closing_owners[$id] = $id; }
  return $closed;
 }
}

function album_native_wait_for_closed_owners($observer)
{
 if (!$observer->closing_owners) { return; }
 $sql = 'SELECT ID FROM information_schema.PROCESSLIST WHERE ID IN (' . implode(',', $observer->closing_owners) . ')';
 $deadline = microtime(true) + 3.0;
 do
 {
  $result = $observer->sql_query($sql);
  if (!$result) { throw new RuntimeException('Observe exact closed Album fixture connections'); }
  try { $remaining = $observer->sql_fetchrowset($result); }
  finally { $observer->sql_freeresult($result); }
  if (!is_array($remaining)) { throw new RuntimeException('Closed Album fixture connection status was not readable'); }
  if (!$remaining) { $observer->closing_owners = array(); return; }
  usleep(25000);
 } while (microtime(true) < $deadline);
 throw new RuntimeException('Album fixture server did not finish closing its previous connections');
}
