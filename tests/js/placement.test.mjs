/**
 * The panel's placement and the dimming layer's cover, as arithmetic (D-126, D-127).
 */
import { test } from 'vitest';
import assert from 'node:assert/strict';
import {
	PANEL_GAP,
	PANEL_MIN,
	coverViewport,
	pinnedIn,
	place,
	roomAround,
	scrollToRow,
} from '../../assets/src/block/placement.js';

test( 'the room around the field is measured in the visible viewport, the keyboard taken off', () => {
	const field = { top: 60, bottom: 108 };
	assert.deepEqual( roomAround( field, null, 800 ), {
		above: 60 - PANEL_GAP,
		below: 800 - 108 - PANEL_GAP,
	} );
	// The keyboard covers the lower half: the visible viewport is 400 tall.
	assert.deepEqual( roomAround( field, { offsetTop: 0, height: 400 }, 800 ), {
		above: 56,
		below: 400 - 108 - PANEL_GAP,
	} );
	// iOS scrolls the visible viewport within the layout viewport.
	assert.deepEqual(
		roomAround( field, { offsetTop: 50, height: 400 }, 800 ),
		{ above: 6, below: 450 - 108 - PANEL_GAP }
	);
	assert.deepEqual(
		roomAround( { top: -10, bottom: 900 }, null, 800 ),
		{ above: 0, below: 0 },
		'never negative'
	);
} );

test( 'a desktop page that scrolls keeps the natural height', () => {
	const room = { above: 100, below: 50 };
	for ( const position of [ 'auto', 'below' ] ) {
		assert.deepEqual(
			place( {
				position,
				coarse: false,
				pinned: false,
				room,
				needed: 240,
			} ),
			{ above: false, capped: false, max: '' }
		);
	}
	assert.deepEqual(
		place( {
			position: 'above',
			coarse: false,
			pinned: false,
			room,
			needed: 240,
		} ),
		{ above: true, capped: false, max: '' }
	);
} );

test( 'a sticky header or a touch screen caps the panel to the room it opens into', () => {
	assert.deepEqual(
		place( {
			position: 'auto',
			coarse: false,
			pinned: true,
			room: { above: 20, below: 612.6 },
			needed: 240,
		} ),
		{ above: false, capped: true, max: '612px' }
	);
	assert.deepEqual(
		place( {
			position: 'below',
			coarse: true,
			pinned: false,
			room: { above: 500, below: 30 },
			needed: 240,
		} ),
		{ above: false, capped: true, max: `${ PANEL_MIN }px` },
		'below as chosen, with two rows at least'
	);
	assert.deepEqual(
		place( {
			position: 'above',
			coarse: true,
			pinned: false,
			room: { above: 300, below: 400 },
			needed: 240,
		} ),
		{ above: true, capped: true, max: '300px' }
	);
} );

test( 'auto flips above only when the room below is short and there is more above', () => {
	const at = ( above, below ) =>
		place( {
			position: 'auto',
			coarse: true,
			pinned: false,
			room: { above, below },
			needed: 240,
		} ).above;
	assert.equal( at( 500, 200 ), true, 'short below, more above' );
	assert.equal( at( 150, 200 ), false, 'short below, but less above' );
	assert.equal( at( 200, 200 ), false, 'as much above: stays below' );
	assert.equal( at( 900, 240 ), false, 'enough below' );
	assert.equal( at( 900, 239 ), true );
} );

test( 'a row is brought into a scrolling panel moving as little as it can', () => {
	assert.equal( scrollToRow( 0, 200, 50, 48 ), 0, 'already in view' );
	assert.equal( scrollToRow( 0, 200, 152, 48 ), 0, 'just fits' );
	assert.equal(
		scrollToRow( 0, 200, 180, 48 ),
		28,
		'below: its bottom at the edge'
	);
	assert.equal(
		scrollToRow( 100, 200, 40, 48 ),
		40,
		'above: its top at the edge'
	);
	assert.equal( scrollToRow( 100, 200, 100, 48 ), 100 );
} );

test( 'the dimming layer is moved back over the viewport only when a transformed header holds it', () => {
	assert.equal(
		coverViewport(
			{ top: 0, left: 0, width: 1280, height: 720 },
			1280,
			720
		),
		null
	);
	assert.equal(
		coverViewport(
			{ top: 0.4, left: -0.4, width: 1280.5, height: 719.5 },
			1280,
			720
		),
		null,
		'within a pixel'
	);
	// Held by an 80px header slid 20px up: the layer is the header's box.
	assert.deepEqual(
		coverViewport(
			{ top: -20, left: 0, width: 1280, height: 80 },
			1280,
			720
		),
		{ top: '20px', left: '0px', width: '1280px', height: '720px' }
	);
	assert.deepEqual(
		coverViewport(
			{ top: 100, left: 16, width: 300, height: 48 },
			390,
			664
		),
		{ top: '-100px', left: '-16px', width: '390px', height: '664px' }
	);
} );

test( 'a fixed or sticky ancestor pins the block to the viewport', () => {
	const tree = ( ...positions ) => {
		let parent = null;
		const nodes = positions.map( ( position ) => {
			const node = { nodeType: 1, position, parentElement: parent };
			parent = node;
			return node;
		} );
		return nodes[ nodes.length - 1 ];
	};
	const styleOf = ( el ) => ( { position: el.position } );
	assert.equal(
		pinnedIn( tree( 'static', 'relative', 'static' ), styleOf ),
		false
	);
	assert.equal(
		pinnedIn( tree( 'sticky', 'static', 'relative' ), styleOf ),
		true
	);
	assert.equal( pinnedIn( tree( 'static', 'fixed' ), styleOf ), true );
	assert.equal( pinnedIn( null, styleOf ), false );
	const doc = { nodeType: 9, position: 'fixed', parentElement: null };
	assert.equal(
		pinnedIn(
			{ nodeType: 1, position: 'static', parentElement: doc },
			styleOf
		),
		false,
		'the document node is not an element'
	);
} );
