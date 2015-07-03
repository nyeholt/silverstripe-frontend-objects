;(function ($) {
	$('tr[data-itemid]').entwine({
		onmatch: function () {
			var title = $(this).find('td.Title');
		}
	})
	
	var loadList = function (listObj, pagination) {
		var url = 'frontend-admin/model/itemlist/showlist/' + listObj.ID;
		var listContainer = '#' + listObj.Type + listObj.ID;
		
		if ($(listContainer).attr('data-listlink')) {
			url = $(listContainer).attr('data-listlink');
		}
		var params = {};
		if (pagination) {
			url += pagination;
		}
		$.get(url).done(function (data) {
			// replace the table
			$(listContainer).replaceWith(data);
		});
	};
	
	$(document).on('itemtable.paginate', function (event, data) {
		
	});
	
	$(document).on('click', '.pagination-controls a', function (e) {
		e.preventDefault();
		var list = $(this).parents('div.item-list').data('object');
		var pagination = $(this).attr('href');
		pagination = pagination.substring(pagination.indexOf('?'));
		loadList(list, pagination);
		
		return false;
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
				loadList(list);
			}
		}
	});
})(jQuery);