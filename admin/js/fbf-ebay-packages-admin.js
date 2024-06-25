(function( $	 ) {
	'use strict';

	/**
	 * All of the code for your admin-facing JavaScript source
	 * should reside in this file.
	 *
	 * Note: It has been assumed you will write jQuery code here, so the
	 * $ function reference has been prepared for usage within the scope
	 * of this function.
	 *
	 * This enables you to define handlers, for when the DOM is ready:
	 *
	 * $(function() {
	 *
	 * });
	 *
	 * When the window is loaded:
	 *
	 * $( window ).load(function() {
	 *
	 * });
	 *
	 * ...and/or other possibilities.
	 *
	 * Ideally, it is not considered best practise to attach more than a
	 * single DOM-ready or window-load handler for a particular page.
	 * Although scripts in the WordPress core, Plugins and Themes may be
	 * practising this, we should strive to set a better example in our own work.
	 */

	$(function() {

		$('#fbf_ebay_packages_add_package').find('.acf-input input[type=text], .acf-input input[type=number]').removeAttr('required');
		$('#fbf_ebay_packages_add_package').on('submit', function(e){
			console.log('form submited');
			let valid = $('#fbf_ebay_packages_valid').val();
			if(valid!=='yes'){
				e.preventDefault();
			}
			let $form = $(this);

			// Clear all errors
			let fields = acf.getFields();
			$(fields).each(function(fi, fv){
				fv.removeNotice();
			});

			let nonce = $('#fbf_ebay_packages_nonce').val();
			let form_data = $(this).serializeArray();

			console.log(nonce);

			if(valid!=='yes'){
				doAjax(nonce, form_data).then(function(data){
					console.log('data:');
					console.log(data.data);
					if(data.data.errors.length){
						$(data.data.errors).each(function(i, v){
							let input = v.input;
							let message = v.message;
							console.log(input);
							console.log(message);
							let regex = /^acf\[(.*?)]$/;
							let match = input.match(regex);
							let field = acf.getField(match[1]);

							field.showNotice({
								text: message,
								type: 'error',
								dismiss: false,
							});

							$('html, body').animate({
								scrollTop: $('.acf-error-message:visible:first').offset().top - 32
							}, 1000);
						});
					}else{
						$('#fbf_ebay_packages_valid').val('yes')
						$form.submit();
					}
				});
			}
		});

		async function doAjax(nonce, form_data) {
			let result;
			let data = {
				nonce: nonce,
				action: "acf/validate_save_post",
			};

			for (const i in form_data) {
				//
				//console.log(form_data[i].name);
				//console.log(form_data[i].value);

				if(form_data[i].name.includes('acf[field_')){
					data[form_data[i].name] = form_data[i].value;
				}
			}

			try {
				result = await $.ajax({
					url: ajax_object.ajax_url,
					type: 'POST',
					data: data,
				});

				return result;
			} catch (error) {
				//console.error(error);
			}
		}

		// multiple select with AJAX search
		$('#tyre_brands, #wheel_brands').select2({
			ajax: {
				url: fbf_ebay_packages_admin.ajax_url, // AJAX URL is predefined in WordPress admin
				dataType: 'json',
				delay: 250, // delay in ms while typing when to perform a AJAX search
				data: function (params) {
					console.log(params);
					return {
						q: params.term, // search query
						action: 'fbf_ebay_packages_get_brands', // AJAX action for admin-ajax.php
						ajax_nonce: fbf_ebay_packages_admin.ajax_nonce
					};
				},
				processResults: function( data ) {
					var options = [];
					if ( data ) {

						// data is the array of arrays, and each of them contains ID and the Label of the option
						$.each( data, function( index, text ) { // do not forget that "index" is just auto incremented value
							options.push( { id: text[0], text: text[1]  } );
						});

					}
					return {
						results: options
					};
				},
				cache: true
			},
			minimumInputLength: 3 // the minimum of symbols to input before perform a search
		});
		$('#wheel_manufacturers').select2({
			ajax: {
				url: fbf_ebay_packages_admin.ajax_url, // AJAX URL is predefined in WordPress admin
				dataType: 'json',
				delay: 250, // delay in ms while typing when to perform a AJAX search
				data: function (params) {
					console.log(params);
					return {
						q: params.term, // search query
						action: 'fbf_ebay_packages_get_manufacturers', // AJAX action for admin-ajax.php
						ajax_nonce: fbf_ebay_packages_admin.ajax_nonce
					};
				},
				processResults: function( data ) {
					var options = [];
					if ( data ) {

						// data is the array of arrays, and each of them contains ID and the Label of the option
						$.each( data, function( index, text ) { // do not forget that "index" is just auto incremented value
							options.push( { id: text[0], text: text[1]  } );
						});

					}
					return {
						results: options
					};
				},
				cache: true
			},
			minimumInputLength: 3 // the minimum of symbols to input before perform a search
		});

		package_chassis_select2_init();
		package_wheel_select2_init();
		package_tyre_select2_init();
		package_nut_bolt_select2_init();

		$('#package_desc, #package_name').bind('blur focus keyup change', function(){
			check_package_vals();
		});

		// datatable
		let $table = $('#example');
		let type = $table.attr('data-type');
		let table = $table.DataTable({
			serverSide: true,
			processing: true,
			searchDelay: 700,
			ajax: {
				url: fbf_ebay_packages_admin.ajax_url,
				type: 'POST',
				data: {
					//action: 'fbf_ebay_packages_ebay_listing'
					action: 'fbf_ebay_packages_tyre_table',
					type: type
				}
			},
			language: {
				processing: "<span class='dataTable-loader' style='margin-top: 2.48em;'></span>"
			},
			columns: [
				{ data: 'name', rowId: 'l_id' },
				{ data: 'sku' },
				{ data: 'qty' },
				{ data: 'l_id' },
				{
					"className":      'details-control',
					"orderable":      false,
					"data":           null,
					"defaultContent": '<a href="#" class="dashicons dashicons-arrow-down-alt2"></a>'
				},
			],
			columnDefs: [
				{
					targets: 0,
					render: function ( data, type, row, meta ) {
						let url = '<a href="/wp/wp-admin/post.php?post='+row.post_id+'&action=edit">'+data+'</a>';
						return url;
					}
				},{
					targets: 3,
					render: function ( data, type, row, meta ) {
						if(data){
							return '<a href="https://www.ebay.co.uk/itm/'+data+'" style="text-decoration: none;" target="_blank">'+data+'<span class="dashicons dashicons-external" style="position: relative; top: -2px;"></span></a>';
						}else{
							return ''
						}
					}
				}
			],
		});

		// Add event listener for opening and closing details
		$('#example tbody').on('click', 'td.details-control a', function () {
			var tr = $(this).closest('tr');
			var row = table.row( tr );
			var $icon = $(this);
			console.log($icon);

			if( row.child.isShown() ) {
				// This row is already open - close it
				row.child.hide();
				tr.removeClass('shown');
				$icon.removeClass('dashicons-arrow-up-alt2').addClass('dashicons-arrow-down-alt2');
			}else{
				// Open this row
				row.child(format(row.data())).show();
				tr.addClass('shown');
				$icon.removeClass('dashicons-arrow-down-alt2').addClass('dashicons-arrow-up-alt2');
			}
			return false;
		});

		$('#dt_packages tbody').on('click', 'td.details-control a', function () {
			var tr = $(this).closest('tr');
			var row = table.row( tr );
			var $icon = $(this);
			console.log($icon);

			if( row.child.isShown() ) {
				// This row is already open - close it
				row.child.hide();
				tr.removeClass('shown');
				$icon.removeClass('dashicons-arrow-up-alt2').addClass('dashicons-arrow-down-alt2');
			}else{
				// Open this row
				row.child.show();
				tr.addClass('shown');
				$icon.removeClass('dashicons-arrow-down-alt2').addClass('dashicons-arrow-up-alt2');
			}
			return false;
		});

		let log = $('#fbf_ep_event_log_table').DataTable({
			paging: false,
			searching: false,
			ordering: false,
			info: false,
			serverSide: true,
			processing: true,
			ajax: {
				url: fbf_ebay_packages_admin.ajax_url,
				type: 'POST',
				data: {
					//action: 'fbf_ebay_packages_ebay_listing'
					action: 'fbf_ebay_packages_event_log'
				}
			},
			language: {
				processing: "<span class='dataTable-loader' style='margin-top: 1em;'></span>"
			},
		});

		let log_detail = $('#fbf_ep_event_log_detail').DataTable({
			serverSide: true,
			processing: true,
			searchDelay: 700,
			ajax: {
				url: fbf_ebay_packages_admin.ajax_url,
				type: 'POST',
				data: {
					//action: 'fbf_ebay_packages_ebay_listing'
					action: 'fbf_ebay_packages_log_detail',
					listing_id: get('listing_id'),
				}
			},
			language: {
				processing: "<span class='dataTable-loader' style='margin-top: 2.48em;'></span>"
			},
			columns: [
				{ data: 'created' },
				{ data: 'action' },
				{ data: 'status' },
				{ data: 'response_code' },
				{
					"className":      'log-details-control',
					"orderable":      false,
					"data":           null,
					"defaultContent": '<a href="#" class="dashicons dashicons-arrow-down-alt2"></a>'
				}
			]
		});

		let $packages = $('#dt_packages');

		let packages = $packages.DataTable({
			serverSide: true,
			processing: true,
			ajax: {
				url: fbf_ebay_packages_admin.ajax_url,
				type: 'POST',
				data: {
					//action: 'fbf_ebay_packages_ebay_listing'
					action: 'fbf_ebay_packages_packages_table'
				}
			},
			language: {
				processing: "<span class='dataTable-loader' style='margin-top: 2.48em;'></span>"
			},
			order: [
				[1, 'desc']
			],
			columns: [
				{ data: 'name', rowId: 'l_id' },
				{ data: 'created' },
				{
					"className":      'details-control',
					"orderable":      false,
					"data":           null,
					"defaultContent": '<a href="#" class="dashicons dashicons-arrow-down-alt2"></a>'
				},
			]
		});



		// tyre select form submit
		$('#tyre-brand-select-form').on('submit', function(){
			console.log('tyre brand form submit');
			let thickbox_id = 'save-listings-thickbox';
			let $content = $('#' + thickbox_id + ' .tb-modal-content');
			console.log($content);
			let brands_selected = $('#tyre_brands').val();
			console.log(brands_selected);
			let url = '#TB_inline?&width=600&height=150&inlineId=' + thickbox_id;

			let data = {
				action: 'fbf_ebay_packages_brand_confirm',
				brands: brands_selected,
				ajax_nonce: fbf_ebay_packages_admin.ajax_nonce,
			};

			$.ajax({
				// eslint-disable-next-line no-undef
				url: fbf_ebay_packages_admin.ajax_url,
				type: 'POST',
				data: data,
				dataType: 'json',
				success: function (response) {
					let html = '';
					$content.empty();
					if(response.status==='success'){
						html+= '<p>' + response.message + '</p>';
					}else if(response.status==='error'){
						let errors = response.errors;
						$.each(errors, function(i, e){
							html+= '<p>' + e + '</p>';
						});
					}
					$content.append(html);
					tb_show('Tyre Listings', url);
				},
			});

			return false;
		});

		$('#wheel-save-brands').bind('click', function(){
			let brands_selected = $('#wheel_brands').val();
			let $notices = $('.wheel-notice');
			if($notices.length){
				$notices.each(function(){
					$(this).remove();
				});
			}

			let data = {
				action: 'fbf_ebay_packages_wheel_brands_confirm',
				ajax_nonce: fbf_ebay_packages_admin.ajax_nonce,
				brands: brands_selected,
			};

			$.ajax({
				// eslint-disable-next-line no-undef
				url: fbf_ebay_packages_admin.ajax_url,
				type: 'POST',
				data: data,
				dataType: 'json',
				success: function (response) {
					$('.wheel-meta-box-wrap').before('<div class="wheel-notice notice notice-' + response.status + ' is-dismissible"><p>' + response.msg + '</p><button id="my-dismiss-admin-message" class="notice-dismiss" type="button"><span class="screen-reader-text">Dismiss this notice.</span></button></div>');
					$("#my-dismiss-admin-message").click(function(event) {
						event.preventDefault();
						$('.wheel-notice').fadeTo(100, 0, function() {
							$('.wheel-notice').slideUp(100, function() {
								$('.wheel-notice').remove();
							});
						});
					});
				}
			});

			return false;
		});

		$('#wheel-save-manufacturers').bind('click', function (){
			let manufacturers_selected = $('#wheel_manufacturers').val();
			let $notices = $('.wheel-notice');
			if($notices.length){
				$notices.each(function(){
					$(this).remove();
				});
			}

			let data = {
				action: 'fbf_ebay_packages_wheel_manufacturers_confirm',
				ajax_nonce: fbf_ebay_packages_admin.ajax_nonce,
				manufacturers: manufacturers_selected,
			};

			$.ajax({
				// eslint-disable-next-line no-undef
				url: fbf_ebay_packages_admin.ajax_url,
				type: 'POST',
				data: data,
				dataType: 'json',
				success: function (response) {
					$('.wheel-meta-box-wrap').before('<div class="wheel-notice notice notice-' + response.status + ' is-dismissible"><p>' + response.msg + '</p><button id="my-dismiss-admin-message" class="notice-dismiss" type="button"><span class="screen-reader-text">Dismiss this notice.</span></button></div>');
					$("#my-dismiss-admin-message").click(function(event) {
						event.preventDefault();
						$('.wheel-notice').fadeTo(100, 0, function() {
							$('.wheel-notice').slideUp(100, function() {
								$('.wheel-notice').remove();
							});
						});
					});

					if(response.status==='success'){
						populate_chassis();
					}
				}
			});

			return false;
		});

		$('#wheel-save-chassis').bind('click', function(){
			console.log('save chassis');
			let $notices = $('.wheel-notice');
			if($notices.length){
				$notices.each(function(){
					$(this).remove();
				});
			}

			let chassis_data = {};
			let all_chassis_data = {};
			let $selects = $('.wheel-chassis-select');
			if($selects.length){
				$selects.each(function(key, value){
					let id = $(this).attr('data-id');
					chassis_data[id] = $(this).val();
					let saved_chassis = [];
					let $selected = $(this).find(':selected');
					//console.log($selected);
					$selected.each(function(skey, svalue){
						 let chassis = {
							id: $(this).val(),
							name: $(this).text(),
						};
						saved_chassis.push(chassis);
					});
					all_chassis_data[id] = saved_chassis;
				});
			}

			let data = {
				action: 'fbf_ebay_packages_save_chassis',
				ajax_nonce: fbf_ebay_packages_admin.ajax_nonce,
				chassis: chassis_data,
				all_chassis_data: all_chassis_data,
			};

			$.ajax({
				// eslint-disable-next-line no-undef
				url: fbf_ebay_packages_admin.ajax_url,
				type: 'POST',
				data: data,
				dataType: 'json',
				success: function (response) {
					$('.wheel-meta-box-wrap').before('<div class="wheel-notice notice notice-' + response.status + ' is-dismissible"><p>' + response.msg + '</p><button id="my-dismiss-admin-message" class="notice-dismiss" type="button"><span class="screen-reader-text">Dismiss this notice.</span></button></div>');
					$("#my-dismiss-admin-message").click(function(event) {
						event.preventDefault();
						$('.wheel-notice').fadeTo(100, 0, function() {
							$('.wheel-notice').slideUp(100, function() {
								$('.wheel-notice').remove();
							});
						});
					});

					console.log(response);
				}
			});

			return false;
		});

		$('#wheel-create-listings').bind('click', function(){
			let thickbox_id = 'save-listings-thickbox';
			let $content = $('#' + thickbox_id + ' .tb-modal-content');
			let url = '#TB_inline?&width=600&height=150&inlineId=' + thickbox_id;
			let $confirm = $('#fbf-ebay-packages-wheels-confirm-listing');
			$content.append('<p><span class="spinner is-active"></span>Getting Wheels... please wait</p>')
			tb_show('Wheel Listings', url);
			$('body').on('thickbox:removed', function(){
				$content.empty();
				$confirm.prop('disabled', true);
				$('body').unbind('thickbox:removed');
			});
			let data = {
				action: 'fbf_ebay_packages_wheel_create_listings',
				ajax_nonce: fbf_ebay_packages_admin.ajax_nonce,
			};
			$.ajax({
				// eslint-disable-next-line no-undef
				url: fbf_ebay_packages_admin.ajax_url,
				type: 'POST',
				data: data,
				dataType: 'json',
				success: function (response) {
					if(response.status==='success'){
						$content.empty().append('<p>'+response.wheel_count+' wheels found, please click confirm to create listings...</p>');
						$confirm.attr('data-listings', JSON.stringify(response.wheels_listings));
						$confirm.attr('data-chassis-lookup', JSON.stringify(response.chassis_lookup));
						$confirm.prop('disabled', false);
					}
				}
			});

			return false;
		});

		$('#fbf-ebay-packages-wheels-confirm-listing').bind('click', function(){
			console.log('Wheels confirm listing');
			let thickbox_id = 'save-listings-thickbox';
			let $content = $('#' + thickbox_id + ' .tb-modal-content');
			let $confirm = $('#fbf-ebay-packages-wheels-confirm-listing');

			let data = {
				action: 'fbf_ebay_packages_wheel_confirm_listings',
				listings: $confirm.attr('data-listings'),
				chassis_lookup: $confirm.attr('data-chassis-lookup'),
				ajax_nonce: fbf_ebay_packages_admin.ajax_nonce,
			};
			$.ajax({
				// eslint-disable-next-line no-undef
				url: fbf_ebay_packages_admin.ajax_url,
				type: 'POST',
				data: data,
				dataType: 'json',
				success: function (response) {
					if(response.status==='success'){
						console.log(response);
						$content.empty();
						$confirm.prop('disabled', true);

						// Close the thickbox
						tb_remove();

						// Refresh table
						table.search('');
						table.columns().search('');
						table.ajax.reload();
					}
				}
			});

			return false;
		});

		$('#fbf-ebay-packages-tyres-confirm-listing').bind('click', function (){
			console.log('confirm listing button press');

			let $content = $('.tb-modal-content');
			$content.empty();
			$content.append('<p><span class="spinner"></span> Please wait...</p>');

			let data = {
				action: 'fbf_ebay_packages_list_tyres',
				ajax_nonce: fbf_ebay_packages_admin.ajax_nonce,
			};
			$.ajax({
				// eslint-disable-next-line no-undef
				url: fbf_ebay_packages_admin.ajax_url,
				type: 'POST',
				data: data,
				dataType: 'json',
				success: function (response) {
					if(response.status==='success'){
						// Close the thickbox
						tb_remove();
						table.search('');
						table.columns().search('');
						table.ajax.reload();
					}else{
						alert('Errors generated, check console');
						console.log(response.errors);
					}
				},
			})

			return false;
		});

		$('#fbf_ebay_packages_clean').bind('click', function(){
			console.log('cleaning');
			let $loader = $(this).next();
			$loader.addClass('is-active');
			let conf = confirm('WARNING - this is a destructive act, you will delete ALL of the active eBay listings if you continue... are you sure you want to do this?');
			if(conf===true){
				let data = {
					action: 'fbf_ebay_packages_clean',
					product_type: 'wheel',
					ajax_nonce: fbf_ebay_packages_admin.ajax_nonce,
				};

				$.ajax({
					// eslint-disable-next-line no-undef
					url: fbf_ebay_packages_admin.ajax_url,
					type: 'POST',
					data: data,
					dataType: 'json',
					success: function (response) {
						$loader.removeClass('is-active');
					}
				});
			}else{
				$loader.removeClass('is-active');
			}
			return false;
		});

		$('#fbf_ebay_packages_synchronise').bind('click', function(){
			console.log('syncronising');
			let $loader = $(this).parent().find('.spinner');
			$loader.addClass('is-active');

			let data = {
				action: 'fbf_ebay_packages_synchronise',
				ajax_nonce: fbf_ebay_packages_admin.ajax_nonce,
			};
			$.ajax({
				// eslint-disable-next-line no-undef
				url: fbf_ebay_packages_admin.ajax_url,
				type: 'POST',
				data: data,
				dataType: 'json',
				success: function (response) {
					$loader.removeClass('is-active');
					if(response.status==='success'){
						console.log('synchronised');
						log.ajax.reload();
					}
				}
			});
			return false;
		});

		$('#fbf_ebay_packages_schedule').bind('click', function(){
			let data = {
				action: 'fbf_ebay_packages_schedule',
				ajax_nonce: fbf_ebay_packages_admin.ajax_nonce,
			}
			$.ajax({
				// eslint-disable-next-line no-undef
				url: fbf_ebay_packages_admin.ajax_url,
				type: 'POST',
				data: data,
				dataType: 'json',
				success: function (response) {
					console.log(response.next);
				}
			});
			return false;
		});

		$('#fbf_ebay_packages_unschedule').bind('click', function(){
			console.log('unschedule click');
			let data = {
				action: 'fbf_ebay_packages_unschedule',
				ajax_nonce: fbf_ebay_packages_admin.ajax_nonce,
			}
			$.ajax({
				// eslint-disable-next-line no-undef
				url: fbf_ebay_packages_admin.ajax_url,
				type: 'POST',
				data: data,
				dataType: 'json',
				success: function (response) {
					console.log(response);
				}
			});
			return false;
		});

		$('#fbf_ebay_packages_test_skus').bind('click', function(){
			console.log('testing skus');
			let $loader = $(this).parent().find('.spinner');
			$loader.addClass('is-active');

			let data = {
				action: 'fbf_ebay_packages_test_item',
				ajax_nonce: fbf_ebay_packages_admin.ajax_nonce,
				items: $('#fbf_ebay_packages_skus').val(),
				type: 'wheel'
			};
			$.ajax({
				// eslint-disable-next-line no-undef
				url: fbf_ebay_packages_admin.ajax_url,
				type: 'POST',
				data: data,
				dataType: 'json',
				success: function (response) {
					if(response.status==='success'){
						$loader.removeClass('is-active');
						if(response.status==='success'){
							console.log('synchronised');
							table.ajax.reload();
							log.ajax.reload();
						}
					}
				}
			});
			return false;
		});

		// add compatibility
		$('.add-compatibility').bind('click', function(){
			console.log('add compatibility' + ' ' + $(this).attr('data-chassis-id'));
			let thickbox_id = 'compatibility-thickbox';
			let $content = $('.tb-modal-content');
			let $confirm = $('#fbf-ebay-packages-compatibility-confirm-listing');
			$content.empty();
			$content.attr('data-compatibility', '{}');
			$content.attr('data-chassis-id', $(this).attr('data-chassis-id'));
			$confirm.prop('disabled', true);
			let url = '#TB_inline?&width=600&height=auto&inlineId=' + thickbox_id;
			tb_show('Add compatibility for ' + $(this).attr('data-chassis-name'), url);
			populate_compatibility();
			return false;
		});

		$('#fbf_ebay_packages_create_package').bind('click', function(){
			console.log('create package');
			let $loader = $(this).parent().find('.spinner');
			let $button = $(this);
			$button.prop('disabled', true);
			$loader.addClass('is-active');
			let selected_chassis = $('#package_chassis').select2('data')[0].text;
			console.log('selected chassis');
			console.log(selected_chassis);

			let data = {
				action: 'fbf_ebay_packages_package_create_listing',
				ajax_nonce: fbf_ebay_packages_admin.ajax_nonce,
				chassis_id: $('#package_chassis').select2('data')[0].id,
				wheel_id: $('#package_wheel').select2('data')[0].id,
				tyre_id: $('#package_tyre').select2('data')[0].id,
				nut_bolt_id: $('#package_nut_bolt').select2('data')[0].id,
				package_name: $('#package_name').val(),
			};
			$.ajax({
				// eslint-disable-next-line no-undef
				url: fbf_ebay_packages_admin.ajax_url,
				type: 'POST',
				data: data,
				dataType: 'json',
				success: function (response) {
					console.log(response);
					$loader.removeClass('is-active');
					if(response.status==='success'){
						console.log('reload the table');

						package_chassis_select2_init();
						package_wheel_select2_init();
						$('#package_wheel').prop('disabled', true);
						package_tyre_select2_init();
						$('#package_tyre').prop('disabled', true);
						package_nut_bolt_select2_init();
						$('#package_nut_bolt').prop('disabled', true);
						$('#package_name').val('');
						$('#package_desc').val('');

						// Scroll window to listings table
						$('html, body').animate({
							scrollTop: $("#package-listings").offset().top - 50
						}, 500);

						$('#dt_packages').DataTable().ajax.reload(function(){
							$('#dt_packages').DataTable().order([[1, 'desc']]).draw();
						});
					}else{
						alert('ERROR: ' + response.error);
					}
				},
			});
			return false;
		});

		$('#fbf_ep_event_log_detail tbody').on('click', 'td.log-details-control a', function () {
			var tr = $(this).closest('tr');
			var row = log_detail.row( tr );
			var $icon = $(this);
			console.log('show detail');
			console.log(row);
			console.log(row.data());

			if( row.child.isShown() ) {
				// This row is already open - close it
				row.child.hide();
				tr.removeClass('shown');
				$icon.removeClass('dashicons-arrow-up-alt2').addClass('dashicons-arrow-down-alt2');
			}else{
				// Open this row
				row.child(format2(row.data())).show();
				tr.addClass('shown');
				$icon.removeClass('dashicons-arrow-down-alt2').addClass('dashicons-arrow-up-alt2');
			}
			return false;
		});

		$('#fbf-ebay-packages-compatibility-confirm-listing').on('click', function(){
			let $content = $('.tb-modal-content');
			let data = {
				action: 'fbf_ebay_packages_confirm_compatibility',
				ajax_nonce: fbf_ebay_packages_admin.ajax_nonce,
				data: $content.attr('data-compatibility'),
				chassis_id: $content.attr('data-chassis-id'),
			};
			$.ajax({
				// eslint-disable-next-line no-undef
				url: fbf_ebay_packages_admin.ajax_url,
				type: 'POST',
				data: data,
				dataType: 'json',
				success: function (response) {
					console.log('response');
					if(response.status==='success'){
						// Close the thickbox
						tb_remove();
						let $list = $('#tb_compat_chassis_'+response.chassis_id);
						$list.empty();
						populate_compatibility_list($list);
					}else{
						alert('Error: '+response.error);
					}
				}
			});
			return false;
		});

		$('.tb_compat_delete').bind('click', function(){
			delete_compatibility($(this));
			return false;
		});

		$('.tb_compat_delete_all').bind('click', function(){
			let id = $(this).attr('data-chassis-id');
			let name = $(this).attr('data-chassis-name');
			let cnf = confirm('Are you sure you want to delete all compatibility for chassis: '+name);
			if(cnf){
				let data = {
					action: 'fbf_ebay_packages_compatibility_delete_all',
					ajax_nonce: fbf_ebay_packages_admin.ajax_nonce,
					id: id
				};
				$.ajax({
					// eslint-disable-next-line no-undef
					url: fbf_ebay_packages_admin.ajax_url,
					type: 'POST',
					data: data,
					dataType: 'json',
					success: function (response) {
						if(response.status==='success'){
							let $list = $('#tb_compat_chassis_'+response.chassis_id);
							$list.empty();
							populate_compatibility_list($list);
						}else{
							alert('Error: '+response.error);
						}
					}
				});
			}

			return false;
		});

		populate_chassis();

		function format(d){
			console.log('format');
			console.log(d);

			let data = {
				action: 'fbf_ebay_packages_listing_info',
				id: d.id
			};

			const result = $.ajax({
				// eslint-disable-next-line no-undef
				url: ajax_object.ajax_url,
				type: 'POST',
				data: data,
				dataType: 'json',
				success: function (response){
					console.log(response);
					let $child = $('#child_'+response.result.id);
					let html = '';
					if(response.result.info.created){
						html+= '' +
							'<tr>' +
							'<td>Created:</td>' +
							'<td>'+response.result.info.created+'</td>' +
							'</tr>';
					}
					if(response.result.info.activated_count){
						html+= '' +
							'<tr>' +
							'<td>Activated:</td>' +
							'<td>'+response.result.info.activated_count+' times</td>' +
							'</tr>';
					}
					if(response.result.info.deactivated_count){
						html+= '' +
							'<tr>' +
							'<td>Deactivated:</td>' +
							'<td>'+response.result.info.deactivated_count+' times</td>' +
							'</tr>';
					}
					if(response.result.fitting_info){
						html+='' +
							'<tr>' +
							'<td colspan="2"><strong>'+d.type+' fits:</strong></td>' +
							'</tr>';
						$.each(response.result.fitting_info, function(key, item){
							html+='' +
								'<tr>' +
								'<td colspan="2">'+item+'</td>' +
								'</tr>';
						});
					}
					if(response.result.inv_info.sku!==null){
						html+='' +
							'<tr>' +
							'<td colspan="2"><strong>eBay Inventory item:</strong></td>' +
							'</tr>' +
							'<tr>' +
							'<td>Custom Label:</td>' +
							'<td>'+response.result.inv_info.sku+'</td>' +
							'</tr>';
						if(response.result.inv_info.first_created){
							html+='' +
								'<tr>' +
								'<td>Created:</td>' +
								'	<td>'+response.result.inv_info.first_created+'</td>' +
								'</tr>';
						}
						if(response.result.inv_info.update_count){
							html+='' +
								'<tr>' +
								'<td>Updated:</td>' +
								'<td>'+response.result.inv_info.update_count+' times</td>' +
								'</tr>';
						}
						if(response.result.inv_info.last_update){
							html+='' +
								'<tr>' +
								'<td>Last update:</td>' +
								'<td>'+response.result.inv_info.last_update+'</td>' +
								'</tr>';
						}
						if(response.result.inv_info.error_count>0){
							html+='' +
								'<tr>' +
								'<td><span class="dashicons dashicons-warning" style="color: darkred;"></span> Errors:</td>' +
								'<td>'+response.result.inv_info.error_count+'</td>' +
								'</tr>' +
								'<tr>' +
								'<td>Last error:</td>' +
								'<td>'+response.result.inv_info.last_error+'</td>' +
								'</tr>';
						}
					}
					if(response.result.offer_info.offer_id!==null){
						html+='' +
							'<tr>' +
							'<td colspan="2"><strong>eBay Offer:</strong></td>' +
							'</tr>' +
							'<tr>' +
							'<td>ID:</td>' +
							'<td>'+response.result.offer_info.offer_id+'</td>' +
							'</tr>';
						if(response.result.offer_info.first_created){
							html+='' +
								'<tr>' +
								'<td>Created:</td>' +
								'<td>'+response.result.offer_info.first_created+'</td>' +
								'</tr>';
						}
						if(response.result.offer_info.update_count){
							html+='' +
								'<tr>' +
								'<td>Updated:</td>' +
								'<td>'+response.result.offer_info.update_count+' times</td>' +
								'</tr>';
						}
						if(response.result.offer_info.last_update){
							html+='' +
								'<tr>' +
								'<td>Last update:</td>' +
								'<td>'+response.result.offer_info.last_update+'</td>' +
								'</tr>';
						}
						if(response.result.offer_info.error_count>0){
							html+='' +
								'<tr>' +
								'<td><span class="dashicons dashicons-warning" style="color: darkred;"></span> Errors:</td>' +
								'<td>'+response.result.offer_info.error_count+'</td>' +
								'</tr>' +
								'<tr>' +
								'<td>Last error:</td>' +
								'<td>'+response.result.offer_info.last_error+'</td>' +
								'</tr>';
						}
					}
					if(response.result.publish_info.listing_id!==null){
						html+='' +
							'<tr>' +
							'<td colspan="2"><strong>eBay Publish Info:</strong></td>' +
							'</tr>' +
							'<tr>' +
							'<td>Listing ID:</td>' +
							'<td>'+response.result.publish_info.listing_id+'</td>' +
							'</tr>';
						if(response.result.publish_info.first_created){
							html+='' +
								'<tr>' +
								'<td>Published:</td>' +
								'<td>'+response.result.publish_info.first_created+'</td>' +
								'</tr>';
						}
						if(response.result.publish_info.error_count>0){
							html+='' +
								'<tr>' +
								'<td><span class="dashicons dashicons-warning" style="color: darkred;"></span> Errors:</td>' +
								'<td>'+response.result.publish_info.error_count+'</td>' +
								'</tr>' +
								'<tr>' +
								'<td>Last error:</td>' +
								'<td>'+response.result.publish_info.last_error+'</td>' +
								'</tr>';
						}
					}
					if(response.result.full_log_url){
						html+='' +
							'<tr>' +
							'<td colspan="2">' +
							'<a class="" href="'+response.result.full_log_url+'">View all log entries for item...</a>' +
							'</td>' +
							'</tr>';
					}


					$child.append(html);
				}
			});

			return '<table cellpadding="5" cellspacing="0" border="0" style="width: 100%;" id="child_'+d.id+'">'+
				'</table>';
		}

		function format2(d){
			let data = {
				action: 'fbf_ebay_packages_detail_log_response',
				id: d.id
			};

			const result = $.ajax({
				// eslint-disable-next-line no-undef
				url: ajax_object.ajax_url,
				type: 'POST',
				data: data,
				dataType: 'json',
				success: function (response) {
					console.log(response);
					let $child = $('#child_' + response.id);
					let html ='' +
						'<tr>' +
						'<td><pre>' + response.log + '</pre></td>' +
						'</tr>';
					$child.append(html);
				}
			});

			return '<table cellpadding="5" cellspacing="0" border="0" style="width: 100%;" id="child_'+d.id+'">'+
				'</table>';
		}

		function get(name){
			if(name=(new RegExp('[?&]'+encodeURIComponent(name)+'=([^&]*)')).exec(location.search))
				return decodeURIComponent(name[1]);
		}

		function populate_chassis(){
			let data = {
				action: 'fbf_ebay_packages_wheel_get_chassis',
				ajax_nonce: fbf_ebay_packages_admin.ajax_nonce,
			};
			$.ajax({
				// eslint-disable-next-line no-undef
				url: fbf_ebay_packages_admin.ajax_url,
				type: 'POST',
				data: data,
				dataType: 'json',
				success: function (response) {
					if(response.status==='success'){
						let $chassis_wrap = $('#wheel-chassis-wrap');
						let $selects = $('.wheel-chassis-select');
						let ids = [];
						$selects.each(function (key, value){
							ids.push($(this).attr('data-id'));
						});
						console.log(ids);
						// Create <select>'s that need creating
						$.each(response.data, function(key, value){
							// Only create it if it's not already in the ids array
							if(!ids.includes(value.ID)){
								let $wrap = $('<div class="wheel-chassis-select-wrap" id="wheel-chassis-wrap-'+value.ID+'" style="margin-bottom: 1em;"><label for="wheel-chassis-select-'+value.ID+'">'+value.name+':</label></div>');
								let $select = $(`<select class="wheel-chassis-select" data-id="${value.ID}" id="wheel-chassis-select-${value.ID}" multiple style="width: 99%; max-width: 25em;"></select>`);
								$.each(value.chassis, function(c_key, c_value){
									let option = '<option value="'+c_value.ID+'" '+c_value.selected+'>'+c_value.name+'</option>';
									$select.append(option);
								});
								$wrap.append($select);
								$chassis_wrap.append($wrap);

								$select.select2();
							}

							// Remove this value.ID from the ids array
							let index = ids.indexOf(value.ID);
							if(index > -1){
								ids.splice(index, 1);
							}
						});

						// Now remove remaining ids
						$.each(ids, function(key, value){
							let $element = $('#wheel-chassis-wrap-'+value);
							$element.remove();
						});
					}
				}
			});
		}

		function populate_compatibility(){
			let $content = $('.tb-modal-content');

			let data = {
				action: 'fbf_ebay_packages_compatibility',
				ajax_nonce: fbf_ebay_packages_admin.ajax_nonce,
				data: $content.attr('data-compatibility'),
				chassis_id: $content.attr('data-chassis-id'),
			};
			$.ajax({
				// eslint-disable-next-line no-undef
				url: fbf_ebay_packages_admin.ajax_url,
				type: 'POST',
				data: data,
				dataType: 'json',
				success: function (response) {
					console.log(response);

					if(response.status==='success'){
						// remove unwanted levels
						$('.tb_compatibility_wrapper').each(function(e_key, e_value){
							let level = $(this).attr('data-level');
							if(level >= response.level){
								$(this).remove();
							}
						});

						let $wrapper = $('<div class="tb_compatibility_wrapper" id="tb_compat_wrap_'+response.level+'" style="margin-top: 1em;" data-level="'+response.level+'"></div>');

						if(response.values.length){
							$content.append($wrapper);
							let $label = $('<label for="tb_compat_select_'+response.level+'">'+response.label+'</label>');
							$wrapper.append($label);
							let multiple = '';
							if(response.select_limit===false){
								multiple = 'multiple="multiple"';
							}
							let $select = $('<select id="tb_compat_select_'+response.level+'" style="width: 100%;" '+multiple+'></select>');
							$wrapper.append($select);
							$.each(response.values, function(key, value){
								let $option;
								if(response.selected!==null && response.selected.includes(value.value)) {
									$option = '<option value="' + value.value + '" disabled>' + value.value + '</option>';
								}else{
									$option = '<option value="' + value.value + '">' + value.value + '</option>';
								}
								$select.append($option);
							});
							// Multi-select - so add checkbox for all
							if(response.select_limit===false){
								let $select_all = $('<div style="margin-top: 0.5em;"><input type="checkbox" id="tb_compat_select_all_'+response.level+'" data-id="'+response.level+'"/> <label for="tb_compat_select_all_'+response.level+'">Select All</label></div>');
								$wrapper.append($select_all);

								$('#tb_compat_select_all_'+response.level).bind('click', function(){
									let $options = $select.find('option');
									if($(this).is(':checked') ){
										$options.each(function(o_key, o_val){
											$(o_val).prop('selected', true);
										});
									}else{
										$options.each(function(o_key, o_val){
											$(o_val).prop('selected', false);
										});
									}
									$select.trigger('change');
								});
							}
							$select.val('').change();
							$select.select2();
							$select.bind('change', function(){
								compatability_select($content, response.level, response.name, response.max_levels, $select.select2('data'));
							});
						}
					}
				}
			});

			function compatability_select($content, level, name, max, values){
				console.log('compatibility select');
				let selections = [];
				let $confirm = $('#fbf-ebay-packages-compatibility-confirm-listing');
				$.each(values, function(key, value){
					selections.push(value.text);
				});
				let data = JSON.parse($content.attr('data-compatibility'));
				data['level_'+level] = {
					selections: selections,
					level: level,
					name: name
				};
				data['next_level'] = level + 1;

				if(level >= max){
					if(values.length){
						console.log('last level and selections exist');
						$confirm.prop('disabled', false);
					}else{
						console.log('last level and no selections');
						$confirm.prop('disabled', true);
					}
				}else{
					console.log('not last level');
					$confirm.prop('disabled', true)
				}

				for (const [key, value] of Object.entries(data)) {
					if(value.level > level){
						delete data[key];
					}
				}
				$content.attr('data-compatibility', JSON.stringify(data));
				populate_compatibility();
			}
		}

		function populate_compatibility_list($list){
			let id = $list.attr('data-id');
			let data = {
				action: 'fbf_ebay_packages_compatibility_list',
				ajax_nonce: fbf_ebay_packages_admin.ajax_nonce,
				id: id
			};
			$.ajax({
				// eslint-disable-next-line no-undef
				url: fbf_ebay_packages_admin.ajax_url,
				type: 'POST',
				data: data,
				dataType: 'json',
				success: function (response) {
					if(response.status==='success'){
						$.each(response.list_items, function(key, value){
							let $li = $('<li style="display: inline-block; margin-right: 0.5em;">'+value.name+'<a class="tb_compat_delete dashicons dashicons-no-alt" data-name="'+value.name+'" data-id="'+value.id+'" href="#"></a></li>');
							$li.find('.tb_compat_delete').bind('click', function(){
								delete_compatibility($(this));
								return false;
							});
							$list.append($li);
						});
					}else{
						alert('Error: '+response.error);
					}
				}
			});
		}

		function delete_compatibility($elem){
			console.log($elem);
			let name = $elem.attr('data-name');
			let id = $elem.attr('data-id');
			console.log(name);
			let conf = confirm('Please confirm that you wish to remove compatibility with: '+name);

			if(conf){
				let data = {
					action: 'fbf_ebay_packages_compatibility_delete',
					ajax_nonce: fbf_ebay_packages_admin.ajax_nonce,
					id: id
				};
				$.ajax({
					// eslint-disable-next-line no-undef
					url: fbf_ebay_packages_admin.ajax_url,
					type: 'POST',
					data: data,
					dataType: 'json',
					success: function (response) {
						if(response.status==='success'){
							let $list = $('#tb_compat_chassis_'+response.chassis_id);
							$list.empty();
							populate_compatibility_list($list);
						}else{
							alert('Error: '+response.error);
						}
					}
				});
			}
		}
	});

	function validateACFinputs(nonce, form_data) {
		var data = {
			nonce: nonce,
			action: "acf/validate_save_post",
		};


		for (const i in form_data) {
			//
			console.log(form_data[i].name);
			console.log(form_data[i].value);

			if(form_data[i].name.includes('acf[field_')){
				data[form_data[i].name] = form_data[i].value;
			}
		}

		//console.log(data);

		$.ajax({
			// eslint-disable-next-line no-undef
			url: ajax_object.ajax_url,
			type: 'POST',
			data: data,
			success: function (result) {
				console.log(result);
				if(result.success===true){
					// now check for errors
					if(result.errors.length>0){
						return result.errors;
					}else{
						return true;
					}
				}
			},
		});
	}

	function package_chassis_select2_init(){
		if($('#package_chassis').hasClass('select2-hidden-accessible')){
			console.log('chassis has select2 - destroy it');
			$('#package_chassis').select2('destroy');
			$('#package_chassis').val('');
		}
		$('#package_chassis').select2({
			ajax: {
				url: fbf_ebay_packages_admin.ajax_url, // AJAX URL is predefined in WordPress admin
				dataType: 'json',
				delay: 250, // delay in ms while typing when to perform a AJAX search
				data: function (params) {
					console.log(params);
					return {
						q: params.term, // search query
						action: 'fbf_ebay_packages_get_package_chassis', // AJAX action for admin-ajax.php
						ajax_nonce: fbf_ebay_packages_admin.ajax_nonce
					};
				},
				processResults: function( data ) {
					var options = [];
					if ( data ) {

						// data is the array of arrays, and each of them contains ID and the Label of the option
						$.each( data, function( index, text ) { // do not forget that "index" is just auto incremented value
							options.push( { id: text[0], text: text[1]  } );
						});

					}
					return {
						results: options
					};
				},
				cache: true
			},
			placeholder: 'Select chassis...'
		}).on('select2:selecting', function(e) {
			// Here if user selects a chassis
			console.log('Selecting: ' , e.params.args.data);
			$('#package_wheel').attr('data-chassis_id', e.params.args.data.id);
			$('#package_wheel').attr('data-chassis_name', e.params.args.data.text);
			$('#package_wheel').prop('disabled', false);
			$('#package_tyre').prop('disabled', true);
			$('#package_nut_bolt').prop('disabled', true);
			$('#fbf_ebay_packages_create_package').prop('disabled', true);
			package_wheel_select2_init();
			package_tyre_select2_init();
			package_nut_bolt_select2_init();
			$('#package_wheel').select2('open');
		});
	}

	function package_wheel_select2_init(){
		if($('#package_wheel').hasClass('select2-hidden-accessible')){
			console.log('package_wheel has select2 - destroy it');
			$('#package_wheel').select2('destroy');
			$('#package_wheel').val('');
		}
		$('#package_wheel').select2({
			ajax: {
				url: fbf_ebay_packages_admin.ajax_url, // AJAX URL is predefined in WordPress admin
				dataType: 'json',
				delay: 250, // delay in ms while typing when to perform a AJAX search
				data: function (params) {
					console.log('params');
					console.log(params);
					return {
						q: params.term, // search query
						chassis_id: $('#package_wheel').attr('data-chassis_id'),
						chassis_name: $('#package_wheel').attr('data-chassis_name'),
						action: 'fbf_ebay_packages_get_package_wheel', // AJAX action for admin-ajax.php
						ajax_nonce: fbf_ebay_packages_admin.ajax_nonce
					};
				},
				processResults: function( data ) {
					var options = [];
					if ( data ) {

						// data is the array of arrays, and each of them contains ID and the Label of the option
						$.each( data, function( index, text ) { // do not forget that "index" is just auto incremented value

							console.log(text[1]);
							options.push( { id: text[0], text: text[1]  } );
						});
					}
					return {
						results: options
					};
				},
				cache: true
			},
			placeholder: 'Select wheel...'
		}).on('select2:selecting', function(e) {
			// Here if user selects a tyre
			console.log('Selecting tyre: ' , e.params.args.data);
			$('#package_tyre').attr('data-wheel_id', e.params.args.data.id);
			$('#package_tyre').prop('disabled', false);
			$('#fbf_ebay_packages_create_package').prop('disabled', true);
			package_tyre_select2_init();
			package_nut_bolt_select2_init();
			$('#package_tyre').select2('open');
		});
	}

	function package_tyre_select2_init(){
		if($('#package_tyre').hasClass('select2-hidden-accessible')){
			console.log('package_tyre has select2 - destroy it');
			$('#package_tyre').select2('destroy');
			$('#package_tyre').val('');
		}
		$('#package_tyre').select2({
			ajax: {
				url: fbf_ebay_packages_admin.ajax_url, // AJAX URL is predefined in WordPress admin
				dataType: 'json',
				delay: 250, // delay in ms while typing when to perform a AJAX search
				data: function (params) {
					console.log('params');
					console.log(params);
					return {
						q: params.term, // search query
						chassis_id: $('#package_wheel').attr('data-chassis_id'),
						wheel_id: $('#package_tyre').attr('data-wheel_id'),
						action: 'fbf_ebay_packages_get_package_tyre', // AJAX action for admin-ajax.php
						ajax_nonce: fbf_ebay_packages_admin.ajax_nonce
					};
				},
				processResults: function( data ) {
					var options = [];
					if ( data ) {

						// data is the array of arrays, and each of them contains ID and the Label of the option
						$.each( data, function( index, text ) { // do not forget that "index" is just auto incremented value

							console.log(text[1]);
							options.push( { id: text[0], text: text[1]  } );
						});
					}
					return {
						results: options
					};
				},
				cache: true
			},
			placeholder: 'Select tyre...'
		}).on('select2:selecting', function(e) {
			$('#package_nut_bolt').prop('disabled', false);
			package_nut_bolt_select2_init();
			check_package_vals();
			$('#package_nut_bolt').select2('open');
		});
	}

	function package_nut_bolt_select2_init(){
		if($('#package_nut_bolt').hasClass('select2-hidden-accessible')){
			console.log('package_nut_bolt has select2 - destroy it');
			$('#package_nut_bolt').select2('destroy');
			$('#package_nut_bolt').val('');
		}
		$('#package_nut_bolt').select2({
			ajax: {
				url: fbf_ebay_packages_admin.ajax_url, // AJAX URL is predefined in WordPress admin
				dataType: 'json',
				delay: 250, // delay in ms while typing when to perform a AJAX search
				data: function (params) {
					console.log('params');
					console.log(params);
					return {
						q: params.term, // search query
						chassis_id: $('#package_wheel').attr('data-chassis_id'),
						wheel_id: $('#package_tyre').attr('data-wheel_id'),
						action: 'fbf_ebay_packages_get_package_nut_bolt', // AJAX action for admin-ajax.php
						ajax_nonce: fbf_ebay_packages_admin.ajax_nonce
					};
				},
				processResults: function( data ) {
					var options = [];
					if ( data ) {

						// data is the array of arrays, and each of them contains ID and the Label of the option
						$.each( data, function( index, text ) { // do not forget that "index" is just auto incremented value

							console.log(text[1]);
							options.push( { id: text[0], text: text[1]  } );
						});
					}
					return {
						results: options
					};
				},
				cache: true
			},
			placeholder: 'Select nut/bolt...'
		}).on('select2:select', function(e) {
			check_package_vals();
			$('#package_name').focus();
		});
	}

	function check_package_vals(){
		console.log('checking package form vals');
		let chassis = $('#package_chassis').val();
		let wheel = $('#package_wheel').val();
		let tyre = $('#package_tyre').val();
		let nut_bolt = $('#package_nut_bolt').val();
		let name = $('#package_name').val();
		let desc = $('#package_desc').val();

		if(!chassis || !wheel|| !tyre || !nut_bolt || !name || ! desc){
			$('#fbf_ebay_packages_create_package').prop('disabled', true);
		}else{
			$('#fbf_ebay_packages_create_package').prop('disabled', false);
		}
	}

})( jQuery );


