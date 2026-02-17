/**
 * Admin JavaScript for Woo HubSpot Product Tag Sync.
 *
 * @package Woo_HubSpot_Product_Tag_Sync
 * @since   1.0.0
 */

/* global jQuery, whptsAdmin */
(function ($) {
	'use strict';

	$(document).ready(function () {
		// =============================================
		// Connection Test
		// =============================================
		$('#whpts-test-connection').on('click', function (e) {
			e.preventDefault();

			var $button = $(this);
			var $status = $('#whpts-connection-status');
			var token = $('#whpts_hubspot_token').val();

			if (!token) {
				$status
					.text(whptsAdmin.i18n.error + 'Please enter a token first.')
					.removeClass('whpts-success whpts-testing')
					.addClass('whpts-error');
				return;
			}

			// Show testing state.
			$button.prop('disabled', true);
			$status
				.text(whptsAdmin.i18n.testing)
				.removeClass('whpts-success whpts-error')
				.addClass('whpts-testing');

			$.ajax({
				url: whptsAdmin.ajax_url,
				type: 'POST',
				data: {
					action: 'whpts_test_connection',
					nonce: whptsAdmin.nonce,
					token: token,
				},
				success: function (response) {
					if (response.success) {
						$status
							.text(whptsAdmin.i18n.success)
							.removeClass('whpts-error whpts-testing')
							.addClass('whpts-success');
					} else {
						$status
							.text(
								whptsAdmin.i18n.error +
									(response.data && response.data.message
										? response.data.message
										: 'Unknown error')
							)
							.removeClass('whpts-success whpts-testing')
							.addClass('whpts-error');
					}
				},
				error: function () {
					$status
						.text(whptsAdmin.i18n.error + 'Request failed.')
						.removeClass('whpts-success whpts-testing')
						.addClass('whpts-error');
				},
				complete: function () {
					$button.prop('disabled', false);
				},
			});
		});

		// =============================================
		// Product Filter (Mappings Tab)
		// =============================================
		$('#whpts-product-filter').on('keyup', function () {
			var query = $(this).val().toLowerCase();

			$('.whpts-mapping-row').each(function () {
				var productName = $(this).data('product-name') || '';
				if (productName.indexOf(query) > -1) {
					$(this).removeClass('whpts-hidden');
				} else {
					$(this).addClass('whpts-hidden');
				}
			});
		});
	});
})(jQuery);
