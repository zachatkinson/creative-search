<?php
/**
 * Browser-test fixture for Field mode in a sticky site header (D-125 to D-127): a header fixed to the top with a
 * transform and its own z-index, as Kadence's sticky header is, holding the search block; theme-like input rules,
 * focus rings among them; and enough of a page to scroll. A second page has the same header with the page dimming
 * switched off. Run with `wp eval-file` after shop.php; the pages are rewritten each time.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

// An administrator, so the page's own <style> is kept (unfiltered_html).
wp_set_current_user( 1 );

$csearch_style = '<!-- wp:html --><style>'
	. '.csearch-test-header{position:fixed;inset-block-start:0;inset-inline:0;z-index:1200;transform:translateY(0);background:#fff;padding:8px 16px;box-shadow:0 1px 4px rgb(0 0 0 / 20%)}'
	. '.csearch-test-body{margin-block-start:140px}'
	. 'input[type="text"]{background:#f2f2f2;border:1px solid #999;border-radius:3px;padding:12px 16px;height:52px;max-width:200px;box-shadow:inset 0 1px 2px rgb(0 0 0 / 20%);font-size:14px}'
	. 'input[type="text"]:focus,input:focus{outline:3px solid #c00;outline-offset:2px;box-shadow:0 0 0 3px #00f;border-color:#00f;background:#fff}'
	. '</style><!-- /wp:html -->';

$csearch_body = '';
for ( $csearch_i = 1; $csearch_i <= 30; $csearch_i++ ) {
	$csearch_body .= '<!-- wp:paragraph --><p>Paragraph ' . $csearch_i . ' of the page behind the header.</p><!-- /wp:paragraph -->';
}

foreach ( array(
	'sticky-header'       => '<!-- wp:creative-search/search /-->',
	'sticky-header-nodim' => '<!-- wp:creative-search/search {"dimPage":false} /-->',
) as $csearch_slug => $csearch_block ) {
	$csearch_page = array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_name'    => $csearch_slug,
		'post_title'   => 'Sticky header',
		'post_content' => $csearch_style
			. '<!-- wp:group {"className":"csearch-test-header"} --><div class="wp-block-group csearch-test-header">' . $csearch_block . '</div><!-- /wp:group -->'
			. '<!-- wp:group {"className":"csearch-test-body"} --><div class="wp-block-group csearch-test-body">' . $csearch_body . '</div><!-- /wp:group -->',
	);
	$csearch_existing = get_page_by_path( $csearch_slug );
	if ( $csearch_existing instanceof WP_Post ) {
		$csearch_page['ID'] = $csearch_existing->ID;
		wp_update_post( wp_slash( $csearch_page ) );
	} else {
		wp_insert_post( wp_slash( $csearch_page ) );
	}
}
