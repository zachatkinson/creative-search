/**
 * The Tools tab: while a rebuild runs, read its progress from GET /csearch/v1/status every few seconds and
 * reload the screen when it finishes, so the status table shows the new index.
 */
( function () {
	const box = document.getElementById( 'csearch-progress' );
	if ( ! box || box.dataset.building !== '1' || ! window.wp?.apiFetch ) {
		return;
	}
	const { __, sprintf } = window.wp.i18n;
	const bar = document.getElementById( 'csearch-progress-bar' );
	const text = document.getElementById( 'csearch-progress-text' );
	const format = ( n ) =>
		new Intl.NumberFormat(
			document.documentElement.lang || undefined
		).format( n );

	const poll = () => {
		window.wp
			.apiFetch( { path: '/csearch/v1/status' } )
			.then( ( status ) => {
				if ( status.building === null ) {
					window.location.reload();
					return;
				}
				const { offset, total, cancelled } = status.progress;
				bar.max = Math.max( 1, total );
				bar.value = offset;
				text.textContent = cancelled
					? sprintf(
							/* translators: 1: products done, 2: products in all */
							__(
								'%1$s of %2$s products (stopping)',
								'creative-search'
							),
							format( offset ),
							format( total )
						)
					: sprintf(
							/* translators: 1: products done, 2: products in all */
							__( '%1$s of %2$s products', 'creative-search' ),
							format( offset ),
							format( total )
						);
				window.setTimeout( poll, 3000 );
			} )
			.catch( () => window.setTimeout( poll, 10000 ) );
	};
	window.setTimeout( poll, 3000 );
} )();
