<section class="hgroup">
    <h1>{'Webhook list'|i18n( 'extension/ocwebhookserver' )}</h1>
</section>

<div class="row">
    <div class="col-xs-4">
        <p>
            {if $webhook_count|gt(10)}
            {switch match=$limit}
            {case match=25}
                <a class="btn btn-info" href={'/user/preferences/set/webhooks_limit/10/'|ezurl} title="{'Show 10 items per page.'|i18n( 'design/admin/node/view/full' )}">10</a>
                <span class="btn btn-default current">25</span>
                <a class="btn btn-info" href={'/user/preferences/set/webhooks_limit/50/'|ezurl} title="{'Show 50 items per page.'|i18n( 'design/admin/node/view/full' )}">50</a>
            {/case}

            {case match=50}
                <a class="btn btn-info" href={'/user/preferences/set/webhooks_limit/10/'|ezurl} title="{'Show 10 items per page.'|i18n( 'design/admin/node/view/full' )}">10</a>
                <a class="btn btn-info" href={'/user/preferences/set/webhooks_limit/25/'|ezurl} title="{'Show 25 items per page.'|i18n( 'design/admin/node/view/full' )}">25</a>
                <span class="btn btn-default current">50</span>
            {/case}

            {case}
                <span class="btn btn-default current">10</span>
                <a class="btn btn-info" href={'/user/preferences/set/webhooks_limit/25/'|ezurl} title="{'Show 25 items per page.'|i18n( 'design/admin/node/view/full' )}">25</a>
                <a class="btn btn-info" href={'/user/preferences/set/webhooks_limit/50/'|ezurl} title="{'Show 50 items per page.'|i18n( 'design/admin/node/view/full' )}">50</a>
            {/case}

            {/switch}
            {/if}
        </p>
    </div>
    <div class="col-xs-8 text-right">
        <p>
            <a class="btn btn-success" href="{'webhook/edit/new'|ezurl(no)}">{"Add new webhook"|i18n( 'extension/ocwebhookserver' )}</a>
        </p>
    </div>
</div>

<hr />

<div class="row">
    <div class="col-xs-12">
        {if $webhook_count|eq(0)}
            {"No webhooks"|i18n( 'extension/ocwebhookserver' )}
        {else}
            <form method="post" action="{$uri|ezurl(no)}" style="background: #fff">

                <table class="table table-hover" cellspacing="0">
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
                    </tr>
                    </thead>

                    <tbody>
                    {foreach $webhooks as $webhook}
                        <tr class="{if $webhook.enabled|ne(1)}active{/if}">
                            <td>{$webhook.id|wash()}</td>
                            <td>{$webhook.name|wash()}</td>
                            <td>{$webhook.method|wash()} {$webhook.url|urldecode|wash()}</td>
                            <td>{$webhook.secret|wash()}</td>
                            <td>
                                {foreach $webhook.triggers as $trigger}<span style="white-space: nowrap">{$trigger['name']|wash()}</span>{delimiter}<br />{/delimiter}{/foreach}
                            </td>
                            <td>
                                {foreach $webhook.headers_array as $header}
                                    {$header|wash()}{delimiter}<br />{/delimiter}
                                {/foreach}
                            </td>
                            <td>
                                <button class="btn btn-sm btn-default" type="submit" {if $webhook.enabled|ne(1)}disabled="disabled"{/if} class="button" name="TestWebHook" value="{$webhook.id}"><i class="fa fa-gear"></i> {"Test"|i18n( 'extension/ocwebhookserver' )}</button>
                            </td>
                            <td>
                                {if $webhook.enabled|ne(1)}
                                    <button type="submit" class="btn btn-sm btn-success" name="EnableWebHook" value="{$webhook.id}">{"Enable"|i18n( 'extension/ocwebhookserver' )}</button>
                                {else}
                                    <button type="submit" class="btn btn-sm btn-danger" name="DisableWebHook" value="{$webhook.id}">{"Disable"|i18n( 'extension/ocwebhookserver' )}</button>
                                {/if}
                            </td>
                            <td>
                                <a class="btn btn-sm btn-default" href="{concat('webhook/logs/', $webhook.id)|ezurl(no)}">{"Logs"|i18n( 'extension/ocwebhookserver' )}</a>
                            </td>
                            <td>
                                <div class="btn-group">
                                    <button type="button" class="btn btn-sm btn-default dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                        {"Edit"|i18n( 'extension/ocwebhookserver' )} <span class="caret"></span>
                                    </button>
                                    <ul class="dropdown-menu">
                                        <li>
                                            <a href="{concat('webhook/edit/', $webhook.id)|ezurl(no)}"><i class="fa fa-edit"></i> {"Edit"|i18n( 'extension/ocwebhookserver' )}</a>
                                        </li>
                                        <li>
                                            <a onclick="return confirm('{'Are you sure you want to cancel this webhook ?'|i18n( 'extension/ocwebhookserver' )}')"
                                               href="{concat('webhook/remove/', $webhook.id)|ezurl(no)}"><i class="fa fa-trash"></i> {"Remove"|i18n( 'extension/ocwebhookserver' )}</a>
                                        </li>
                                    </ul>
                                </div>
                            </td>
                        </tr>
                    {/foreach}
                    </tbody>
                </table>
            </form>
        {/if}
        <div class="context-toolbar">
            {include name=navigator uri='design:navigator/google.tpl'
                     page_uri=$uri
                     item_count=$webhook_count
                     view_parameters=$view_parameters
                     item_limit=$limit}
        </div>
    </div>
</div>
