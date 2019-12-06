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

        eZDB::setErrorHandling(eZDB::ERROR_HANDLING_EXCEPTIONS);

        $webHook->setAttribute('name', $http->postVariable('name'));
        $webHook->setAttribute('url', $http->postVariable('url'));
        $webHook->setAttribute('secret', $http->postVariable('secret'));
        $webHook->setAttribute('enabled', (int)$http->hasPostVariable('enabled'));

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
        try {
            $webHook->store();
            if ($http->hasPostVariable('triggers')) {
                $triggers = $http->postVariable('triggers');
                $webHook->setTriggers(array_keys($triggers));
            }

            $Module->redirectTo('/webhook/list');
            return;

        }catch (eZDBException $e){
            $tpl->setVariable('error_message', $e->getMessage());
        }
    }

    $tpl->setVariable('id', $id);
    $tpl->setVariable('webhook', $webHook);
    $webHookTriggers = [];
    foreach ($webHook->getTriggers() as $trigger){
        $webHookTriggers[] = $trigger['identifier']; //@todo show filters
    }
    $tpl->setVariable('webhook_triggers', $webHookTriggers);
    $tpl->setVariable('triggers', OCWebHookTriggerRegistry::registeredTriggersAsArray());

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
