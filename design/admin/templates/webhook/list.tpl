<div class="context-block">
    <div class="box-header">
        <div class="box-tc">
            <div class="box-ml">
                <div class="box-mr">
                    <div class="box-tl">
                        <div class="box-tr">
                            <h1 class="context-title">{'Webhook list'|i18n( 'extension/ocwebhookserver' )}</h1>
                            <div class="header-mainline"/>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="box-ml">
    <div class="box-mr">
        <div class="box-content">
            <div class="context-toolbar">
                <div class="block">
                    <div class="left">
                        <p>
                            {switch match=$limit}
                            {case match=25}
                                <a href={'/user/preferences/set/webhooks_limit/10/'|ezurl} title="{'Show 10 items per page.'|i18n( 'design/admin/node/view/full' )}">10</a>
                                <span class="current">25</span>
                                <a href={'/user/preferences/set/webhooks_limit/50/'|ezurl} title="{'Show 50 items per page.'|i18n( 'design/admin/node/view/full' )}">50</a>
                            {/case}

                            {case match=50}
                                <a href={'/user/preferences/set/webhooks_limit/10/'|ezurl} title="{'Show 10 items per page.'|i18n( 'design/admin/node/view/full' )}">10</a>
                                <a href={'/user/preferences/set/webhooks_limit/25/'|ezurl} title="{'Show 25 items per page.'|i18n( 'design/admin/node/view/full' )}">25</a>
                                <span class="current">50</span>
                            {/case}

                            {case}
                                <span class="current">10</span>
                                <a href={'/user/preferences/set/webhooks_limit/25/'|ezurl} title="{'Show 25 items per page.'|i18n( 'design/admin/node/view/full' )}">25</a>
                                <a href={'/user/preferences/set/webhooks_limit/50/'|ezurl} title="{'Show 50 items per page.'|i18n( 'design/admin/node/view/full' )}">50</a>
                            {/case}

                            {/switch}
                        </p>
                    </div>
                </div>
            </div>
            <div class="block">
                {if $webhook_count|eq(0)}
                    {"No webhooks"|i18n( 'extension/ocwebhookserver' )}
                {else}
                    <form method="post" action="{$uri|ezurl(no)}">
                        <table class="list" cellspacing="0">
                            <thead>
                            <tr>
                                <th width="1">{"ID"|i18n( 'extension/ocwebhookserver' )}</th>
                                <th>{"Name"|i18n( 'extension/ocwebhookserver' )}</th>
                                <th>{"Endpoint"|i18n( 'extension/ocwebhookserver' )}</th>
                                <th>{"Secret"|i18n( 'extension/ocwebhookserver' )}</th>
                                <th>{"Triggers"|i18n( 'extension/ocwebhookserver' )}</th>
                                <th>{"Headers"|i18n( 'extension/ocwebhookserver' )}</th>
                                <th width="1"></th>
                                <th width="1"></th>
                                <th width="1"></th>
                                <th width="1"></th>
                                <th width="1"></th>
                            </tr>
                            </thead>

                            <tbody>
                            {foreach $webhooks as $webhook sequence array( 'bglight', 'bgdark' ) as $trClass}
                                <tr class="{$trClass}{if $webhook.enabled|ne(1)} muted{/if}">
                                    <td>{$webhook.id|wash()}</td>
                                    <td>{$webhook.name|wash()}</td>
                                    <td>{$webhook.url|wash()}</td>
                                    <td>{$webhook.secret|wash()}</td>
                                    <td>
                                        {foreach $webhook.triggers as $trigger}{$trigger['name']|wash()}{delimiter}, {/delimiter}{/foreach}
                                    </td>
                                    <td>
                                        {foreach $webhook.headers_array as $header}
                                            {$header|wash()}<br />
                                        {/foreach}
                                    </td>
                                    <td>
                                        <a href="{concat('webhook/logs/', $webhook.id)|ezurl(no)}">{"Logs"|i18n( 'extension/ocwebhookserver' )}</a>
                                    </td>
                                    <td>
                                        <button type="submit" {if $webhook.enabled|ne(1)}disabled="disabled"{/if} class="button" name="TestWebHook" value="{$webhook.id}">{"Test"|i18n( 'extension/ocwebhookserver' )}</button>
                                    </td>
                                    <td>
                                        {if $webhook.enabled|ne(1)}
                                            <button type="submit" class="button" name="EnableWebHook" value="{$webhook.id}">{"Enable"|i18n( 'extension/ocwebhookserver' )}</button>
                                        {else}
                                            <button type="submit" class="button" name="DisableWebHook" value="{$webhook.id}">{"Disable"|i18n( 'extension/ocwebhookserver' )}</button>
                                        {/if}
                                    </td>
                                    <td>
                                        <a href="{concat('webhook/edit/', $webhook.id)|ezurl(no)}"><img alt="{"Edit"|i18n( 'extension/ocwebhookserver' )}" src={'edit.png'|ezimage} /></a>
                                    </td>
                                    <td>
                                        <a onclick="return confirm('{'Are you sure you want to cancel this webhook ?'|i18n( 'extension/ocwebhookserver' )}')"
                                           href="{concat('webhook/remove/', $webhook.id)|ezurl(no)}"><img alt="{"Remove"|i18n( 'extension/ocwebhookserver' )}" src={'trash.png'|ezimage} /></a>
                                    </td>
                                </tr>
                            {/foreach}
                            </tbody>
                        </table>
                    </form>
                {/if}
            </div>
            <div class="context-toolbar">
                {include name=navigator uri='design:navigator/google.tpl'
                         page_uri=$uri
                         item_count=$webhook_count
                         view_parameters=$view_parameters
                         item_limit=$limit}
            </div>
        </div>
    </div>
</div>

<div class="controlbar"><div class="box-bc"><div class="box-ml"><div class="box-mr"><div class="box-tc"><div class="box-bl"><div class="box-br"></div></div></div></div></div></div></div>
<style>
    table.list tr.muted td {ldelim}
        color: #ccc;
    {rdelim}
</style>