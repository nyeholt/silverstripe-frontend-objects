;(function ($) {
	$('tr[data-itemid]').entwine({
		onmatch: function () {
			var title = $(this).find('td.Title');
		}
	})
	
	$(document).on('sidebar.itemSaved', function (event, data) {
		if (data && data.ID) {
			var context = [];
			if (data.class == 'ItemList') {
				context.push(data);
			} else {
				// we've got an item that's inside a list, so lets find 
				// the data we're looking for as a tr
				var selector = 'tr.item-list-row.item-' + data.Type + '[data-itemid=' + data.ID +']';
				var items = $(selector);
				if (items.length > 0) {
					items.each (function () {
						var parentElem = $(this).parents('div.item-list');
						if (parentElem.length == 1) {
							context.push(parentElem.data('object'));
						}
					});
				}
			}
			for (var i in context) {
				var list = context[i];
				$('#' + list.Type + list.ID).addClass('loadingList');
				// close over the listId variable
				(function (listObj) {
					var url = 'frontend-admin/model/itemlist/showlist/' + listObj.ID;
					$.get(url).done(function (data) {
						// replace the table
						$('#' + listObj.Type + listObj.ID).replaceWith(data);
						
					});
				})(list);
				
			}
		}
	});
})(jQuery);