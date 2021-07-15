<?php

/** @var eZModule $Module */
$Module = $Params['Module'];
$Result = array();
$tpl = eZTemplate::factory();
$http = eZHTTPTool::instance();
$id = $Params['ID'];
if ($id === 'new'){
    $webHook = new OCWebHook([]);
}else {
    $webHook = OCWebHook::fetch((int)$id);
}

if ($webHook instanceof OCWebHook) {

    if ($http->hasPostVariable('Store')){

        $hasError = false;
        eZDB::setErrorHandling(eZDB::ERROR_HANDLING_EXCEPTIONS);

        $webHook->setAttribute('name', $http->postVariable('name'));
        $webHook->setAttribute('url', $http->postVariable('url'));
        $webHook->setAttribute('method', strtoupper($http->postVariable('method')));
        $webHook->setAttribute('secret', $http->postVariable('secret'));
        $webHook->setAttribute('enabled', (int)$http->hasPostVariable('enabled'));
        $webHook->setAttribute('retry_enabled', (int)$http->hasPostVariable('retry_enabled'));

        $headers = [];
        $rawHeaders = explode("\n", $http->postVariable('headers'));
        foreach ($rawHeaders as $rawHeader){
            $rawHeader = trim($rawHeader);
            if (!empty($rawHeader)) {
                list($key, $value) = explode(':', $rawHeader, 2);
                $headers[trim($key)] = trim($value);
            }
        }
        $webHook->setAttribute('headers', json_encode($headers));

        if ($http->hasPostVariable('payload_params') && !empty($http->postVariable('payload_params'))){
            $payloadParamsValue = json_encode(json_decode($http->postVariable('payload_params'), true));
            $webHook->setAttribute('payload_params', $payloadParamsValue);
            if (json_decode($payloadParamsValue) === NULL) {
                $webHook->setAttribute('payload_params', $http->postVariable('payload_params'));
                $webHook->setAttribute('enabled', 0);
                $hasError = 1;
            }
        }

        try {
            $webHook->store();
            $triggers = [];
            if ($http->hasPostVariable('triggers')) {
                $triggersEnabled = $http->postVariable('triggers');
                foreach (array_keys($triggersEnabled) as $triggerEnabled) {
                    $triggers[$triggerEnabled] = [
                        'identifier' => $triggerEnabled
                    ];
                }
            }
            if ($http->hasPostVariable('trigger_filters')) {
                $triggerFilters = $http->postVariable('trigger_filters');
                foreach ($triggerFilters as $identifier => $filters) {
                    if (isset($triggers[$identifier])) {
                        $triggers[$identifier]['filters'] = $filters;
                    }
                }
            }
            $webHook->setTriggers($triggers);

            if ($hasError) {
                $Module->redirectTo('/webhook/edit/' . $webHook->attribute('id') . '/(error)/' . $hasError);
            }else{
                $Module->redirectTo('/webhook/list');
            }

            return;

        } catch (eZDBException $e) {
            $tpl->setVariable('error_message', $e->getMessage());
        }
    }

    $tpl->setVariable('id', $id);
    $tpl->setVariable('webhook', $webHook);
    $webHookTriggers = [];
    foreach ($webHook->getTriggers() as $trigger){
        $webHookTriggers[$trigger['identifier']] = $trigger;
    }
    $tpl->setVariable('webhook_triggers', $webHookTriggers);

    $triggersAsArray = OCWebHookTriggerRegistry::registeredTriggersAsArray();
    $tpl->setVariable('triggers', $triggersAsArray);

    $canCustomizePayload = false;
    $placeholders = [];
    $helpTexts = [];
    foreach ($triggersAsArray as $trigger){
        if ($trigger['can_customize_payload']){
            $canCustomizePayload = true;
            $placeholders = array_merge($placeholders, $trigger['available_payload_placeholders']);
            $helpTexts[] = $trigger['help_text'];
        }
    }
    $tpl->setVariable('can_customize_payload', $canCustomizePayload);
    $tpl->setVariable('payload_placeholders', array_unique($placeholders));
    $tpl->setVariable('help_texts', array_unique($helpTexts));

    if (isset($Params['UserParameters']['error'])){
        $error = $Params['UserParameters']['error'];
        if ($error == 1) {
            $tpl->setVariable('error_message', 'Invalid json in custom payload');
        }
    }

    $Result['path'] = array(
        array(
            'url' => 'webhook/list',
            'text' => ezpI18n::tr('extension/ocwebhookserver', 'Webhooks')
        ),
        array(
            'url' => false,
            'text' => ezpI18n::tr('extension/ocwebhookserver', 'Edit')
        )
    );

    $Result['content'] = $tpl->fetch('design:webhook/edit.tpl');

}else{
    return $Module->handleError( eZError::KERNEL_NOT_AVAILABLE, 'kernel' );
}
