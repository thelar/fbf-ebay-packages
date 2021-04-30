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
		$('#tyre_brands').select2({
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

		// datatable
		let table = $('#example').DataTable({
			serverSide: true,
			processing: true,
			searchDelay: 700,
			ajax: {
				url: fbf_ebay_packages_admin.ajax_url,
				type: 'POST',
				data: {
					//action: 'fbf_ebay_packages_ebay_listing'
					action: 'fbf_ebay_packages_tyre_table'
				}
			},
			oLanguage: {
				sProcessing: "<span><i class=\"fas fa-spinner fa-pulse fa-lg\"></i></span><br/><p style=\"margin-top: 0.5em\">Loading</p>"
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
			oLanguage: {
				sProcessing: "<span><i class=\"fas fa-spinner fa-pulse fa-lg\"></i></span><br/><p style=\"margin-top: 0.5em\">Loading</p>"
			}
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

		function format (d){
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
									'<td>Errors:</td>' +
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
									'<td>Errors:</td>' +
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
								'<td>Errors:</td>' +
								'<td>'+response.result.publish_info.error_count+'</td>' +
								'</tr>' +
								'<tr>' +
								'<td>Last error:</td>' +
								'	<td>'+response.result.publish_info.last_error+'</td>' +
								'</tr>';
						}
					}

					$child.append(html);
				}
			});

			return '<table cellpadding="5" cellspacing="0" border="0" style="width: 100%;" id="child_'+d.id+'">'+
				'</table>';
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



})( jQuery );


