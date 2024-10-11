{extends file="layouts/backend.tpl"}
{block name="page"}
    <link rel="stylesheet" href="https://cdn.datatables.net/2.0.6/css/dataTables.dataTables.css" />
    <script src="https://cdn.datatables.net/2.0.6/js/dataTables.js"></script>
    <script>
        $(document).ready( function () {
            $('#confirmmebership').DataTable({
                "paging":   false,
                "info":  false,
                "bFilter": false
            });
        } );
    </script>

    <h1 class="app__pageHeading">
        {translate key="plugins.generic.confirmmembership.pagetitle"}
    </h1>
    <div><p>{translate key="plugins.generic.confirmmembership.total" total=$total}</p></div>
    <table id="confirmmebership"  class="table stripe dataTable">
        <thead>
            <tr>
                <th class="sorting">{translate key="plugins.generic.confirmmembership.date"}</th>
                <th class="sorting">{translate key="plugins.generic.confirmmembership.name"}</th>
                <th class="sorting">{translate key="plugins.generic.confirmmembership.username"}</th>
                <th class="sorting">{translate key="plugins.generic.confirmmembership.email"}</th>
                <th class="sorting">{translate key="plugins.generic.confirmmembership.role"}</th>
                <th style="min-width: 400px;">{translate key="plugins.generic.confirmmembership.assignment"}</th>
                <th class="sorting">{translate key="plugins.generic.confirmmembership.journals"}</th>
                <th class="sorting">{translate key="plugins.generic.confirmmembership.subscriber"}</th>
                <th class="">{translate key="plugins.generic.confirmmembership.link"}</th>
                <th ></th>
            </tr>
        </thead>
        <tbody>
         {foreach from=$users item="user"}
            <tr>
                <td>{$user['date']}</td>
                <td>{$user['name']}</td>
                <td>{$user['username']}</td>
                <td>{$user['email']}</td>
                <td>{$user['role']}</td>
                <td>
                    {if !empty($user['assignment'])}
                        {foreach $user['assignment'] as $ass}
                          <a href="{$ass['url']}" target="_blank">
                             {$ass['date']}

                          </a>
                            {if $ass['review'] }
                                <span>{translate key="plugins.generic.confirmmembership.review"}</span>
                            {/if}
                            &nbsp;
                        {/foreach}
                    {/if}
                </td>
                <td>{$user['journals']}</td>
                <td>
                    {if $user['subscriber'] }
                        <p>{translate key="plugins.generic.confirmmembership.subscriber"}</p>
                    {/if}
                </td>
                <td>
                    {if $user['link'] }
                        <a href="{$user['link']}" target="_blank">{translate key="plugins.generic.confirmmembership.link"}</a>
                    {/if}
                </td>
                 <td>
                     <form class="pkp_form" id="mergesUser_{$user['userid']} " onSubmit="return confirm('{$mergesUser}')" method="post" action="{{$smarty.server.REQUEST_URI}}"> {fbvElement type="hidden" id="userid" value={$user['userid']}}{fbvElement type="submit"  label="plugins.generic.confirmmembership.merges" id="suggestUsernameButton" inline=true class="default"}
                     </form>
                 </td>
            </tr>
        {/foreach}
        </tbody>
    </table>
    {if $total > $next}
        <div><a href="{url next=$next}">{translate key="plugins.generic.confirmmembership.next"}</a> </div>
    {/if}
    {if $show != $next}
        <div><a href="{url previous=$next}">{translate key="plugins.generic.confirmmembership.previous"}</a></div>
    {/if}
{/block}