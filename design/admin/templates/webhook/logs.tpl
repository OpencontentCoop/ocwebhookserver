<div class="context-block">
    <div class="box-header">
        <div class="box-tc">
            <div class="box-ml">
                <div class="box-mr">
                    <div class="box-tl">
                        <div class="box-tr">
                            <h1 class="context-title">{'Webhook logs'|i18n( 'extension/ocwebhookserver' )}</h1>
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
                            <strong>{"Pagination"|i18n( 'extension/ocwebhookserver' )}</strong>
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

                            <strong style="margin-left: 20px">{"Status"|i18n( 'extension/ocwebhookserver' )}</strong>
                            {if $status|ge(0)}
                                <a href="{concat('webhook/logs/',$webhook.id)|ezurl(no)}">{"All"|i18n( 'extension/ocwebhookserver' )}</a>
                            {/if}
                            {if $status|eq(0)}
                                <span class="current">{"Pending"|i18n( 'extension/ocwebhookserver' )}</span>
                            {else}
                                <a href="{concat('webhook/logs/',$webhook.id, '/(status)/0')|ezurl(no)}">{"Pending"|i18n( 'extension/ocwebhookserver' )}</a>
                            {/if}
                            {if $status|eq(1)}
                                <span class="current">{"Running"|i18n( 'extension/ocwebhookserver' )}</span>
                            {else}
                                <a href="{concat('webhook/logs/',$webhook.id, '/(status)/1')|ezurl(no)}">{"Running"|i18n( 'extension/ocwebhookserver' )}</a>
                            {/if}
                            {if $status|eq(2)}
                                <span class="current">{"Done"|i18n( 'extension/ocwebhookserver' )}</span>
                            {else}
                                <a href="{concat('webhook/logs/',$webhook.id, '/(status)/2')|ezurl(no)}">{"Done"|i18n( 'extension/ocwebhookserver' )}</a>
                            {/if}
                            {if $status|eq(3)}
                                <span class="current">{"Failed"|i18n( 'extension/ocwebhookserver' )}</span>
                            {else}
                                <a href="{concat('webhook/logs/',$webhook.id, '/(status)/3')|ezurl(no)}">{"Failed"|i18n( 'extension/ocwebhookserver' )}</a>
                            {/if}
                            {if $status|eq(4)}
                                <span class="current">{"Retrying"|i18n( 'extension/ocwebhookserver' )}</span>
                            {else}
                                <a href="{concat('webhook/logs/',$webhook.id, '/(status)/4')|ezurl(no)}">{"Retrying"|i18n( 'extension/ocwebhookserver' )}</a>
                            {/if}
                        </p>
                    </div>
                </div>
            </div>
            <div class="block">
                {if $job_count|eq(0)}
                    {"No jobs"|i18n( 'extension/ocwebhookserver' )}
                {else}
                    <form method="post" action="{$uri|ezurl(no)}">
                        <table class="list" cellspacing="0">
                            <thead>
                            <tr>
                                <th width="1">{"ID"|i18n( 'extension/ocwebhookserver' )}</th>
                                <th>{"Status"|i18n( 'extension/ocwebhookserver' )}</th>
                                <th>{"Response code"|i18n( 'extension/ocwebhookserver' )}</th>
                                <th>{"Trigger"|i18n( 'extension/ocwebhookserver' )}</th>
                                <th>{"Payload"|i18n( 'extension/ocwebhookserver' )}</th>
                                <th>{"Created at"|i18n( 'extension/ocwebhookserver' )}</th>
                                <th>{"Executed at"|i18n( 'extension/ocwebhookserver' )}</th>
                                <th>{"Response"|i18n( 'extension/ocwebhookserver' )}</th>
                                <th>{"Executor"|i18n( 'extension/ocwebhookserver' )}</th>
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
                                            <p><a href="{concat('webhook/job/',$job.id, '/retry')|ezurl(no)}" class="button">{"Retry"|i18n( 'extension/ocwebhookserver' )}</a></p>
                                        {elseif $job.execution_status|eq(4)}
                                            {"Retrying"|i18n( 'extension/ocwebhookserver' )}
                                            {def $failures = $job.failures}
                                            {if $failures|count()}
                                                <ol style="font-size: .875em">
                                                    {foreach $failures as $failure}
                                                        <li title="#{$failure.id|wash()}">
                                                            {$failure.executed_at|l10n( shortdatetime )}: {if $failure.response_status}{$failure.response_status|wash()}{else}?{/if}
                                                        </li>
                                                    {/foreach}
                                                    {if $job.next_retry}
                                                        <li>
                                                            <em>{"Next retry:"|i18n( 'extension/ocwebhookserver' )} {$job.next_retry|l10n( shortdatetime )}</em>
                                                            <p><a href="{concat('webhook/job/',$job.id, '/stop')|ezurl(no)}" class="button">{"Stop retry"|i18n( 'extension/ocwebhookserver' )}</a></p>
                                                            <p><a href="{concat('webhook/job/',$job.id, '/retry')|ezurl(no)}" class="button">{"Retry"|i18n( 'extension/ocwebhookserver' )}</a></p>
                                                        </li>
                                                    {/if}
                                                </ol>
                                            {/if}
                                            {undef $failures}
                                        {/if}
                                    </td>
                                    <td>{$job.response_status|wash()}</td>
                                    <td>{$job.trigger.name|wash()}</td>
                                    <td><pre style="max-width: 200px;max-height: 200px;overflow: auto"><code class="json">{$job.serialized_payload|json_encode()|wash()}</code></pre></td>
                                    <td>{$job.created_at|l10n( datetime )}</td>
                                    <td>{if $job.executed_at|int()|gt(0)}{$job.executed_at|l10n( datetime )}{/if}</td>
                                    <td><pre style="max-width: 200px;max-height: 200px;overflow: auto"><code class="json">{$job.response_headers|wash()}</code></pre></td>
                                    <td>
                                        {if $job.hostname}
                                            {$job.hostname|wash()} ({$job.pid|wash()})
                                        {/if}
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
                         item_count=$job_count
                         view_parameters=$view_parameters
                         item_limit=$limit}
            </div>
        </div>
    </div>
</div>

<div class="controlbar"><div class="box-bc"><div class="box-ml"><div class="box-mr"><div class="box-tc"><div class="box-bl"><div class="box-br"></div></div></div></div></div></div></div>

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
        padding: 10px 20px;
        margin: 20px;
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