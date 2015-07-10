;(function ($) {
	$('tr[data-itemid]').entwine({
		onmatch: function () {
			var title = $(this).find('td.Title');
		}
	})
	
	var loadList = function (listObj, pagination) {
		var listContainer = listObj;
		var url = '';
		if (!listObj.length) {
			listContainer = $('#' + listObj.Type + listObj.ID);
			url = 'frontend-admin/model/itemlist/showlist/' + listObj.ID;
		}
		if (listContainer.attr('data-listlink')) {
			url = listContainer.attr('data-listlink');
		}

		var params = {};
		if (pagination) {
			url += pagination;
		}
		listContainer.addClass('loadingList');
		$.get(url).done(function (data) {
			// replace the table
			listContainer.replaceWith(data);
			listContainer.removeClass('loadingList');
		});
	};
	
	$(document).on('itemtable.paginate', function (event, data) {
		
	});
	
	$(document).on('click', '.pagination-controls a', function (e) {
		e.preventDefault();
		var pagination = $(this).attr('href');
		pagination = pagination.substring(pagination.indexOf('?'));
		var list = $(this).parents('div.item-list');
		loadList(list, pagination);
		
		return false;
	})
	
	$(document).on('sidebar.itemSaved', function (event, data) {
		if (data && data.ID) {
			var context = [];
			if (data.class == 'ItemList') {
				context.push(data);
			} else {
				// we've got an item that may be inside a list, so lets find 
				// the data we're looking for as a tr
				var selector = '.item-list.list-of-' + data.Type;
				var items = $(selector);
				if (items.length > 0) {
					items.each (function () {
						context.push($(this));
					});
				} 
			}
			for (var i in context) {
				var list = context[i];
				// close over the listId variable
				loadList(list);
			}
		}
	});
})(jQuery);