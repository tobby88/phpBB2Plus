<?php
// SQLite models only check the actual ownership predicates. Independent mysqli
// sessions, autocommit state and caller snapshots are tested by the native suite.
class PmDownloadModelForum
{
    function sql_dedicated_connection(){return new PmDownloadModelReader('fixture','fixture','','fixture',false);}
    function sql_query($sql){mutation_check($sql==='SELECT CONNECTION_ID() AS owned_connection_id,DATABASE() AS owned_schema,@@SESSION.autocommit AS owned_autocommit,@@SESSION.character_set_connection AS owned_charset','Model caller only supplies connection metadata');$result=new stdClass();$result->rows=array(array('owned_connection_id'=>'1','owned_schema'=>'fixture','owned_autocommit'=>'1','owned_charset'=>'utf8mb4'));return$result;}
    function sql_fetchrowset($result){return$result->rows;}
    function sql_freeresult($result){}
}
class PmDownloadModelReader extends sql_db
{
    var $model_id;
    function __construct($server,$user,$password,$name,$persistent){static$next=2;$this->model_id=(string)$next++;parent::__construct($server,$user,$password,$name,$persistent);}
    function sql_query($sql)
    {
        if($this->closed || !$this->db_connect_id){return false;}
        if($sql==='SET TRANSACTION READ ONLY'||$sql==='SET TRANSACTION READ WRITE'){return true;}
        if($sql==='SELECT CONNECTION_ID() AS owned_connection_id,DATABASE() AS owned_schema,@@SESSION.autocommit AS owned_autocommit,@@SESSION.character_set_connection AS owned_charset'){return$this->result(array(array('owned_connection_id'=>$this->model_id,'owned_schema'=>'fixture','owned_autocommit'=>'1','owned_charset'=>'utf8mb4')));}
        mutation_check(strpos($sql,'SELECT ')===0,'PM download reader only sets next-read mode and selects');
        if($sql==='SELECT @@SESSION.autocommit AS pm_autocommit'){
            return $this->result(array(array('pm_autocommit'=>1)));
        }
        $s=$this->state;
        if($s->failure!=='' && strpos($sql,$s->failure)===0){return false;}
        return $this->result($s->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC));
    }
}
