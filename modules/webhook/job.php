<?php

/** @var eZModule $Module */
$Module = $Params['Module'];
$jobId = $Params['JobID'];
$action = $Params['Action'];

$job = OCWebHookJob::fetch((int)$jobId);
if ($job instanceof OCWebHookJob) {
    if ($action === 'retry') {
        $job->retryIfNeeded();
    } elseif ($action === 'stop') {
        $job->stopRetry();
    }
    $Module->redirectTo('/webhook/logs/' . $job->attribute('webhook_id'));
} else {
    $Module->redirectTo('/webhook/list');
}