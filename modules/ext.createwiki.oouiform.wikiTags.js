( function () {
	$( function () {
		var $fallbackLayout = $( '.createwiki-wikitags.oo-ui-layout' );
		var $fallbackInput = $( '#mw-input-wpwikitags' );

		var infusedFallbackLayout = OO.ui.infuse( $fallbackLayout );
		var infusedFallbackInput = OO.ui.infuse( $fallbackInput );

		infusedFallbackLayout.$element.css( 'display', 'none' );

		var tags = Object.entries( mw.config.get( 'wgCreateWikiAvailableTags' ) ).map( function ( val ) {
			return {
				data: val[ 0 ],
				label: val[ 1 ]
			};
		} );

		var multiTagSelect = new OO.ui.MenuTagMultiselectWidget( {
			options: tags,
			tagLimit: 5
		} );

		var multiTagSelectLayout = new OO.ui.FieldLayout( multiTagSelect, {
			label: mw.msg( 'createwiki-label-wiki-tags' ),
			align: 'top'
		} );

		multiTagSelect.on( 'change', function ( items ) {
			// Map selection changes back to the fallback input so that it is included in form submit
			infusedFallbackInput.setValue( items.map( function ( val ) {
				return val.data;
			} ).join( ',' ) );
		} );

		infusedFallbackLayout.$element.before( multiTagSelectLayout.$element );
	} );
}() );
