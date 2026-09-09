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

		const total = $steps.length;
		if ( !total ) {
			return;
		}

		let current = 0;

		/**
		 * Read the step index this page's current history entry represents.
		 *
		 * @param {Object|null} state A History API state object.
		 * @return {number} Zero-based step index, defaulting to 0 if unset.
		 */
		function stepFromState( state ) {
			return state && typeof state.createwikiWizardStep === 'number' ? state.createwikiWizardStep : 0;
		}

		/**
		 * Push a new history entry recording the wizard's current step.
		 *
		 * @param {number} step Zero-based step index.
		 * @return {void}
		 */
		function pushStepState( step ) {
			history.pushState( { createwikiWizardStep: step }, '', location.href );
		}

		/**
		 * Replace the current history entry with the wizard's current step.
		 *
		 * @param {number} step Zero-based step index.
		 * @return {void}
		 */
		function replaceStepState( step ) {
			history.replaceState( { createwikiWizardStep: step }, '', location.href );
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

			$back.toggle( current > 0 );
			$next.toggle( current < total - 1 );
			$submit.toggle( current === total - 1 );

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
		 * Post a single field's value to the REST validation endpoint.
		 *
		 * @param {string} field The field key the server should validate.
		 * @param {string} value The current value to validate.
		 * @param {jQuery} $errorAnchor Element to show or clear the resulting error against.
		 * @return {jQuery.Promise} Resolves once the check has settled.
		 */
		function validateFieldViaRest( field, value, $errorAnchor ) {
			const rest = new mw.Rest();
			const api = new mw.Api();

			clearFieldError( $errorAnchor );

			return api.getToken( 'csrf' ).then( ( token ) => rest.post( '/createwiki/v0/request_wiki/validate', {
				field: field,
				value: value,
				token: token
			} ) ).then( ( data ) => {
				if ( !data.valid ) {
					showFieldError( $errorAnchor, data.message );
				}
			}, () => true );
		}

		/**
		 * Validate every REST-validated, visible field within a step, plus any
		 * step-level checks (rate limiting, duplicate requests) that apply to it.
		 *
		 * @param {jQuery} $step The step to validate.
		 * @return {jQuery.Promise} Resolves once every check has settled.
		 */
		function checkRestValidation( $step ) {
			const deferreds = [];
			const stepKey = $step.data( 'step' );

			if ( stepKey === 'intro' ) {
				deferreds.push( validateFieldViaRest(
					'pinglimiter', '', $step.find( '.ext-createwiki-wizard-card-title' )
				) );
			}

			if ( stepKey === 'basics' ) {
				const $sitename = $step.find( '[name="wpsitename"]' );
				if ( $sitename.length && isVisible( $sitename.get( 0 ) ) ) {
					deferreds.push( validateFieldViaRest(
						'duplicate', $sitename.val(), $step.find( '.ext-createwiki-wizard-card-title' )
					) );
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

				const fieldName = field.name.slice( 2 );
				const value = field.type === 'checkbox' ? ( field.checked ? '1' : '' ) : $input.val();

				deferreds.push( validateFieldViaRest( fieldName, value, $input ) );
			} );

			return $.when.apply( $, deferreds );
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
			} );
		}

		$next.on( 'click', () => {
			if ( current >= total - 1 ) {
				return;
			}

			validateStepThen( $steps.eq( current ), $next, () => {
				current++;
				updateView( true );
				pushStepState( current );
			} );
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

		$( window ).on( 'popstate', ( e ) => {
			current = stepFromState( e.originalEvent.state );
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
