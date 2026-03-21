<?php

$cli = eZCLI::instance();

$ini = eZINI::instance('webhook.ini');
$timeoutSeconds = (int)$ini->variable('KafkaSettings', 'RunningJobTimeoutSeconds');
if ($timeoutSeconds <= 0) {
    $cli->output('RunningJobTimeoutSeconds not configured or invalid, skipping.');
    return;
}

$threshold = time() - $timeoutSeconds;
$pendingStatus = OCWebHookJob::STATUS_PENDING;
$runningStatus = OCWebHookJob::STATUS_RUNNING;

$db = eZDB::instance();
$result = $db->query(
    "UPDATE ocwebhook_job
        SET execution_status = $pendingStatus
      WHERE execution_status = $runningStatus
        AND created_at < $threshold"
);

$affected = eZINI::instance()->variable('DatabaseSettings', 'DatabaseImplementation') === 'ezpostgresql'
    ? pg_affected_rows($result)
    : mysqli_affected_rows($result);

$cli->output("reset_running_jobs: $affected job(s) riportati in PENDING (timeout {$timeoutSeconds}s).");
