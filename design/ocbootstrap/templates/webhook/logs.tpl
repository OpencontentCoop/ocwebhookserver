<section class="hgroup">
    <h1>
        {'Webhook logs'|i18n( 'extension/ocwebhookserver' )}
        <a class="pull-right" href="{'webhook/list/'|ezurl(no)}"><small>{'Back to webhook list'|i18n( 'extension/ocwebhookserver' )}</small></a>
    </h1>
</section>

<div class="row">
    <div class="col-xs-4">
        <p>
            {if $job_count|gt(10)}
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
            {if $status|ge(0)}
                <a class="btn btn-info" href="{concat('webhook/logs/',$webhook.id)|ezurl(no)}">{"All"|i18n( 'extension/ocwebhookserver' )}</a>
            {/if}
            {if $status|eq(0)}
                <span class="btn btn-default current">{"Pending"|i18n( 'extension/ocwebhookserver' )}</span>
            {else}
                <a class="btn btn-info" href="{concat('webhook/logs/',$webhook.id, '/(status)/0')|ezurl(no)}">{"Pending"|i18n( 'extension/ocwebhookserver' )}</a>
            {/if}
            {if $status|eq(1)}
                <span class="btn btn-default current">{"Running"|i18n( 'extension/ocwebhookserver' )}</span>
            {else}
                <a class="btn btn-info" href="{concat('webhook/logs/',$webhook.id, '/(status)/1')|ezurl(no)}">{"Running"|i18n( 'extension/ocwebhookserver' )}</a>
            {/if}
            {if $status|eq(2)}
                <span class="btn btn-default current">{"Done"|i18n( 'extension/ocwebhookserver' )}</span>
            {else}
                <a class="btn btn-info" href="{concat('webhook/logs/',$webhook.id, '/(status)/2')|ezurl(no)}">{"Done"|i18n( 'extension/ocwebhookserver' )}</a>
            {/if}
            {if $status|eq(3)}
                <span class="btn btn-default current">{"Failed"|i18n( 'extension/ocwebhookserver' )}</span>
            {else}
                <a class="btn btn-info" href="{concat('webhook/logs/',$webhook.id, '/(status)/3')|ezurl(no)}">{"Failed"|i18n( 'extension/ocwebhookserver' )}</a>
            {/if}
        </p>
    </div>
</div>

<hr />

<div class="row">
    <div class="col-xs-12">
        {if $job_count|eq(0)}
            {"No jobs"|i18n( 'extension/ocwebhookserver' )}
        {else}
            <form method="post" action="{$uri|ezurl(no)}" style="background: #fff">
                <table class="table table-hover" cellspacing="0">
                    <thead>
                    <tr>
                        <th width="1">{"ID"|i18n( 'extension/ocwebhookserver' )}</th>
                        <th>{"Status"|i18n( 'extension/ocwebhookserver' )}</th>
                        <th style="white-space: nowrap">{"Response code"|i18n( 'extension/ocwebhookserver' )}</th>
                        <th>{"Details"|i18n( 'extension/ocwebhookserver' )}</th>
                        <th>{"Payload"|i18n( 'extension/ocwebhookserver' )}</th>
                        <th style="white-space: nowrap">{"Response headers/Error message"|i18n( 'extension/ocwebhookserver' )}</th>
                        <th></th>
                    </tr>
                    </thead>

                    <tbody>
                    {foreach $jobs as $job sequence array( 'bglight', 'bgdark' ) as $trClass}
                        <tr class="{$trClass}">
                            <td>{$job.id|wash()}</td>
                            <td>
                                {if $job.execution_status|eq(0)}
                                    {"Pending"|i18n( 'extension/ocwebhookserver' )}
                                {elseif $job.execution_status|eq(1)}
                                    {"Running"|i18n( 'extension/ocwebhookserver' )}
                                {elseif $job.execution_status|eq(2)}
                                    {"Done"|i18n( 'extension/ocwebhookserver' )}
                                {elseif $job.execution_status|eq(3)}
                                    {"Failed"|i18n( 'extension/ocwebhookserver' )}
                                {/if}
                            </td>
                            <td class="text-center">{$job.response_status|wash()}</td>
                            <td>
                                <dl>
                                    <dt>{"Trigger"|i18n( 'extension/ocwebhookserver' )}:</dt>
                                    <dd>{$job.trigger.name|wash()}</dd>

                                    <dt>{"Created at"|i18n( 'extension/ocwebhookserver' )}:</dt>
                                    <dd>{$job.created_at|l10n( datetime )}</dd>

                                    <dt>{"Executed at"|i18n( 'extension/ocwebhookserver' )}:</dt>
                                    <dd>{if $job.executed_at|int()|gt(0)}{$job.executed_at|l10n( datetime )}{/if}</dd>

                                    <dt>{"Executor"|i18n( 'extension/ocwebhookserver' )}:</dt>
                                    <dd>{if $job.hostname}{$job.hostname|wash()} ({$job.pid|wash()}){/if}</dd>
                                </dl>

                            </td>
                            <td><pre><code class="json">{$job.payload|wash()}</code></pre></td>
                            <td>
                                <pre><code class="json">{$job.response_headers|wash()}</code></pre>
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
            item_count=$job_count
            view_parameters=$view_parameters
            item_limit=$limit}
        </div>
    </div>

</div>

{literal}
<script>
    $(document).ready(function () {

        var library = {};
        library.json = {
            replacer: function(match, pIndent, pKey, pVal, pEnd) {
                var key = '<span class=json-key>';
                var val = '<span class=json-value>';
                var str = '<span class=json-string>';
                var r = pIndent || '';
                if (pKey)
                    r = r + key + pKey.replace(/[": ]/g, '') + '</span>: ';
                if (pVal)
                    r = r + (pVal[0] == '"' ? str : val) + pVal + '</span>';
                return r + (pEnd || '');
            },
            prettyPrint: function(obj) {
                var jsonLine = /^( *)("[\w]+": )?("[^"]*"|[\w.+-]*)?([,[{])?$/mg;
                return JSON.stringify(obj, null, 3)
                    .replace(/&/g, '&amp;').replace(/\\"/g, '&quot;')
                    .replace(/</g, '&lt;').replace(/>/g, '&gt;')
                    .replace(jsonLine, library.json.replacer);
            }
        };


        $('code.json').each(function () {
            try {
                var tmpData = JSON.parse($(this).text());
                $(this).html(library.json.prettyPrint(tmpData));
            } catch (e) {
                $(this).parent().css({'white-space':'normal'});
                console.log(e);
            }
        });
    });
</script>
<style>
    pre {
        background-color: #f8f8ff;
        border: 1px solid #C0C0C0;
        padding: 5px;
        margin: 0;
        font-size: .7em;
    }
    .json-key {
        color: #A52A2A;
    }
    .json-value {
        color: #000080;
    }
    .json-string {
        color: #556b2f;
    }
</style>
{/literal}