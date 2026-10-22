<?php
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
