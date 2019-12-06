# OpenContent Webhook Server

A webhook is a way to provide information to another app about a particular event. 
The way the two apps communicate is with a simple HTTP request.

This eZPublish legacy extension allows you to configure and send webhooks easily.

### Installation

 - Require package `composer require opencontent/ocwebhookserver-ls`
 - Enable extension in site.ini
 - Regenerate autoloads and clear cache
 - Create your webhook in `/webhook/list`
 - Run the worker `php extension/ocwebhookserver/bin/php/worker.php`
 
 
### Default trigger
A post publish trigger is available: 
to activate it, you need to configure the workflow 'Post publish webhook' in content/after trigger

### Create new trigger
To add your own trigger: 
 - create a `OCWebHookTriggerInterface` implementation 
 - configure it in `webhook.ini [TriggersSettings]TriggerList`
 - put in your code the event trigger method
```
OCWebHookEmitter::emit(
    <your_trigger_identifier>,
    <your_json_serializable_payload>,
    OCWebHookQueue::defaultHandler()
);
```
see for example *eventtypes/event/workflowwebhook/workflowwebhooktype.php*

### Todo
 - trigger filter configurations
 - payload configurations 
 - worker evolutions
