;(function ($) {
	var elem = '#sidebar-container';
	
	var SideBar = {
		isDirty: false,
		pendingLoad: null,
		currentItem: null,
		markDirty: function () {
			this.isDirty = true;
		},
		hide: function () {
			if (this.isDirty) {
				if (confirm("There may be unsaved changes, sure?")) {
					this.isDirty = false;
				}
			}
			if (!this.isDirty) {
				$(elem).empty();
				this.currentItem = null;
				$(elem).animate({width: '0px'},350);
				if (this.pendingLoad) {
					this.load(this.pendingLoad);
					this.pendingLoad = null;
				}
			}
		},
		show: function () {
			$(elem).show();
			var width = '33%';
			if ($(document).width() < 600) {
				width = '90%';
			}
			$(elem).animate({width: width},350);
		},
		load: function (url, item) {
			if (this.isDirty) {
				this.pendingLoad = url;
				return;
			} 
			this.currentItem = item;
			var self = this;
			$.get(url).success(function (data) {
				self.setContent(data);
				SideBar.show();
			});
		}, 
		setContent: function (content) {
			this.isDirty = false;
			$(elem).empty();
			$(elem).html(content);
		}
	};

	$(document).on('click', '.main', function (e) {
		if ($(elem).width() > 10) {
			SideBar.hide();
		}
	});
	
	var formFields = [
		elem + ' select', 
		elem + ' input',
		elem + ' textarea',
	];
	
	$(document).on('click', formFields.join(','), function (e) {
		SideBar.markDirty();
	})
	
	$(document).on('click', '.sidebar-edit-trigger', function (e) {
		e.preventDefault();
		var url = $(this).attr('href');
		var sidebarItem = $(this).attr('data-sidebaritem');
		SideBar.load(url, sidebarItem);
	})
	
	$(document).on('submit', elem + ' form', function (e) {
		e.preventDefault();
		var form = $(this);
		form.find('input[type=submit]').prop('disabled', true);
		$(this).ajaxSubmit({
			error: function (data) {
				alert("There was an error saving the form");
				form.find('input[type=submit]').prop('disabled', false);
			},
			success: function (data) {
				form.find('input[type=submit]').prop('disabled', false);
				if (data && data.form) {
					SideBar.setContent(data.form);
					$(document).trigger('sidebar.itemSaved', {ID: data.id, Type: data.class});
				}
			}
		});
		
		
		return false;
	})

	$.extend(true, window.WiTrack, {
		SideBar: SideBar
	});
	
	$(function () {
		if ($(elem).length == 0) {
			$('<div>').attr('id', 'sidebar-container').appendTo('body');
		}
	})
	
})(jQuery);