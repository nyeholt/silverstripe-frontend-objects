
<div class="item-list" id="$ClassName$ID" data-object='{"ID": $ID, "Type": "$ClassName"}'>
	<h3>
		$Title
	</h3>
	<% if canEdit %>
	<a href='frontend-admin/model/$ClassName/edit/$ID' class='sidebar-edit-trigger' data-sidebaritem="$ClassName,$ID">
	<i class="fi-widget"> </i>
	<% end_if %>
	</a>
<table>
	<thead>
		<tr>
		<% loop $tableHeaders %>
		<th>$Label</th>
		<% end_loop %>
		</tr>
	</thead>

	<tbody>
		<% loop $getItems %>
		<tr data-itemid="$ID" class="item-list-row item-$ClassName" data-object='{"ID": $ID, "Type": "$ClassName"}'>
			<% loop $Values %>
			<td class="$Label.ATT">
				<% if $LinkField %>
					<% if $Up.Item.TableEdit %>
					<a href='frontend-admin/model/$Up.ClassName/edit/$Up.ID' class='sidebar-edit-trigger' data-sidebaritem="$Up.ClassName,$Up.ID">$Value</a>
					<% else %>
					<a href='$Up.Item.Link'>$Value</a>
					<% end_if %>
				<% else_if $ActionsField %>
				<ul class="button-group round even-{$Value.count}">
				<% loop $Value %>
					<li>
					<% if $html %>
					$html
					<% else %>
					<a href='#' class='item-list-action $action $classes.ATT button tiny'>$label</a>
					<% end_if %>
					</li>
				<% end_loop %>
				</ul>
				<% else %>
				$Value
				<% end_if %>
			</td>
			<% end_loop %>
		</tr>
		<% end_loop %>

	</tbody>

</table>
</div>