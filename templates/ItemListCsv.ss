<% loop $tableHeaders %><% if $ActionsField %><% else %>"$Label.NoHTML.raw"<% if $Last %><% else %>,<% end_if %><% end_if %><% end_loop %>
<% loop $getItems %>
<% loop $Values %><% if $ActionsField %><% else %>"$Value.NoHTML.raw"<% if $Last %><% else %>,<% end_if %><% end_if %><% end_loop %>
<% end_loop %>

