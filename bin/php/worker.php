<?php
require 'autoload.php';

set_time_limit(0);

$cli = eZCLI::instance();
$script = eZScript::instance(array('description' => ("webhooks worker"),
    'use-session' => false,
    'use-modules' => true,
    'use-extensions' => true));

$script->startup();


$options = $script->getOptions(
    '[max_load_value:][has_jobs_delay:][empty_jobs_delay:][too_busy_delay:][jobs_limit:]',
    '',
    array(
        'max_load_value'  => 'Max load value (default 5)',
        'has_jobs_delay'  => 'Delay when isset jobs (default 3)',
        'empty_jobs_delay'  => 'Delay when not isset jobs (default 6)',
        'too_busy_delay'  => 'Delay when load is greather then max_load_value (default 60)',
        'jobs_limit'  => 'Slice of jobs to execute (default 10)',
    )
);
$script->initialize();
$script->setUseDebugAccumulators(true);

$user = eZUser::fetchByName('admin');
eZUser::setCurrentlyLoggedInUser($user, $user->attribute('contentobject_id'));
eZDB::setErrorHandling(eZDB::ERROR_HANDLING_EXCEPTIONS);

function isTooBusy($maxLoad)
{
    $load = sys_getloadavg();
    return $load[0] >= $maxLoad;
}

$verbose = $options['verbose'];
$maxLoadValue = $options['max_load_value'] ? $options['max_load_value'] : 5;
$hasWorkDelay = $options['has_jobs_delay'] ? $options['has_jobs_delay'] : 3;
$emptyQueueDelay = $options['empty_jobs_delay'] ? $options['empty_jobs_delay'] : 6;
$loadToHighDelay = $options['too_busy_delay'] ? $options['too_busy_delay'] : 60;
$limit = $options['jobs_limit'] ? $options['jobs_limit'] : 10;

$pusher = new OCWebHookPusher();

if ($verbose){
    $cli->output("max_load_value: {$maxLoadValue}");
    $cli->output("has_jobs_delay: {$hasWorkDelay}");
    $cli->output("empty_jobs_delay: {$emptyQueueDelay}");
    $cli->output("too_busy_delay: {$loadToHighDelay}");
    $cli->output("jobs_limit: {$limit}");
}

try {
    while (true) {
        if (!isTooBusy($maxLoadValue)) {
            OCWebHookFailure::scheduleRetries();
            if (OCWebHookJob::fetchTodoCount()) {

                if ($verbose) echo '+';
                $pusher->push(OCWebHookJob::fetchTodoList(0, $limit));

                sleep($hasWorkDelay);
            } else {
                if ($verbose) echo '-';
                sleep($emptyQueueDelay);
            }
        } else {
            if ($verbose) echo '.';
            sleep($loadToHighDelay);
        }
    }
} catch (Exception $e) {
    $cli->error($e->getMessage());
    $cli->output($e->getTraceAsString());
}

$script->shutdown();