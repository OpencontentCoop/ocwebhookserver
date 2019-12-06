<section class="hgroup">
    <h1>
        {if $id|eq('new')}
            {'Create webhook'|i18n( 'extension/ocwebhookserver' )}
        {else}
            {'Edit webhook'|i18n( 'extension/ocwebhookserver' )}
        {/if}
        <a class="pull-right" href="{'webhook/list/'|ezurl(no)}"><small>{'Back to webhook list'|i18n( 'extension/ocwebhookserver' )}</small></a>
    </h1>
</section>

{if is_set( $error_message )}
    <div class="alert alert-danger">
        <h4>{'Input did not validate'|i18n( 'design/admin/settings' )}</h4>
        <p>{$error_message}</p>
    </div>
{/if}

<form class="form" action="{concat('/webhook/edit/', $id)|ezurl(no)}" method="post">
    <div class="row" style="margin-bottom: 10px">
        <div class="col-md-2 col-md-offset-1">
            <label for="name">{"Name"|i18n( 'extension/ocwebhookserver' )}</label>
        </div>
        <div class="col-md-8">
            <input required="required" class="form-control" id="name" type="text" name="name"
                   value="{$webhook.name|wash()}"/>
        </div>
    </div>
    <div class="row" style="margin-bottom: 10px">
        <div class="col-md-2 col-md-offset-1">
            <label for="url">{"Endpoint"|i18n( 'extension/ocwebhookserver' )}</label>
        </div>
        <div class="col-md-8">
            <input required="required" class="form-control" id="url" type="text" name="url"
                   value="{$webhook.url|wash()}"/>
        </div>
    </div>
    <div class="row" style="margin-bottom: 10px">
        <div class="col-md-2 col-md-offset-1">
            <label for="secret">{"Secret"|i18n( 'extension/ocwebhookserver' )}</label>
        </div>
        <div class="col-md-8">
            <input class="form-control" id="secret" type="text" name="secret" value="{$webhook.secret|wash()}"/>
        </div>
    </div>
    <div class="row" style="margin-bottom: 10px">
        <div class="col-md-2 col-md-offset-1">
            <strong>{"Triggers"|i18n( 'extension/ocwebhookserver' )}</strong>
        </div>
        <div class="col-md-8">
            <div class="row">
            {foreach $triggers as $trigger}
                <div class="col-md-6">
                    <label class="checkbox" style="font-weight: normal">
                        <input type="checkbox" name="triggers[{$trigger.identifier}]" value="1"
                               {if $webhook_triggers|contains($trigger.identifier)}checked="checked"{/if} />
                        <strong>{$trigger.name|wash()}</strong>
                        <br /><small>{$trigger.description|wash()}</small>
                    </label>
                </div>
            {/foreach}
            </div>
        </div>
    </div>
    <div class="row" style="margin-bottom: 10px">
        <div class="col-md-2 col-md-offset-1">
            <label for="headers">{"Headers"|i18n( 'extension/ocwebhookserver' )}</label>
        </div>
        <div class="col-md-8">
            <textarea class="form-control" id="secret"
                      name="headers">{$webhook.headers_array|implode("\n")}</textarea>
        </div>
    </div>
    <div class="row" style="margin-bottom: 10px">
        <div class="col-md-8 col-md-offset-3">
            <label class="checkbox">
                <input type="checkbox" name="enabled" value="1" {if $webhook.enabled|eq(1)}checked="checked"{/if} />
                {"Enable"|i18n( 'extension/ocwebhookserver' )}
            </label>
        </div>
    </div>
    <div class="row" style="margin-bottom: 10px">
        <div class="col-md-11 text-right">
            <input class="btn btn-lg btn-success" type="submit" name="Store"
                   value="{'Store webhook'|i18n( 'extension/ocwebhookserver' )}"/>
        </div>
    </div>

</form>
