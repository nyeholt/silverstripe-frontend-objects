
<div class="item-list list-of-$ItemType" id="$ClassName$ID" data-object='{"ID": $ID, "Type": "$ClassName"}' data-listlink="$Link">
	<h3>
		$Title
	</h3>
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
				<% if $ActionsField %>
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
		<tr>
			<td  colspan="$tableHeaders.count" class="pagination-controls">
				<% if $getItems.MoreThanOnePage %>
					<% if $getItems.NotFirstPage %>
						<a class="prev" href="$getItems.PrevLink">Prev</a>
					<% end_if %>
					<% loop $getItems.Pages %>
						<% if $CurrentBool %>
						<span class="item-list-current-page">$PageNum</span>
						<% else %>
							<% if $Link %>
								<a href="$Link">$PageNum</a>
							<% else %>
								...
							<% end_if %>
						<% end_if %>
						<% end_loop %>
					<% if $getItems.NotLastPage %>
						<a class="next" href="$getItems.NextLink">Next</a>
					<% end_if %>
				<% end_if %>
				
			</td>
		</tr>
	</tbody>

</table>
</div>
