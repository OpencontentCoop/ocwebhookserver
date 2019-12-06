<?php

/** @var eZModule $Module */
$Module = $Params['Module'];
$Result = array();
$tpl = eZTemplate::factory();
$http = eZHTTPTool::instance();
$currentURI = '/' . $Module->currentModule() . '/' . $Module->currentView();

if ($http->hasPostVariable('EnableWebHook')) {
    $webHook = OCWebHook::fetch((int)$http->postVariable('EnableWebHook'));
    if ($webHook instanceof OCWebHook) {
        $webHook->setAttribute('enabled', 1);
        $webHook->store();
        $Module->redirectTo($currentURI);
        return;
    }
}

if ($http->hasPostVariable('DisableWebHook')) {
    $webHook = OCWebHook::fetch((int)$http->postVariable('DisableWebHook'));
    if ($webHook instanceof OCWebHook) {
        $webHook->setAttribute('enabled', 0);
        $webHook->store();
        $Module->redirectTo($currentURI);
        return;
    }
}

if ($http->hasPostVariable('TestWebHook')) {
    $webHook = OCWebHook::fetch((int)$http->postVariable('TestWebHook'));
    if ($webHook instanceof OCWebHook && $webHook->isEnabled()) {
        $triggers = $webHook->getTriggers();
        $jobs = [];
        foreach ($triggers as $trigger) {

            $payload = [
                'test_webhook' => [
                    'id' => $webHook->attribute('id'),
                    'name' => $webHook->attribute('name'),
                    'endpoint' => $webHook->attribute('url'),
                    'trigger' => $trigger['identifier']
                ]
            ];

            $job = new OCWebHookJob([
                'webhook_id' => $webHook->attribute('id'),
                'trigger_identifier' => $trigger['identifier'],
                'payload' => json_encode($payload),
            ]);
            $job->store();
            $jobs[] = $job;
        }

        OCWebHookQueue::instance(OCWebHookQueue::HANDLER_IMMEDIATE)
            ->pushJobs($jobs)
            ->execute();

        $Module->redirectTo('/' . $Module->currentModule() . '/logs/' . $webHook->attribute('id'));
        return;
    }
}

try {
    $offset = isset($Params['UserParameters']['offset']) ? (int)$Params['UserParameters']['offset'] : 0; // Offset for pagination
    $limit = eZPreferences::value('webhooks_limit');
    $limit = $limit ? $limit : 10; // Default limit is 10
    $webHooks = OCWebHook::fetchList($offset, $limit);
    $webHookCount = OCWebHook::count(OCWebHook::definition());

    $tpl->setVariable('webhooks', $webHooks);
    $tpl->setVariable('offset', $offset);
    $tpl->setVariable('limit', $limit);
    $tpl->setVariable('uri', $currentURI);
    $tpl->setVariable('webhook_count', $webHookCount);
    $tpl->setVariable('view_parameters', $Params['UserParameters']);
} catch (Exception $e) {
    $tpl->setVariable('error_message', $e->getMessage());
}

$Result['path'] = array(
    array(
        'url' => false,
        'text' => ezpI18n::tr('extension/ocwebhookserver', 'Webhooks')
    )
);

$Result['content'] = $tpl->fetch('design:webhook/list.tpl');
