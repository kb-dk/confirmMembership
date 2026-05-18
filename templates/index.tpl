<!DOCTYPE html>
<html lang="{$currentLocale|replace:"_":"-"}" xml:lang="{$currentLocale|replace:"_":"-"}">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>{translate key="plugins.generic.confirmmembership.pagetitle"}</title>

	{load_header context="backend"}
	{load_stylesheet context="backend"}

	<link rel="stylesheet" href="https://cdn.datatables.net/2.0.6/css/dataTables.dataTables.css" />
	<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
	<script src="https://cdn.datatables.net/2.0.6/js/dataTables.js"></script>

	<script>
		$(document).ready(function() {
			$('#confirmmebership').DataTable({
				"paging": false,
				"info": false,
				"searching": false,
				"order": [],
				"columnDefs": [{
					"targets": 'no-sort',
					"orderable": false,
				}]
			});
		});
	</script>
</head>
<body class="pkp_page_admin">
<div class="app__page">
	<div class="app__contentPanel">
		<h1 class="app__pageHeading">
			{translate key="plugins.generic.confirmmembership.pagetitle"}
		</h1>

		<div class="app__contentPanel__body">
			<p>{translate key="plugins.generic.confirmmembership.total" total=$total}</p>

			<table id="confirmmebership" class="table stripe dataTable">
				<thead>
				<tr>
					<th class="no-sort">{translate key="plugins.generic.confirmmembership.date"}</th>
					<th class="sorting">{translate key="plugins.generic.confirmmembership.name"}</th>
					<th class="sorting">{translate key="plugins.generic.confirmmembership.username"}</th>
					<th class="sorting">{translate key="plugins.generic.confirmmembership.email"}</th>
					<th class="sorting">{translate key="plugins.generic.confirmmembership.role"}</th>
					<th style="min-width: 400px;">{translate key="plugins.generic.confirmmembership.assignment"}</th>
					<th class="sorting">{translate key="plugins.generic.confirmmembership.journals"}</th>
					<th class="sorting">{translate key="plugins.generic.confirmmembership.subscriber"}</th>
					<th class="no-sort">{translate key="plugins.generic.confirmmembership.link"}</th>
					<th class="no-sort"></th>
				</tr>
				</thead>
				<tbody>
				{foreach from=$users item="user"}
					<tr>
						<td>{$user['date']}</td>
						<td>{$user['name']|escape}</td>
						<td>{$user['username']|escape}</td>
						<td>{$user['email']|escape}</td>
						<td>{$user['role']|escape}</td>
						<td>
							{if !empty($user['assignment'])}
								{foreach $user['assignment'] as $ass}
									<a href="{$ass['url']|escape}" target="_blank">
										{$ass['date']|escape}
									</a>
									{if $ass['review']}
										<span>{translate key="plugins.generic.confirmmembership.review"}</span>
									{/if}
									 
								{/foreach}
							{/if}
						</td>
						<td>{$user['journals']|escape}</td>
						<td>
							{if $user['subscriber']}
								<span>{translate key="plugins.generic.confirmmembership.subscriber"}</span>
							{/if}
						</td>
						<td>
							{if $user['link']}
								<a href="{$user['link']|escape}" target="_blank">{translate key="plugins.generic.confirmmembership.link"}</a>
							{/if}
						</td>
						<td>
							<form class="pkp_form" id="mergesUser_{$user['userid']}" onsubmit="return confirm('{$mergesUser|escape:'javascript'}');" method="post" action="{$smarty.server.REQUEST_URI|escape}">
								<input type="hidden" name="userid" value="{$user['userid']|escape}" />
								<button type="submit" class="pkp_button pkp_button_primary">
									{translate key="plugins.generic.confirmmembership.merges"}
								</button>
							</form>
						</td>
					</tr>
				{/foreach}
				</tbody>
			</table>

			<div class="pkp_helpers_align_right" style="margin-top: 20px;">
				{if $show != $next}
					<a href="{url previous=$next}" class="pkp_button">
						{translate key="plugins.generic.confirmmembership.previous"}
					</a>
				{/if}
				{if $total > $next}
					<a href="{url next=$next}" class="pkp_button">
						{translate key="plugins.generic.confirmmembership.next"}
					</a>
				{/if}
			</div>
		</div>
	</div>
</div>
</body>
</html>
