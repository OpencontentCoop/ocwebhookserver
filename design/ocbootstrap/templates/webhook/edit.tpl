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
                        <input {if $trigger.can_enabled|not}disabled="disabled"{/if} type="checkbox" name="triggers[{$trigger.identifier}]" value="1"
                               {if is_set($webhook_triggers[$trigger.identifier])}checked="checked"{/if} />
                        <strong>{$trigger.name|wash()}</strong>
                        <br /><small>{$trigger.description|wash()|autolink()}</small>
                    </label>
                    {if $trigger.use_filter}
                        {if and(is_set($webhook_triggers[$trigger.identifier]), $webhook_triggers[$trigger.identifier].filters|ne(''))}
                            <a style="margin-left: 20px" href="#" data-schema='{$trigger.use_filter}' data-trigger="{$trigger.identifier}" class="btn btn-primary btn-xs">{"Edit filters"|i18n( 'extension/ocwebhookserver' )}</a>
                        {else}
                            <a style="margin-left: 20px" href="#" data-schema='{$trigger.use_filter}' data-trigger="{$trigger.identifier}" class="btn btn-default btn-xs">{"Set filters"|i18n( 'extension/ocwebhookserver' )}</a>
                        {/if}
                        <input type="hidden" data-filter_value="{$trigger.identifier}" value="{if is_set($webhook_triggers[$trigger.identifier])}{$webhook_triggers[$trigger.identifier].filters|wash()}{/if}" name="trigger_filters[{$trigger.identifier}]" />
                    {/if}
                </div>
                {delimiter modulo=2}</div><div class="row">{/delimiter}
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


{ezscript_require(array(
    'ezjsc::jquery',
    'ezjsc::jqueryUI',
    'moment-with-locales.min.js',
    'handlebars.min.js',
    'bootstrap-datetimepicker.min.js',
    'alpaca.min.js'
))}
{ezcss_load(array(
    'alpaca.min.css',
    'bootstrap-datetimepicker.min.css'
))}
<div id="modal" class="modal fade">
	<div class="modal-dialog modal-lg">
		<div class="modal-content">
			<div class="modal-body">
				<div class="clearfix">
					<button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
				</div>
				<div id="filters-form" class="clearfix"></div>
			</div>
		</div>
	</div>
</div>

{literal}
<script type="text/javascript">
    $(document).ready(function () {
        var modal = $('#modal');
        $('[data-trigger]').on('click', function (e) {
            modal.modal('show');
            var self = $(this);
            var trigger = self.data('trigger');
            var input = $('[data-filter_value="'+trigger+'"]');

            $("#filters-form").alpaca('destroy').alpaca($.extend(true, $(this).data('schema'),{
                "data": input.val().length > 0 ? JSON.parse(input.val()) : null,
                "options": {
                    "form": {
                        "buttons": {
                            "submit": {
                                "click": function() {
                                    this.refreshValidationState(true);
                                    if (this.isValid(true)) {
                                        var value = this.getValue();
                                        if ($.isEmptyObject(value)){
                                            self.html('{/literal}{"Set filters"|i18n('extension/ocwebhookserver')}{literal}').addClass('btn-default').removeClass('btn-primary');
                                            input.val('');
                                        }else{
                                            self.html('{/literal}{"Edit filters"|i18n('extension/ocwebhookserver')}{literal}').addClass('btn-primary').removeClass('btn-default');
                                            input.val(JSON.stringify(value));
                                        }
                                        modal.modal('hide');
                                    }
                                },
                                'id': "save-button",
                                "value": "Save",
                                "styles": "btn btn-md btn-success pull-right"
                            }
                        }
                    }
                }
            }));
            e.preventDefault();
        });
    });
</script>
{/literal}