QUnit.module( 'ext.createwiki.oouiform', {
	beforeEach: function () {
		this.fixture = document.createElement( 'div' );
		document.body.appendChild( this.fixture );

		this.tabs = OO.ui.infuse( $( '.createwiki-tabs' ) );
		this.previousTab = mw.storage.session.get( 'createwiki-prevTab' );
	},
	afterEach: function () {
		mw.storage.session.remove( 'createwiki-prevTab' );
		mw.storage.session.set( 'createwiki-prevTab', this.previousTab );
	}
} );

QUnit.test( 'infuse tabs', function ( assert ) {
	assert.strictEqual( this.tabs, true, 'Tabs object exists' );
	assert.strictEqual( this.tabs.$element.hasClass( 'createwiki-tabs-infused' ), true, 'Tabs object has createwiki-tabs-infused class' );
} );

QUnit.test( 'switch tab', function ( assert ) {
	var currentTab, currentTabName;

	this.tabs.setTabPanel( '#mw-section-test' );
	currentTab = this.tabs.getCurrentTabPanel();
	currentTabName = currentTab.getName();

	assert.strictEqual( currentTabName, 'mw-section-test', 'Tab switched to mw-section-test' );
	assert.strictEqual( $( currentTab.$element ).data( 'mw-section-infused' ), true, 'Section mw-section-test is infused' );
} );

QUnit.test( 'set tab panel', function ( assert ) {
	var spy = this.tabs.setTabPanel;

	this.tabs.setTabPanel( '#mw-section-test' );

	assert.strictEqual( spy, true, 'setTabPanel method called once' );
	assert.strictEqual( this.tabs.setTabPanel( '#mw-section-test' ), spy, 'setTabPanel method is called' );
} );

QUnit.test( 'hashchange', function ( assert ) {
	var hash = '#mw-section-test';

	location.hash = hash;

	$( window ).trigger( 'hashchange' );

	assert.strictEqual( location.hash, hash, 'Location hash is set to mw-section-test' );
	assert.strictEqual( this.tabs.getCurrentTabPanel().getName(), 'mw-section-test', 'Tab panel is set to mw-section-test' );
} );

QUnit.test( 'detectHash', function ( assert ) {
	var hash = '#mw-section-test';

	location.hash = hash;

	detectHash();

	assert.strictEqual( this.tabs.getCurrentTabPanel().getName(), 'mw-section-test', 'Tab panel is set to mw-section-test' );
} );

QUnit.test( 'Restore previous tab', function ( assert ) {
	var tabs, previousTab, switchingNoHash, tab, activeTab;

	// Set up tabs
	tabs = OO.ui.infuse( $( '.createwiki-tabs' ) );
	tabs.$element.addClass( 'createwiki-tabs-infused' );

	// Set up a mocked previous tab
	previousTab = 'mw-section-previous';
	mw.storage.session.set( 'createwiki-prevTab', previousTab );

	// Trigger hashchange to restore previous tab
	$( window ).trigger( 'hashchange' );

	// Get the active tab
	tab = tabs.getCurrentTabPanel();
	activeTab = tab.getName();

	// Assert that the previous tab was correctly restored
	assert.strictEqual( activeTab, previousTab, 'Previous tab was correctly restored' );
} );
