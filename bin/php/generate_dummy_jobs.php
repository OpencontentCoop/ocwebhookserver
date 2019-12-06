<?php
require 'autoload.php';

set_time_limit(0);

$cli = eZCLI::instance();
$script = eZScript::instance(array('description' => ("Generate webhook jobs"),
    'use-session' => false,
    'use-modules' => true,
    'use-extensions' => true));

$script->startup();


$options = $script->getOptions(
    '[webhook:][number:]',
    '',
    array(
        'webhook'  => 'WebHook ID',
        'number'  => 'Job number to generate',
    )
);
$script->initialize();
$script->setUseDebugAccumulators(true);

$user = eZUser::fetchByName('admin');
eZUser::setCurrentlyLoggedInUser($user, $user->attribute('contentobject_id'));
eZDB::setErrorHandling(eZDB::ERROR_HANDLING_EXCEPTIONS);

$webHookId = (int)$options['webhook'];
$numberOfJobs = (int)$options['number'];

try {

    $webHook = OCWebHook::fetch((int)$webHookId);
    if ($webHook instanceof OCWebHook && $webHook->isEnabled()) {

        $cli->output("Generate $numberOfJobs jobs for first trigger of webhook " . $webHook->attribute('name'));

        for ($x = 0; $x <= $numberOfJobs; $x++) {

            $triggers = $webHook->getTriggers();

            $payload = [
                'test_webhook' => [
                    'id' => $webHook->attribute('id'),
                    'name' => $webHook->attribute('name'),
                    'endpoint' => $webHook->attribute('url'),
                    'trigger' => $triggers[0]['identifier']
                ]
            ];

            $job = new OCWebHookJob([
                'webhook_id' => $webHook->attribute('id'),
                'trigger_identifier' => $triggers[0]['identifier'],
                'payload' => json_encode($payload),
            ]);
            $job->store();

        }
    }else{
        $cli->error('Webhook not found or not enabled');
    }

} catch (Exception $e) {
    $cli->error($e->getMessage());
}

$script->shutdown();