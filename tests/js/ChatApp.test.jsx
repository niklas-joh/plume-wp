/**
 * Unit tests for ChatApp — the root chat shell component.
 *
 * @see src/admin/components/Chat/ChatApp.jsx
 */
import React from 'react';
import { act } from 'react-dom/test-utils';
import ReactDOM from 'react-dom';
import ChatApp from '../../src/admin/components/Chat/ChatApp';

// @wordpress/api-fetch is treated as an external by webpack and is not
// installed as a standalone npm package. Mock the module so Jest can resolve
// it; all tests that care about fetch behaviour control it via this mock.
jest.mock( '@wordpress/api-fetch', () => jest.fn() );

// @wordpress/element re-exports React hooks. Mock it to forward to React so
// useState/useEffect/useRef are available in the jsdom environment.
jest.mock( '@wordpress/element', () => ( {
	...jest.requireActual( 'react' ),
	render: jest.requireActual( 'react-dom' ).render,
} ) );

// @wordpress/i18n is not installed standalone — provide a minimal stub.
jest.mock( '@wordpress/i18n', () => ( {
	__: ( str ) => str,
	_x: ( str ) => str,
	_n: ( singular ) => singular,
} ) );

// @wordpress/components renders heavy UI widgets not needed for these tests.
jest.mock( '@wordpress/components', () => ( {
	Button: ( { children, onClick, disabled } ) => (
		<button onClick={ onClick } disabled={ disabled }>{ children }</button>
	),
	SelectControl: ( { label, value, onChange, options = [] } ) => (
		<select aria-label={ label } value={ value } onChange={ ( e ) => onChange( e.target.value ) }>
			{ options.map( ( o ) => <option key={ o.value } value={ o.value }>{ o.label }</option> ) }
		</select>
	),
} ) );

// lucide-react icons — stub every named export as a no-op span.
jest.mock( 'lucide-react', () =>
	new Proxy( {}, { get: () => () => <span /> } )
);

// Storage utility used by ChatApp to persist sidebar collapse state.
jest.mock( '../../src/admin/utils/storage', () => ( {
	storageGet: jest.fn( () => null ),
	storageSet: jest.fn(),
} ) );

// Provide the global wpAiMindData the component reads on initialisation
// (ChatApp.jsx line 36).
beforeAll( () => {
	global.window.wpAiMindData = {
		isPro: false,
		defaultProvider: 'claude',
		restUrl: 'http://localhost/wp-json/wp-ai-mind/v1',
		nonce: 'test-nonce',
	};
} );

afterAll( () => {
	delete global.window.wpAiMindData;
} );

describe( 'ChatApp', () => {
	let container;

	beforeEach( () => {
		// apiFetch resolves with empty arrays by default so the component does
		// not enter an error state during mount.
		const apiFetch = require( '@wordpress/api-fetch' );
		apiFetch.mockResolvedValue( [] );

		container = document.createElement( 'div' );
		document.body.appendChild( container );
	} );

	afterEach( () => {
		act( () => {
			ReactDOM.unmountComponentAtNode( container );
		} );
		document.body.removeChild( container );
	} );

	it( 'renders without throwing', async () => {
		// The component makes apiFetch calls on mount; wrap in act so React
		// flushes effects and state updates before we make assertions.
		await act( async () => {
			ReactDOM.render( <ChatApp />, container );
		} );

		// .wpaim-shell is the root CSS class (ChatApp.jsx line 297).
		expect( container.querySelector( '.wpaim-shell' ) ).not.toBeNull();
	} );

	it( 'renders the composer input in the DOM', async () => {
		await act( async () => {
			ReactDOM.render( <ChatApp />, container );
		} );

		// The launch view's Composer always renders .wpaim-composer__input
		// (Composer.jsx line 98 — a <textarea>).
		const input = container.querySelector( '.wpaim-composer__input' );
		expect( input ).not.toBeNull();
		expect( input.tagName.toLowerCase() ).toBe( 'textarea' );
	} );

	it( 'renders the sidebar element', async () => {
		await act( async () => {
			ReactDOM.render( <ChatApp />, container );
		} );

		// ChatApp.jsx line 301 — <aside className="wpaim-sidebar">.
		expect( container.querySelector( '.wpaim-sidebar' ) ).not.toBeNull();
	} );
} );
