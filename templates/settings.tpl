<script>
	$(function() {ldelim}
		$('#confirmMembershipPluginSettings').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
		{rdelim});
</script>

<form
		class="pkp_form"
		id="confirmMembershipPluginSettings"
		method="POST"
		action="{url router=$smarty.const.ROUTE_COMPONENT op="manage" category="generic" plugin=$pluginName verb="settings" save=true}"
>
	{csrf}

	{fbvFormArea}
		{fbvFormSection}
			{fbvElement type="text" id="roleids" value=$roleids label="plugins.generic.confirmmembership.roleids" }
		{/fbvFormSection}
		{fbvFormSection}
			{fbvElement type="text" id="maxusers" value=$maxusers label="plugins.generic.confirmmembership.maxusers" }
		{/fbvFormSection}
		{fbvFormSection}
			{fbvElement type="text" id="daysmail" value=$daysmail label="plugins.generic.confirmmembership.daysmail" }
		{/fbvFormSection}
		{fbvFormSection}
			{fbvElement type="text" id="daysdisabled" value=$daysdisabled label="plugins.generic.confirmmembership.daysdisabled" }
		{/fbvFormSection}
		{fbvFormSection}
			{fbvElement type="text" id="daysmerged" value=$daysdisabled label="plugins.generic.confirmmembership.daysmerged" }
		{/fbvFormSection}
		{fbvFormSection}
			{fbvElement type="text" id="mergeusername" value=$mergeusername label="plugins.generic.confirmmembership.mergeusername" }
		{/fbvFormSection}
	{/fbvFormArea}
	{fbvFormButtons submitText="common.save"}
</form>
