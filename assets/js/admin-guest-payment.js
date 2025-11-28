/**
 * Wicket Guest Payment Admin AJAX (Vanilla JS)
 */
document.addEventListener('DOMContentLoaded', function () {
	// Use event delegation on a stable parent element
	const metaBoxContainer = document.getElementById('wicket_guest_payment_metabox');

	if (!metaBoxContainer) {
		// Metabox not found on this page
		return;
	}

	metaBoxContainer.addEventListener('click', function (event) {
		const copyButton = event.target.closest('.wicket-copy-link-button'); // Keep for the persistent link copy
		const generateSendButton = event.target.closest('.wicket-generate-send-button');
		const resendButton = event.target.closest('.wicket-resend-email-button');
		const invalidateButton = event.target.closest('.wicket-invalidate-link-button');

		// --- Handle Generate & Send Email, Resend Email, Invalidate Link, or Manual Generate Button ---
		const manualGenerateButton = event.target.closest('.wicket-generate-manual-button');
		if (generateSendButton || resendButton || invalidateButton || manualGenerateButton) {
			event.preventDefault();
			const button = generateSendButton || resendButton || invalidateButton || manualGenerateButton;

			// Get data attributes
			const orderId = button.dataset.orderId;
			const nonce = button.dataset.nonce;

			// Determine button type
			const isResend = button.classList.contains('wicket-resend-email-button');
			const isInvalidate = button.classList.contains('wicket-invalidate-link-button');
			const isManualGenerate = button.classList.contains('wicket-generate-manual-button');

			// Get or create the feedback area early so it can be used in confirmation dialogs
			let feedbackArea;

			// Try to find the feedback area with the specific or generic class
			if (isManualGenerate) {
				feedbackArea = button.closest('#wicket-guest-payment-manual-generate').querySelector('.wicket-ajax-feedback-bottom') || button.closest('#wicket-guest-payment-manual-generate').querySelector('.wicket-ajax-feedback');
			} else {
				feedbackArea = button.closest('.inside').querySelector('.wicket-ajax-feedback-top') || button.closest('.inside').querySelector('.wicket-ajax-feedback');
			}

			// If not found, create it
			if (!feedbackArea) {

				feedbackArea = document.createElement('div');

				// Set appropriate class based on button type
				if (isManualGenerate) {
					feedbackArea.className = 'wicket-ajax-feedback wicket-ajax-feedback-bottom notice';
				} else {
					feedbackArea.className = 'wicket-ajax-feedback wicket-ajax-feedback-top notice';
				}

				feedbackArea.style.display = 'none';
				feedbackArea.style.margin = '5px 0 0 0';
				feedbackArea.style.padding = '5px';

				// For resend/invalidate buttons, find the manage link container and append after all buttons
				if (isResend || isInvalidate) {
					// Find the manage link container that holds both buttons
					const manageContainer = button.closest('.wicket-manage-link-container');
					if (manageContainer) {
						// Check if there's already a feedback area
						const existingFeedback = manageContainer.querySelector('.wicket-ajax-feedback');
						if (existingFeedback) {
							// Use the existing one
							feedbackArea = existingFeedback;
						} else {
							// Append after all the buttons
							manageContainer.appendChild(feedbackArea);
						}
					} else {
						// Fallback: insert after the button if container not found
						const parentElement = button.parentNode;
						// Find the last button in this container
						const allButtons = parentElement.querySelectorAll('button');
						const lastButton = allButtons[allButtons.length - 1];

						if (lastButton && lastButton.nextSibling) {
							parentElement.insertBefore(feedbackArea, lastButton.nextSibling);
						} else {
							parentElement.appendChild(feedbackArea);
						}
					}
				} else {
					// For other buttons, append to container
					if (isManualGenerate) {
						button.closest('#wicket-guest-payment-manual-generate').appendChild(feedbackArea);
					} else {
						button.closest('.inside').appendChild(feedbackArea);
					}
				}
			}

			// --- START: Confirmation Dialogs ---
			if (isResend) {
				const guestEmail = button.dataset.guestEmail;
				if (!guestEmail) {
					feedbackArea.textContent = 'Error: Could not get email address.';
					feedbackArea.className = 'wicket-ajax-feedback notice notice-error is-dismissible';
					feedbackArea.style.display = 'block';
					return; // Stop if email is missing
				}
				const confirmationMessage = wicketGuestPayment.text.resendConfirmation.replace('%s', guestEmail);
				if (!window.confirm(confirmationMessage)) {
					return; // Stop if user cancels
				}
			}
			// --- END: Confirmation Dialog for Resend ---

			// --- START: Confirmation for Invalidate/Generate Manual (uses data-confirm) ---
			if (isInvalidate || isManualGenerate) {
				const confirmMessage = button.dataset.confirm;
				if (confirmMessage && !window.confirm(confirmMessage)) {
					return; // Stop if user cancels
				}
			}
			// --- END: Confirmation for Invalidate/Generate Manual ---

			// Disable button to prevent double-clicks
			button.disabled = true;

			// Get container elements - different containers for manual generate vs email send
			let container;
			if (isManualGenerate) {

				container = button.closest('#wicket-guest-payment-manual-generate');

				if (!container) {

					return;
				}
			} else {
				container = button.closest('.inside');
				if (!container) {

					return;
				}
			}

			const emailInput = !isResend && !isInvalidate && container.querySelector('.wicket-guest-email-input');
			// Get the spinner based on button type
			let spinner;
			if (isResend || isInvalidate) {
				// For resend/invalidate buttons, find the spinner within the parent div
				spinner = button.closest('div').querySelector('.spinner');
			} else {
				// For other buttons, use the next sibling or find in container
				spinner = button.nextElementSibling || container.querySelector('.spinner');
			}
			// Validate required elements
			if (!spinner) {
				// Can't show in feedback area if spinner is missing, just exit
				button.disabled = false;
				return;
			}
			if (!feedbackArea) {
				// Can't show feedback if area is missing, just exit
				button.disabled = false;
				return;
			}

			// Only validate email input for non-manual, non-resend, non-invalidate actions
			if (!isManualGenerate && !isResend && !isInvalidate && !emailInput) {
				feedbackArea.textContent = 'Guest Payment: Missing email input element for email action.';
				button.disabled = false;
				return;
			}

			// Validate required parameters
			if (!orderId || !nonce) {
				feedbackArea.textContent = wicketGuestPayment.text.errorGeneral;
				feedbackArea.className = 'wicket-ajax-feedback notice notice-error is-dismissible';
				feedbackArea.style.display = 'block';
				button.disabled = false;
				return;
			}

			// For generate & send, validate email
			let guestEmail = '';
			if (!isResend && !isInvalidate && emailInput) {
				guestEmail = emailInput.value.trim();
				// Basic email validation
				if (!guestEmail || !/^[\w-\.]+@([\w-]+\.)+[\w-]{2,4}$/.test(guestEmail)) {
					feedbackArea.textContent = wicketGuestPayment.text.errorInvalidEmail;
					feedbackArea.className = 'wicket-ajax-feedback notice notice-error is-dismissible';
					feedbackArea.style.display = 'block';
					emailInput.focus();
					return;
				}
			}

			// --- UI Update Start ---
			button.disabled = true;
			spinner.classList.add('is-active');
			feedbackArea.textContent = '';
			feedbackArea.style.display = 'none';
			// --- UI Update End ---

			// Prepare form data
			const formData = new FormData();
			let action = 'wicket_generate_and_send_email';
			if (isResend) action = 'wicket_resend_email';
			if (isInvalidate) action = 'wicket_invalidate_link';
			if (isManualGenerate) action = 'wicket_generate_manual';

			formData.append('action', action);
			formData.append('order_id', orderId);
			formData.append('nonce', nonce);

			// Only add email for generate & send
			if (!isResend && !isInvalidate && !isManualGenerate) {
				formData.append('guest_email', guestEmail);
			}

			// Perform fetch request
			fetch(wicketGuestPayment.ajax_url, {
				method: 'POST',
				body: formData,
			}).then(response => {
				if (!response.ok) {
					throw new Error(`HTTP error! status: ${response.status}`);
				}
				return response.json();
			}).then(data => {
				if (data.success) {
					feedbackArea.textContent = data.data.message;
					feedbackArea.className = 'wicket-ajax-feedback notice notice-success is-dismissible';
					feedbackArea.style.display = 'block';

					// For generate & send, disable email input
					if (!isResend && !isInvalidate && !isManualGenerate && emailInput) {
						emailInput.disabled = true;
					}

					// Reload page after 2 seconds on success
					setTimeout(() => {
						feedbackArea.textContent = wicketGuestPayment.text.reloading || 'Reloading...';
						window.location.reload();
					}, 2000);

				} else {
					feedbackArea.textContent = data.data.message || wicketGuestPayment.text.errorGeneral;
					feedbackArea.className = 'wicket-ajax-feedback notice notice-error is-dismissible';
					feedbackArea.style.display = 'block';
					button.disabled = false;
				}
			}).catch(error => {
				feedbackArea.textContent = wicketGuestPayment.text.errorNetwork || 'Network error occurred.';
				feedbackArea.className = 'wicket-ajax-feedback notice notice-error is-dismissible';
				feedbackArea.style.display = 'block';
				button.disabled = false;
			}).finally(() => {
				spinner.classList.remove('is-active');
			});
		}
		// --- Copy to Clipboard Handler ---
		// Handle copy link button clicks
		if (copyButton) {
			event.preventDefault();

			const container = copyButton.closest('.wicket-guest-payment-manual-link');
			if (!container) {
				console.error('Guest Payment: Could not find container (.wicket-guest-payment-manual-link)');
				return;
			}

			const linkInput = container.querySelector('input[type="text"]');
			const copyFeedback = container.querySelector('.wicket-copy-feedback');

			if (!linkInput || !copyFeedback) {
				console.error('Guest Payment: Missing copy UI elements.');
				return;
			}

			if (navigator.clipboard && window.isSecureContext) {
				navigator.clipboard.writeText(linkInput.value).then(function () {
					// Success
					copyFeedback.style.display = 'inline';
					copyButton.textContent = wicketGuestPayment.text.copied || 'Copied!';
					setTimeout(function () {
						copyFeedback.style.display = 'none';
						copyButton.textContent = wicketGuestPayment.text.copyLink || 'Copy Link';
					}, 2000); // Reset after 2 seconds
				}, function () {
					// Failure using Clipboard API - fallback
					copyFallback(linkInput, copyButton, copyFeedback);
				});
			} else {
				// Fallback for insecure contexts or older browsers
				copyFallback(linkInput, copyButton, copyFeedback);
			}
		}

		// Fallback copy method using execCommand (less reliable, requires input selection)
		function copyFallback(linkInput, button, copyFeedback) {
			linkInput.select(); // Select the text
			linkInput.setSelectionRange(0, 99999); // For mobile devices

			try {
				const successful = document.execCommand('copy');
				if (successful) {
					copyFeedback.style.display = 'inline'; // Or 'block'
					button.textContent = wicketGuestPayment.text.copied;
					setTimeout(function () {
						copyFeedback.style.display = 'none';
						button.textContent = wicketGuestPayment.text.copyLink;
					}, 2000); // Reset after 2 seconds
				} else {
					alert('Failed to copy link automatically. Please select and copy manually.');
				}
			} catch (err) {
				// Silent error handling with user-friendly message
				alert('Failed to copy link automatically. Please select and copy manually.');
			}

			// Deselect text after attempting copy
			if (window.getSelection) {
				window.getSelection().removeAllRanges();
			} else if (document.selection) {
				document.selection.empty();
			}
		}
	});

	document.querySelectorAll('.wicket-guest-email-input').forEach(input => {
		input.addEventListener('keydown', (event) => {
			if (event.key === 'Enter') {
				event.preventDefault(); // Prevent default form submission
				// Find the button sibling to the parent <p> of the input
				const button = input.closest('p')?.nextElementSibling;
				if (button && button.matches('.wicket-generate-send-button')) {
					button.click();
				}
			}
		});
	});
});
