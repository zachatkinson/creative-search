/**
 * Where the suggestion panel goes and how tall it may be, and where the page's dimming layer must sit: pure
 * arithmetic on measured boxes, so it is tested without a browser (D-126, D-127). The view measures and applies.
 */

// The panel keeps at least two rows when the room is smaller still (the shopper scrolls inside it).
export const PANEL_MIN = 96;

// The gap between the field and the panel, in pixels (half of --csearch-gap at the default 16px root).
export const PANEL_GAP = 4;

/**
 * The room above and below the field inside the visible viewport, which shrinks when the on-screen keyboard
 * opens (`visualViewport`); without it, the window.
 *
 * @param {{top: number, bottom: number}}            field    The field's box, in layout-viewport pixels.
 * @param {{offsetTop: number, height: number}|null} viewport `window.visualViewport`, or null.
 * @param {number}                                   fallback `window.innerHeight`.
 * @return {{above: number, below: number}} Pixels free on each side, never negative.
 */
export function roomAround( field, viewport, fallback ) {
	const top = viewport ? viewport.offsetTop : 0;
	const height = viewport ? viewport.height : fallback;
	return {
		above: Math.max( 0, field.top - top - PANEL_GAP ),
		below: Math.max( 0, top + height - field.bottom - PANEL_GAP ),
	};
}

/**
 * Whether the panel opens above the field, and the height it is capped to ('' for its natural height).
 *
 * Capped when the field is in a fixed or sticky header, or on a touch screen: there the page cannot scroll the
 * rows behind the keyboard into view, so the panel scrolls inside itself instead. Otherwise (a desktop page that
 * scrolls) it keeps its natural height and opens below unless the owner chose above (D-092).
 *
 * @param {Object}                         options
 * @param {string}                         options.position `auto`, `below` or `above`.
 * @param {boolean}                        options.coarse   A touch screen.
 * @param {boolean}                        options.pinned   A fixed or sticky ancestor.
 * @param {{above: number, below: number}} options.room     From roomAround().
 * @param {number}                         options.needed   The room the panel wants below before it flips.
 * @return {{above: boolean, capped: boolean, max: string}} The placement.
 */
export function place( { position, coarse, pinned, room, needed } ) {
	if ( ! coarse && ! pinned ) {
		return { above: position === 'above', capped: false, max: '' };
	}
	// Above only when chosen, or when the room below is short and there is more of it above.
	const above =
		position === 'above' ||
		( position === 'auto' &&
			room.below < needed &&
			room.above > room.below );
	const space = above ? room.above : room.below;
	return {
		above,
		capped: true,
		max: `${ Math.floor( Math.max( PANEL_MIN, space ) ) }px`,
	};
}

/**
 * The scroll position that brings a row fully into a panel that scrolls inside itself, moving as little as it
 * can; the page itself is never scrolled (it cannot help in a fixed header).
 *
 * @param {number} scrollTop The panel's scroll position.
 * @param {number} height    The panel's visible height.
 * @param {number} rowTop    The row's top, from the top of the panel's content.
 * @param {number} rowHeight The row's height.
 * @return {number} The new scroll position.
 */
export function scrollToRow( scrollTop, height, rowTop, rowHeight ) {
	if ( rowTop < scrollTop ) {
		return rowTop;
	}
	if ( rowTop + rowHeight > scrollTop + height ) {
		return rowTop + rowHeight - height;
	}
	return scrollTop;
}

/**
 * Where the dimming layer must sit to cover the viewport. It is `position: fixed; inset: 0`, but an ancestor
 * with a transform (a header that slides away on scroll, as Kadence's does) becomes its containing block, so it
 * would cover only that header. Measured where it landed, it is moved back over the whole viewport.
 *
 * @param {{top: number, left: number, width: number, height: number}} box    The layer's box as laid out.
 * @param {number}                                                     width  The viewport's width.
 * @param {number}                                                     height The viewport's height.
 * @return {{top: string, left: string, width: string, height: string}|null} Styles, or null when it already covers it.
 */
export function coverViewport( box, width, height ) {
	const near = ( a, b ) => Math.abs( a - b ) < 1;
	if (
		near( box.top, 0 ) &&
		near( box.left, 0 ) &&
		near( box.width, width ) &&
		near( box.height, height )
	) {
		return null;
	}
	return {
		top: `${ -box.top }px`,
		left: `${ -box.left }px`,
		width: `${ width }px`,
		height: `${ height }px`,
	};
}

/**
 * Whether an element sits in a fixed or sticky box: it then moves with the viewport, not the page.
 *
 * @param {Element|null}                        element The block.
 * @param {(el: Element) => {position: string}} styleOf `window.getComputedStyle`.
 * @return {boolean} True when an ancestor (or the element) is fixed or sticky.
 */
export function pinnedIn( element, styleOf ) {
	for ( let el = element; el && el.nodeType === 1; el = el.parentElement ) {
		const position = styleOf( el ).position;
		if ( position === 'fixed' || position === 'sticky' ) {
			return true;
		}
	}
	return false;
}
