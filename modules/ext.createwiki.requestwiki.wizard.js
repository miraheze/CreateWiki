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

		const restValidatedFields = {
			agreement: 'wpagreement',
			category: 'wpcategory',
			purpose: 'wppurpose',
			reason: 'wpreason',
			subdomain: 'wpsubdomain',
		};

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
				$( this ).toggleClass( 'is-current', index === current );
				$( this ).toggleClass( 'is-complete', index < current );
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

		function checkRestValidation( $step ) {
			const rest = new mw.Rest();
			const api = new mw.Api();
			const deferreds = [];

			Object.keys( restValidatedFields ).forEach( ( field ) => {
				const $input = $step.find( '[name="' + restValidatedFields[ field ] + '"]' );
				if ( !$input.length ) {
					return;
				}

				clearFieldError( $input );

				const value = $input.get( 0 ).type === 'checkbox' ? ( $input.is( ':checked' ) ? '1' : '' ) : $input.val();

				deferreds.push( api.getToken( 'csrf' ).then( ( token ) => rest.post( '/createwiki/v0/request_wiki/validate', {
					field: field,
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

		$next.on( 'click', () => {
			if ( current >= total - 1 ) {
				return;
			}

			const $step = $steps.eq( current );
			const $invalid = firstInvalidField( $step );
			if ( $invalid.length ) {
				$invalid.get( 0 ).reportValidity();
				return;
			}

			$next.prop( 'disabled', true );

			checkRestValidation( $step ).then( () => {
				$next.prop( 'disabled', false );

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

				current++;
				updateView( true );
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
