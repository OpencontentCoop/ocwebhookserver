<?php

/** @var eZModule $Module */
$Module = $Params['Module'];
$jobId = $Params['JobID'];
$action = $Params['Action'];

$job = OCWebHookJob::fetch((int)$jobId);
if ($job instanceof OCWebHookJob) {
    if (!empty($action)) {

        if ($action === 'retry') {
            $job->retryIfNeeded();
        } elseif ($action === 'stop') {
            $job->stopRetry();
        }
        $Module->redirectTo('/webhook/job/' . $job->attribute('id'));

    } else {

        $tpl = eZTemplate::factory();
        $webHook = $job->getWebhook();
        $currentURI = '/' . $Module->currentModule() . '/' . $Module->currentView() . '/' . $webHook->attribute('id');
        $currentParameters = '';
        $tpl->setVariable('webhook', $webHook);
        $tpl->setVariable('status', -1);
        $tpl->setVariable('jobs', [$job]);
        $tpl->setVariable('offset', 0);
        $tpl->setVariable('limit', 1);
        $tpl->setVariable('uri', $currentURI);
        $tpl->setVariable('uri_parameters', $currentParameters);
        $tpl->setVariable('job_count', 1);
        $tpl->setVariable('view_parameters', $Params['UserParameters']);
        $Result['path'] = [
            [
                'url' => 'webhook/list',
                'text' => ezpI18n::tr('extension/ocwebhookserver', 'Webhooks')
            ],
            [
                'url' => false,
                'text' => ezpI18n::tr('extension/ocwebhookserver', 'Log')
            ],
            [
                'url' => false,
                'text' => $job->attribute('id')
            ]
        ];
        $Result['content'] = $tpl->fetch('design:webhook/logs.tpl');
    }

} else {
    $Module->redirectTo('/webhook/list');
}