<?php

$stats = OCWebHookJobStats::getNamedStats();

$metrics = [
    [
        'name' => 'webhook_pending_jobs',
        'help' => 'Webhook pending jobs',
        'type' => 'counter',
        'stat_key' => 'pending',
    ],
    [
        'name' => 'webhook_running_jobs',
        'help' => 'Webhook running jobs',
        'type' => 'counter',
        'stat_key' => 'running',
    ],
    [
        'name' => 'webhook_done_jobs',
        'help' => 'Webhook done jobs',
        'type' => 'counter',
        'stat_key' => 'done',
    ],
    [
        'name' => 'webhook_failed_jobs',
        'help' => 'Webhook failed jobs',
        'type' => 'counter',
        'stat_key' => 'failed',
    ],
    [
        'name' => 'webhook_retry_jobs',
        'help' => 'Webhook retry jobs',
        'type' => 'counter',
        'stat_key' => 'retry',
    ],
];
$lines = [];
foreach ($metrics as $metric) {
    $lines[] = "# HELP " . $metric['name'] . " " . $metric['help'];
    $lines[] = "# TYPE " . $metric['name'] . " " . $metric['type'];
    foreach ($stats as $stat) {
        $lines[] = $metric['name'] . '{webhook="' . $stat['name'] . '"} ' . $stat[$metric['stat_key']];
    }
}
header('Content-Type: text/plain; version=0.0.4; charset=UTF-8');
echo implode("\n", $lines) . "\n";
eZExecution::cleanExit();