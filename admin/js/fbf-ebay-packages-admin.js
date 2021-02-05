(function( $ ) {
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


