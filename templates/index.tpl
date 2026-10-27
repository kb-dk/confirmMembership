<!DOCTYPE html>
<html lang="{$currentLocale|replace:"_":"-"}" xml:lang="{$currentLocale|replace:"_":"-"}">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>{translate key="plugins.generic.confirmmembership.pagetitle"}</title>

	{load_header context="backend"}
	{load_stylesheet context="backend"}

	<link rel="stylesheet" href="https://cdn.datatables.net/2.0.6/css/dataTables.dataTables.css" />
	<link rel="stylesheet" href="{$pluginCssUrl|escape}" />
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
	<div class="app__contentPanel cm-page">
		<h1 class="app__pageHeading">
			{translate key="plugins.generic.confirmmembership.pagetitle"}
		</h1>

		<div class="app__contentPanel__body">
			<div class="table-responsive">
				<table id="confirmmebership" class="table table-hover">
					<caption>{translate key="plugins.generic.confirmmembership.total" total=$total}</caption>
					<thead>
					<tr>
						<th scope="col">{translate key="plugins.generic.confirmmembership.date"}</th>
						<th scope="col">{translate key="plugins.generic.confirmmembership.name"}</th>
						<th scope="col">{translate key="plugins.generic.confirmmembership.username"}</th>
						<th scope="col">{translate key="plugins.generic.confirmmembership.email"}</th>
						<th scope="col">{translate key="plugins.generic.confirmmembership.role"}</th>
						<th scope="col" class="no-sort" style="min-width: 300px;">{translate key="plugins.generic.confirmmembership.assignment"}</th>
						<th scope="col">{translate key="plugins.generic.confirmmembership.journals"}</th>
						<th scope="col">{translate key="plugins.generic.confirmmembership.subscriber"}</th>
						<th scope="col" class="no-sort">{translate key="plugins.generic.confirmmembership.link"}</th>
						<th scope="col" class="no-sort"><span class="pkp_screen_reader">{translate key="plugins.generic.confirmmembership.merges"}</span></th>
					</tr>
					</thead>
					<tbody>
					{foreach from=$users item="user"}
						<tr>
							<td class="cm-nowrap">{$user['date']|escape}</td>
							<td>{$user['name']|escape}</td>
							<td>{$user['username']|escape}</td>
							<td>{$user['email']|escape}</td>
							<td>{$user['role']|escape}</td>
							<td>
								{if !empty($user['assignment'])}
									{capture assign="assignmentList"}
										<ul class="cm-assignments">
											{foreach $user['assignment'] as $ass}
												<li>
													<a href="{$ass['url']|escape}" target="_blank">{$ass['date']|escape}</a>
													{if $ass['review']}
														<span class="badge">{translate key="plugins.generic.confirmmembership.review"}</span>
													{/if}
												</li>
											{/foreach}
										</ul>
									{/capture}
									{if $user['assignment']|@count > 6}
										<details>
											<summary>{translate key="plugins.generic.confirmmembership.showall" count=$user['assignment']|@count}</summary>
											{$assignmentList}
										</details>
									{else}
										{$assignmentList}
									{/if}
								{/if}
							</td>
							<td>
								{if $user['journals'] === 'none'}
									<span class="cm-muted">–</span>
								{else}
									{$user['journals']|escape}
								{/if}
							</td>
							<td>
								{if $user['subscriber']}
									<span class="badge">{translate key="plugins.generic.confirmmembership.subscriber"}</span>
								{/if}
							</td>
							<td class="cm-nowrap">
								{if $user['link']}
									<a href="{$user['link']|escape}" target="_blank">{translate key="plugins.generic.confirmmembership.link"}</a>
								{/if}
							</td>
							<td>
								<form id="mergesUser_{$user['userid']}" onsubmit="return confirm('{$mergesUser|escape:'javascript'}');" method="post" action="{$smarty.server.REQUEST_URI|escape}">
									<input type="hidden" name="userid" value="{$user['userid']|escape}" />
									<button type="submit" class="btn">
										{translate key="plugins.generic.confirmmembership.merges"}
									</button>
								</form>
							</td>
						</tr>
					{/foreach}
					</tbody>
				</table>
			</div>

			<div class="cm-pagination">
				{if $show != $next}
					<a href="{url previous=$next}" class="btn">
						{translate key="plugins.generic.confirmmembership.previous"}
					</a>
				{/if}
				{if $total > $next}
					<a href="{url next=$next}" class="btn btn-primary">
						{translate key="plugins.generic.confirmmembership.next"}
					</a>
				{/if}
			</div>
		</div>
	</div>
</div>
</body>
</html>
