<?php

/** @var eZModule $Module */
$Module = $Params['Module'];
$Result = array();
$tpl = eZTemplate::factory();
$http = eZHTTPTool::instance();
$webHook = OCWebHook::fetch((int)$Params['ID']);
if ($webHook instanceof OCWebHook) {

    if ($http->hasPostVariable('BatchAction')){
        $action = $http->postVariable('BatchAction');
        $jobs = (array)$http->postVariable('Jobs');
        if ($action === 'Retry'){
            OCWebHookJob::batchRetryIfNeeded($jobs);
        }elseif ($action === 'StopRetry'){
            OCWebHookJob::batchStopRetry($jobs);
        }
    }

    try {
        $offset = isset($Params['UserParameters']['offset']) ? (int)$Params['UserParameters']['offset'] : 0; // Offset for pagination
        $status = isset($Params['UserParameters']['status']) ? (int)$Params['UserParameters']['status'] : null; // Offset for pagination
        $limit = eZPreferences::value('webhooks_limit');
        $limit = $limit ? $limit : 10; // Default limit is 10
        $jobs = OCWebHookJob::fetchListByWebHookId($webHook->attribute('id'), $offset, $limit, $status);
        $jobCount = OCWebHookJob::fetchCountByWebHookId($webHook->attribute('id'), $status);

        $currentURI = '/' . $Module->currentModule() . '/' . $Module->currentView() . '/' . $webHook->attribute('id');
        $currentParameters = "/(status)/$status";

        $status = $status === null ? -1 : $status;
        $tpl->setVariable('webhook', $webHook);
        $tpl->setVariable('status', $status);
        $tpl->setVariable('jobs', $jobs);
        $tpl->setVariable('offset', $offset);
        $tpl->setVariable('limit', $limit);
        $tpl->setVariable('uri', $currentURI);
        $tpl->setVariable('uri_parameters', $currentParameters);
        $tpl->setVariable('job_count', $jobCount);
        $tpl->setVariable('view_parameters', $Params['UserParameters']);
    } catch (Exception $e) {
        $tpl->setVariable('error_message', $e->getMessage());
    }

    $Result['path'] = array(
        array(
            'url' => 'webhook/list',
            'text' => ezpI18n::tr('extension/ocwebhookserver', 'Webhooks')
        ),
        array(
            'url' => false,
            'text' => ezpI18n::tr('extension/ocwebhookserver', 'Logs')
        )
    );

    $Result['content'] = $tpl->fetch('design:webhook/logs.tpl');


}else{
    return $Module->handleError( eZError::KERNEL_NOT_AVAILABLE, 'kernel' );
}
