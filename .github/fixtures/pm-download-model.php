<?php
// SQLite models only check the actual ownership predicates. Independent mysqli
// sessions, autocommit state and caller snapshots are tested by the native suite.
class PmDownloadModelForum
{
    function sql_dedicated_connection(){return new PmDownloadModelReader('fixture','fixture','','fixture',false);}
}
class PmDownloadModelReader extends sql_db
{
    function sql_query($sql)
    {
        mutation_check(strpos($sql,'SELECT ')===0,'PM download reader issues SELECT only');
        if($this->closed || !$this->db_connect_id){return false;}
        if($sql==='SELECT @@SESSION.autocommit AS pm_autocommit,@@SESSION.in_transaction AS pm_transaction'){
            return $this->result(array(array('pm_autocommit'=>1,'pm_transaction'=>0)));
        }
        $s=$this->state;
        if($s->failure!=='' && strpos($sql,$s->failure)===0){return false;}
        return $this->result($s->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC));
    }
}
