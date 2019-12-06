<div class="context-block">
    {if is_set( $error_message )}
        <div class="message-error">
            <h2>{'Input did not validate'|i18n( 'design/admin/settings' )}</h2>
            <p>{$error_message}</p>
        </div>
    {/if}

    <div class="box-header">
        <div class="box-tc">
            <div class="box-ml">
                <div class="box-mr">
                    <div class="box-tl">
                        <div class="box-tr">
                            <h1 class="context-title">
                                {if $id|eq('new')}
                                    {'Create webhook'|i18n( 'extension/ocwebhookserver' )}
                                {else}
                                    {'Edit webhook'|i18n( 'extension/ocwebhookserver' )}
                                {/if}
                            </h1>
                            <div class="header-mainline"/>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>
<form action="{concat('/webhook/edit/', $id)|ezurl(no)}" method="post">
<div class="box-ml">
    <div class="box-mr">
        <div class="box-content">
            <table class="list">
                <tr>
                    <td width="1"><label for="name">{"Name"|i18n( 'extension/ocwebhookserver' )}</label></td>
                    <td><input required="required" class="box" id="name" type="text" name="name" value="{$webhook.name|wash()}" /></td>
                </tr>
                <tr>
                    <td width="1"><label for="url">{"Endpoint"|i18n( 'extension/ocwebhookserver' )}</label></td>
                    <td><input required="required" class="box" id="url" type="text" name="url" value="{$webhook.url|wash()}" /></td>
                </tr>
                <tr>
                    <td width="1"><label for="secret">{"Secret"|i18n( 'extension/ocwebhookserver' )}</label></td>
                    <td><input class="box" id="secret" type="text" name="secret" value="{$webhook.secret|wash()}" /></td>
                </tr>
                <tr>
                    <td width="1">{"Triggers"|i18n( 'extension/ocwebhookserver' )}</td>
                    <td>
                        {foreach $triggers as $trigger}
                            <label>
                                <input {if $trigger.can_enabled|not}disabled="disabled"{/if} type="checkbox" name="triggers[{$trigger.identifier}]" value="1" {if $webhook_triggers|contains($trigger.identifier)}checked="checked"{/if} />
                                {$trigger.name|wash()}
                                <small>{$trigger.description|wash()|autolink()}</small>
                            </label>
                        {/foreach}
                    </td>
                </tr>
                <tr>
                    <td width="1"><label for="headers">{"Headers"|i18n( 'extension/ocwebhookserver' )}</label></td>
                    <td><textarea class="box" id="secret" name="headers">{$webhook.headers_array|implode("\n")}</textarea></td>
                </tr>
                <tr>
                    <td width="1"><label for="enabled">{"Enable"|i18n( 'extension/ocwebhookserver' )}</label></td>
                    <td>
                        <input type="checkbox" name="enabled" value="1" {if $webhook.enabled|eq(1)}checked="checked"{/if} />
                    </td>
                </tr>
            </table>
        </div>
    </div>
    {* Buttons. *}
    <div class="controlbar">
        {* DESIGN: Control bar START *}
        <div class="box-bc">
            <div class="box-ml">
                <div class="box-mr">
                    <div class="box-tc">
                        <div class="box-bl">
                            <div class="box-br">
                                <div class="block">
                                    <input class="button defaultbutton" type="submit" name="Store" value="{'Store webhook'|i18n( 'extension/ocwebhookserver' )}" />
                                </div>
                                {* DESIGN: Control bar END *}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</form>