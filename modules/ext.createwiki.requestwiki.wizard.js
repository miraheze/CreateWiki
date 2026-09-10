( function () {
	'use strict';

	$( () => {
		const $wizard = $( '.ext-createwiki-wizard' );
		if ( !$wizard.length ) {
			return;
		}

		const $steps = $wizard.find( '.ext-createwiki-wizard-step' );
		const $dots = $wizard.find( '.ext-createwiki-wizard-dot' );
		const $back = $wizard.find( '.ext-createwiki-wizard-back' );
		const $next = $wizard.find( '.ext-createwiki-wizard-next' );
		const $submit = $wizard.find( '.ext-createwiki-wizard-submit' );
		const $returnToReview = $wizard.find( '.ext-createwiki-wizard-return-review' );

		const total = $steps.length;
		if ( !total ) {
			return;
		}

		const rest = new mw.Rest();
		const api = new mw.Api();

		const $nextLabel = $next.find( '.oo-ui-labelElement-label' );
		const nextLabelText = mw.msg( 'requestwiki-wizard-next' );
		const startLabelText = mw.msg( 'requestwiki-wizard-start' );
		const reviewYesText = mw.msg( 'requestwiki-wizard-review-yes' );
		const reviewNoText = mw.msg( 'requestwiki-wizard-review-no' );
		const reviewEmptyText = mw.msg( 'requestwiki-wizard-review-empty' );
		const reviewEditText = mw.msg( 'requestwiki-wizard-review-edit' );
		const fieldLabels = JSON.parse( $wizard.attr( 'data-field-labels' ) || '{}' );

		let current = 0;
		let cameFromReview = false;
		const loadToken = String( Date.now() ) + Math.random().toString( 36 ).slice( 2 );

		// HTMLInfoField renders its raw content wrapped in a <label> element with
		// no "for" target, so the browser implicitly associates it with the first
		// nested form control and forwards any click within it there, focusing
		// that control in the process. Block both the initial focus (which
		// happens at mousedown) and the forwarded click, unless the interaction
		// genuinely landed on one of the edit buttons.
		$wizard.find( '.ext-createwiki-wizard-review' ).closest( 'label' )
			.on( 'mousedown click', ( e ) => {
				if ( !$( e.target ).closest( '.ext-createwiki-wizard-review-edit' ).length ) {
					e.preventDefault();
				}
			} );

		/**
		 * Read the step index this page's current history entry represents.
		 *
		 * Every step shares the same URL, so a reload leaves a stale, pre-reload
		 * entry indistinguishable from a fresh one by step number alone. The load
		 * token lets a stale entry be recognized and rejected instead of trusted.
		 *
		 * @param {Object|null} state A History API state object.
		 * @return {number|null} Zero-based step index, or null if the entry
		 *   predates this page load and should not be trusted.
		 */
		function stepFromState( state ) {
			if ( !state || state.createwikiWizardLoadToken !== loadToken ) {
				return null;
			}

			return typeof state.createwikiWizardStep === 'number' ? state.createwikiWizardStep : 0;
		}

		/**
		 * Push a new history entry recording the wizard's current step.
		 *
		 * @param {number} step Zero-based step index.
		 * @return {void}
		 */
		function pushStepState( step ) {
			history.pushState(
				{ createwikiWizardStep: step, createwikiWizardLoadToken: loadToken }, '', location.href
			);
		}

		/**
		 * Replace the current history entry with the wizard's current step.
		 *
		 * @param {number} step Zero-based step index.
		 * @return {void}
		 */
		function replaceStepState( step ) {
			history.replaceState(
				{ createwikiWizardStep: step, createwikiWizardLoadToken: loadToken }, '', location.href
			);
		}

		/**
		 * Jump directly to a given step, recording it as a new history entry.
		 *
		 * @param {number} step Zero-based step index.
		 * @return {void}
		 */
		function goToStep( step ) {
			current = step;
			updateView( true );
			pushStepState( current );
		}

		/**
		 * Validate the current step, then jump to a given step if it passes.
		 *
		 * @param {jQuery} $button The button to disable while validation is in progress.
		 * @param {number} targetStep Zero-based step index to jump to once valid.
		 * @return {void}
		 */
		function validateThenGoToStep( $button, targetStep ) {
			validateStepThen( $steps.eq( current ), $button, () => {
				goToStep( targetStep );
			} );
		}

		/**
		 * Find the index of the first step containing a server-rendered error.
		 *
		 * @return {number} Zero-based step index, or -1 if no step has an error.
		 */
		function findStepWithError() {
			let found = -1;
			$steps.each( function ( index ) {
				if ( found === -1 && $( this ).find( '.oo-ui-fieldLayout-messages-error, .cdx-message--error, .errorbox' ).length ) {
					found = index;
				}
			} );

			return found;
		}

		/**
		 * Determine whether a field is currently hidden by a hide-if condition.
		 *
		 * @param {jQuery} $fieldLayout The field's OOUI field layout wrapper.
		 * @return {boolean}
		 */
		function isHiddenByHideIf( $fieldLayout ) {
			const el = $fieldLayout.get( 0 );
			return el.classList.contains( 'mw-htmlform-hide-if-hidden' ) ||
				el.classList.contains( 'oo-ui-element-hidden' ) ||
				el.style.display === 'none';
		}

		/**
		 * Build a review summary row for a single field and append it to the
		 * list, skipping fields with no name, no known label, or that are
		 * currently hidden by a hide-if condition.
		 *
		 * @param {jQuery} $list The summary list to append the row to.
		 * @param {jQuery} $fieldLayout The field's OOUI field layout wrapper.
		 * @param {number} stepIndex The zero-based step this field belongs to.
		 * @return {void}
		 */
		function appendReviewRow( $list, $fieldLayout, stepIndex ) {
			if ( isHiddenByHideIf( $fieldLayout ) ) {
				return;
			}

			const $control = $fieldLayout.find( 'input, select, textarea' ).filter( function () {
				return this.name && this.name.indexOf( 'wp' ) === 0;
			} ).first();

			const field = $control.get( 0 );
			if ( !field || !fieldLabels[ field.name ] ) {
				return;
			}

			let valueText;
			if ( field.type === 'checkbox' ) {
				valueText = field.checked ? reviewYesText : reviewNoText;
			} else if ( field.tagName === 'SELECT' ) {
				const selected = field.options[ field.selectedIndex ];
				valueText = selected ? selected.text : '';
			} else {
				valueText = $control.val() || '';
			}

			const $editButton = $( '<button>' )
				.attr( 'type', 'button' )
				.addClass( 'ext-createwiki-wizard-review-edit' )
				.text( reviewEditText )
				.on( 'click', () => {
					cameFromReview = true;
					goToStep( stepIndex );
				} );

			const $labelRow = $( '<div>' ).addClass( 'ext-createwiki-wizard-review-label-row' );
			$labelRow.append(
				$( '<div>' ).addClass( 'ext-createwiki-wizard-review-label' ).text( fieldLabels[ field.name ] )
			);

			$labelRow.append( $editButton );

			const $row = $( '<div>' ).addClass( 'ext-createwiki-wizard-review-row' );
			$row.append( $labelRow );
			$row.append(
				$( '<div>' ).addClass( 'ext-createwiki-wizard-review-value' ).text( valueText || reviewEmptyText )
			);

			$list.append( $row );
		}

		/**
		 * Rebuild the final step's summary of every field entered elsewhere
		 * in the wizard, reflecting the current, live values.
		 *
		 * @return {void}
		 */
		function buildReviewSummary() {
			const $review = $wizard.find( '.ext-createwiki-wizard-review' );
			if ( !$review.length ) {
				return;
			}

			const $list = $( '<div>' ).addClass( 'ext-createwiki-wizard-review-list' );

			$steps.each( function ( stepIndex ) {
				const stepKey = $( this ).data( 'step' );
				if ( stepKey === 'intro' || stepKey === 'agreement' ) {
					return;
				}

				$( this ).find( '.oo-ui-fieldLayout' ).each( function () {
					appendReviewRow( $list, $( this ), stepIndex );
				} );
			} );

			$review.empty().append( $list );
		}

		/**
		 * Show the current step and update the dots and nav buttons to match.
		 *
		 * @param {boolean} scroll Whether to smooth-scroll the new step into view.
		 * @return {void}
		 */
		function updateView( scroll ) {
			$steps.each( function ( index ) {
				$( this ).toggle( index === current );
			} );

			$dots.each( function ( index ) {
				$( this ).toggleClass( 'ext-createwiki-wizard-dot--current', index === current );
				$( this ).toggleClass( 'ext-createwiki-wizard-dot--complete', index < current );
			} );

			if ( current === total - 1 ) {
				cameFromReview = false;
			}

			const editingFromReview = cameFromReview && current !== total - 1;
			$back.toggle( !editingFromReview && current > 0 );
			$next.toggle( !editingFromReview && current < total - 1 );
			$submit.toggle( !editingFromReview && current === total - 1 );
			$returnToReview.toggle( editingFromReview );
			$nextLabel.text( current === 0 ? startLabelText : nextLabelText );
			buildReviewSummary();
			if ( scroll ) {
				$steps.eq( current ).get( 0 ).scrollIntoView( { behavior: 'smooth', block: 'start' } );
			}
		}

		/**
		 * Determine whether a form control has no meaningful value.
		 *
		 * @param {HTMLInputElement|HTMLSelectElement|HTMLTextAreaElement} field
		 *   Raw DOM form control.
		 * @param {jQuery} $field The same control wrapped in jQuery.
		 * @return {boolean}
		 */
		function fieldIsEmpty( field, $field ) {
			if ( field.type === 'checkbox' || field.type === 'radio' ) {
				return !field.checked;
			}

			return ( $field.val() || '' ).trim() === '';
		}

		/**
		 * Find the first form control within a step that fails native or required validation.
		 *
		 * @param {jQuery} $step The step to search within.
		 * @return {jQuery} The first invalid control, or an empty jQuery set if none.
		 */
		function firstInvalidField( $step ) {
			let $found = $( [] );
			$step.find( 'input, select, textarea' ).each( function () {
				const $field = $( this );
				if ( this.required && fieldIsEmpty( this, $field ) ) {
					$found = $field;
					return false;
				}

				if ( this.willValidate && !this.checkValidity() ) {
					$found = $field;
					return false;
				}

				return true;
			} );

			return $found;
		}

		/**
		 * Find the element an inline error message for a field should be inserted after.
		 *
		 * @param {jQuery} $input The form control the error belongs to.
		 * @return {jQuery} The control's OOUI field layout wrapper, or the control itself.
		 */
		function errorTarget( $input ) {
			const $wrapper = $input.closest( '.oo-ui-fieldLayout' );
			return $wrapper.length ? $wrapper : $input;
		}

		/**
		 * Remove any inline error message previously shown for a field.
		 *
		 * @param {jQuery} $input The form control to clear the error for.
		 * @return {void}
		 */
		function clearFieldError( $input ) {
			errorTarget( $input ).next( '.ext-createwiki-wizard-field-error' ).remove();
		}

		/**
		 * Show an inline error message for a field, replacing any existing one.
		 *
		 * @param {jQuery} $input The form control the error belongs to.
		 * @param {string} message The error message to display.
		 * @return {void}
		 */
		function showFieldError( $input, message ) {
			clearFieldError( $input );
			errorTarget( $input ).after(
				$( '<div>' ).addClass( 'ext-createwiki-wizard-field-error' ).text( message )
			);
		}

		/**
		 * Determine whether an element is actually rendered on the page.
		 *
		 * @param {HTMLElement} element
		 * @return {boolean}
		 */
		function isVisible( element ) {
			return element.offsetParent !== null;
		}

		/**
		 * Resolve the actual named form control for a REST-validated field marker.
		 *
		 * The marker class may land on the form control itself, or on an OOUI
		 * widget wrapper containing it alongside unrelated decorative controls.
		 *
		 * @param {jQuery} $marked The element carrying the REST-validate marker class.
		 * @return {jQuery} The matching named control, or an empty jQuery set if none.
		 */
		function findNamedControl( $marked ) {
			const candidates = $marked.is( 'input, select, textarea' ) ?
				$marked :
				$marked.find( 'input, select, textarea' );

			return candidates.filter( function () {
				return this.name && this.name.indexOf( 'wp' ) === 0;
			} ).first();
		}

		/**
		 * Pull a human-readable message out of a failed REST response, if present.
		 *
		 * @param {Object} xhr The jqXHR object from a failed request.
		 * @return {string|null} The first available localized message, or null if none.
		 */
		function extractHttpErrorMessage( xhr ) {
			const body = xhr && xhr.responseJSON;
			if ( !body || !body.messageTranslations ) {
				return null;
			}

			const translations = Object.values( body.messageTranslations );
			return translations.length ? translations[ 0 ] : null;
		}

		/**
		 * Validate a batch of fields against the REST endpoint in a single request.
		 *
		 * Rate limit, duplicate request, and token failures are request-level
		 * rejections from the server rather than per-field results, and are shown
		 * against $stepErrorAnchor. Any other request failure, such as the REST
		 * API being unavailable, is treated as inconclusive and never blocks the
		 * wizard; only the real submission is authoritative for those cases.
		 *
		 * @param {Array.<{field: string, value: string, $anchor: jQuery}>} checks
		 *   The fields to validate, each with the element to show or clear its error against.
		 * @param {jQuery} $stepErrorAnchor Element to show a request-level failure against.
		 * @return {jQuery.Promise} Resolves if the step may proceed, rejects if it may not.
		 */
		function validateFieldsViaRest( checks, $stepErrorAnchor ) {
			checks.forEach( ( check ) => {
				clearFieldError( check.$anchor );
			} );

			clearFieldError( $stepErrorAnchor );
			if ( !checks.length ) {
				return $.Deferred().resolve().promise();
			}

			return api.getToken( 'csrf' ).then( ( token ) => rest.post( '/createwiki/v0/request_wiki/validate', {
				checks: checks.map( ( check ) => ( { field: check.field, value: check.value } ) ),
				token: token
			} ) ).then( ( data ) => {
				checks.forEach( ( check ) => {
					const result = data.results && data.results[ check.field ];
					if ( result && !result.valid ) {
						showFieldError( check.$anchor, result.message );
					}
				} );
			}, ( errorCode, errorDetails ) => {
				const status = errorDetails && errorDetails.xhr && errorDetails.xhr.status;
				if ( status !== 403 && status !== 429 ) {
					return $.Deferred().resolve().promise();
				}

				const message = extractHttpErrorMessage( errorDetails.xhr );
				if ( message ) {
					showFieldError( $stepErrorAnchor, message );
				}

				return $.Deferred().reject().promise();
			} );
		}

		/**
		 * Validate every REST-validated, visible field within a step, plus any
		 * step-level checks (rate limiting, duplicate requests) that apply to it.
		 *
		 * @param {jQuery} $step The step to validate.
		 * @return {jQuery.Promise} Resolves if the step may proceed, rejects if it may not.
		 */
		function checkRestValidation( $step ) {
			const stepKey = $step.data( 'step' );
			const $stepErrorAnchor = $step.find( '.ext-createwiki-wizard-card-title' );
			const checks = [];
			const seenFields = new Set();

			/**
			 * Add a check for a field, skipping it if already queued this attempt.
			 *
			 * @param {string} field
			 * @param {string} value
			 * @param {jQuery} $anchor
			 * @return {void}
			 */
			function addCheck( field, value, $anchor ) {
				if ( seenFields.has( field ) ) {
					return;
				}

				seenFields.add( field );
				checks.push( { field: field, value: value, $anchor: $anchor } );
			}

			if ( stepKey === 'intro' ) {
				addCheck( 'ratelimited', '', $stepErrorAnchor );
			}

			if ( stepKey === 'basics' ) {
				const $sitename = $step.find( '[name="wpsitename"]' );
				if ( $sitename.length && isVisible( $sitename.get( 0 ) ) ) {
					addCheck( 'duplicate', $sitename.val(), $stepErrorAnchor );
				}
			}

			$step.find( '.ext-createwiki-wizard-rest-validate' ).each( function () {
				if ( !isVisible( this ) ) {
					return;
				}

				const $input = findNamedControl( $( this ) );
				const field = $input.get( 0 );
				if ( !field ) {
					return;
				}

				const value = field.type === 'checkbox' ? ( field.checked ? '1' : '' ) : $input.val();
				addCheck( field.name.slice( 2 ), value, $input );
			} );

			return validateFieldsViaRest( checks, $stepErrorAnchor );
		}

		/**
		 * Validate a step natively and via REST, then invoke a callback once it passes.
		 *
		 * @param {jQuery} $step The step to validate.
		 * @param {jQuery} $button The button to disable while validation is in progress.
		 * @param {Function} onValid Called with no arguments once the step is fully valid.
		 * @return {void}
		 */
		function validateStepThen( $step, $button, onValid ) {
			const $invalid = firstInvalidField( $step );
			if ( $invalid.length ) {
				$invalid.get( 0 ).reportValidity();
				return;
			}

			$button.prop( 'disabled', true );
			checkRestValidation( $step ).then( () => {
				$button.prop( 'disabled', false );
				const $errors = $step.find( '.ext-createwiki-wizard-field-error' );
				if ( $errors.length ) {
					$errors.get( 0 ).scrollIntoView( { behavior: 'smooth', block: 'center' } );
					return;
				}

				const $stillInvalid = firstInvalidField( $step );
				if ( $stillInvalid.length ) {
					$stillInvalid.get( 0 ).reportValidity();
					return;
				}

				onValid();
			}, () => {
				$button.prop( 'disabled', false );
				const $errors = $step.find( '.ext-createwiki-wizard-field-error' );
				if ( $errors.length ) {
					$errors.get( 0 ).scrollIntoView( { behavior: 'smooth', block: 'center' } );
				}
			} );
		}

		$next.on( 'click', () => {
			if ( current >= total - 1 ) {
				return;
			}

			validateThenGoToStep( $next, current + 1 );
		} );

		const $form = $wizard.closest( 'form' );
		$form.on( 'submit', ( e ) => {
			if ( current !== total - 1 ) {
				return;
			}

			e.preventDefault();
			validateStepThen( $steps.eq( current ), $submit, () => {
				$form.get( 0 ).submit();
			} );
		} );

		$back.on( 'click', () => {
			if ( current <= 0 ) {
				return;
			}

			history.back();
		} );

		$returnToReview.on( 'click', () => {
			validateThenGoToStep( $returnToReview, total - 1 );
		} );

		$( window ).on( 'popstate', ( e ) => {
			const step = stepFromState( e.originalEvent.state );
			if ( step === null ) {
				current = 0;
				updateView( true );
				replaceStepState( current );
				return;
			}

			current = step;
			updateView( true );
		} );

		$wizard.on( 'keydown', 'input:not([type="checkbox"]):not([type="radio"])', ( e ) => {
			if ( e.which !== 13 || current === total - 1 ) {
				return;
			}

			e.preventDefault();
			$next.trigger( 'click' );
		} );

		/**
		 * Attach a live "value changed" listener that clears a field's error optimistically.
		 *
		 * @param {jQuery} $marked The element carrying the REST-validate marker class.
		 * @param {jQuery} $input The resolved named control for that field.
		 * @return {void}
		 */
		function watchForChange( $marked, $input ) {
			let widget = null;
			try {
				widget = OO.ui.infuse( $marked );
			} catch ( e ) {
				widget = null;
			}

			if ( widget && typeof widget.on === 'function' ) {
				widget.on( 'change', () => {
					clearFieldError( $input );
				} );

				return;
			}

			$input.on( 'input change', () => {
				clearFieldError( $input );
			} );
		}

		$wizard.find( '.ext-createwiki-wizard-rest-validate' ).each( function () {
			const $marked = $( this );
			const $input = findNamedControl( $marked );
			if ( $input.length ) {
				watchForChange( $marked, $input );
			}
		} );

		const errorStep = findStepWithError();
		if ( errorStep > -1 ) {
			current = errorStep;
		}

		replaceStepState( current );
		updateView( errorStep > -1 );
	} );
}() );
