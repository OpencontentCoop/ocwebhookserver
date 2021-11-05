<?php

class OCWebHookJobStats
{
    public static function getStats()
    {
        $db = eZDB::instance();
        $stats = $db->arrayQuery('SELECT webhook_id,       
           count(*) FILTER (WHERE execution_status = 0) AS "pending",
           count(*) FILTER (WHERE execution_status = 1) AS "running",
           count(*) FILTER (WHERE execution_status = 2) AS "done",
           count(*) FILTER (WHERE execution_status = 3) AS "failed",
           count(*) FILTER (WHERE execution_status = 4) AS "retry"
        FROM ocwebhook_job GROUP BY webhook_id;');

        $statHash = [];
        foreach ($stats as $stat) {
            $statHash[$stat['webhook_id']] = $stat;
        }

        return $statHash;
    }
}
