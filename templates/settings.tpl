<div id="confirmMembershipSettings">
<script>
	$(function() {ldelim}
		$('#confirmMembershipPluginSettings').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
		{rdelim});
</script>

<form class="pkp_form" id="confirmMembershipPluginSettings" method="POST"  action="{url router=$smarty.const.ROUTE_COMPONENT op="manage" category="generic" plugin=$pluginName verb="settings" save=true}">
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
			{fbvElement type="text" id="daysmerged" value=$daysmerged label="plugins.generic.confirmmembership.daysmerged" }
		{/fbvFormSection}
		{fbvFormSection}
			{fbvElement type="text" id="mergeusername" value=$mergeusername label="plugins.generic.confirmmembership.mergeusername" }
		{/fbvFormSection}

	{fbvFormSection list="true"}

	{if $test}
		{assign var="checked" value=true}
	{else}
		{assign var="checked" value=false}
	{/if}

			{fbvElement	type="checkbox" id="test" value="1" checked=$checked  label="plugins.generic.confirmmembership.test" }
		{/fbvFormSection}
		{fbvFormSection}
			{fbvElement type="text" id="testemails" value=$testemails label="plugins.generic.confirmmembership.testemails" }
	    {/fbvFormSection}
	{fbvFormSection}
	{fbvElement type="text" id="amountofusers" value=$amountofusers label="plugins.generic.confirmmembership.amountofusers" }
	{/fbvFormSection}
	{/fbvFormArea}
	{fbvFormButtons submitText="common.save"}
	<script>
		function resetCanNotDeleteClick() {
			$form = $('#confirmMembershipSettings form');
			$form.append('<input type="hidden" name="delecteUserSetting" value="true" />');
			return true;
		}
	</script>
	<input type="submit" name="resetnotdeleted" value="{translate key="plugins.generic.confirmmembership.resetusernotdeleted.submit"}" onclick="resetCanNotDeleteClick()" class="action" />
	<p>{translate key="plugins.generic.confirmmembership.resetusernotdeleted"}</p><br/>
</form>
	<a href="/index.php/index/deleteusers">{translate key="plugins.generic.confirmmembership.deleteuserlink"}</a>
</div>