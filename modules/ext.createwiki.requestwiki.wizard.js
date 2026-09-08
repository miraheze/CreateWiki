( function () {
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

		function findStepWithError() {
			let found = -1;
			$steps.each( function ( index ) {
				if ( found === -1 && $( this ).find( '.oo-ui-fieldLayout-messages-error, .cdx-message--error, .errorbox' ).length ) {
					found = index;
				}
			} );

			return found;
		}

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

		function fieldIsEmpty( field, $field ) {
			if ( field.type === 'checkbox' || field.type === 'radio' ) {
				return !field.checked;
			}

			return ( $field.val() || '' ).trim() === '';
		}

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

		function errorTarget( $input ) {
			const $wrapper = $input.closest( '.oo-ui-fieldLayout' );
			return $wrapper.length ? $wrapper : $input;
		}

		function clearFieldError( $input ) {
			errorTarget( $input ).next( '.ext-createwiki-wizard-field-error' ).remove();
		}

		function showFieldError( $input, message ) {
			clearFieldError( $input );

			errorTarget( $input ).after(
				$( '<div>' ).addClass( 'ext-createwiki-wizard-field-error' ).text( message )
			);
		}

		function isVisible( field ) {
			return field.offsetParent !== null;
		}

		function findNamedControl( $marked ) {
			const candidates = $marked.is( 'input, select, textarea' ) ?
				$marked :
				$marked.find( 'input, select, textarea' );

			return candidates.filter( function () {
				return this.name && this.name.indexOf( 'wp' ) === 0;
			} ).first();
		}

		function checkRestValidation( $step ) {
			const rest = new mw.Rest();
			const api = new mw.Api();
			const deferreds = [];

			$step.find( '.ext-createwiki-wizard-rest-validate' ).each( function () {
				const $input = findNamedControl( $( this ) );
				const field = $input.get( 0 );
				if ( !field || !isVisible( field ) ) {
					return;
				}

				const fieldName = field.name.slice( 2 );

				clearFieldError( $input );

				const value = field.type === 'checkbox' ? ( field.checked ? '1' : '' ) : $input.val();

				deferreds.push( api.getToken( 'csrf' ).then( ( token ) => rest.post( '/createwiki/v0/request_wiki/validate', {
					field: fieldName,
					value: value,
					token: token
				} ) ).then( ( data ) => {
					if ( !data.valid ) {
						showFieldError( $input, data.message );
					}
				}, () => true ) );
			} );

			return $.when.apply( $, deferreds );
		}

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

			current--;
			updateView( true );
		} );

		$wizard.on( 'keydown', 'input:not([type="checkbox"]):not([type="radio"])', ( e ) => {
			if ( e.which !== 13 || current === total - 1 ) {
				return;
			}

			e.preventDefault();
			$next.trigger( 'click' );
		} );

		const errorStep = findStepWithError();
		if ( errorStep > -1 ) {
			current = errorStep;
		}

		updateView( errorStep > -1 );
	} );
}() );
