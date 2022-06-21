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
        FROM ocwebhook_job GROUP BY webhook_id ORDER BY webhook_id;');

        $statHash = [];
        foreach ($stats as $stat) {
            $statHash[$stat['webhook_id']] = $stat;
        }

        return $statHash;
    }

    public static function getNamedStats()
    {
        $db = eZDB::instance();
        $stats = $db->arrayQuery('SELECT ocwebhook.name,       
           count(*) FILTER (WHERE execution_status = 0) AS "pending",
           count(*) FILTER (WHERE execution_status = 1) AS "running",
           count(*) FILTER (WHERE execution_status = 2) AS "done",
           count(*) FILTER (WHERE execution_status = 3) AS "failed",
           count(*) FILTER (WHERE execution_status = 4) AS "retry"
        FROM ocwebhook_job INNER JOIN ocwebhook ON ocwebhook.id = ocwebhook_job.webhook_id GROUP BY ocwebhook.name ORDER BY ocwebhook.name;');

        return $stats;
    }
}
