<?php
/**
 * The i18n check (spec: Quality bar → i18n; D-083). Fails when the committed POT is missing a string the code
 * has, or keeps one it lost, and when a shipped translation has an empty, fuzzy or misplaced entry: every msgstr
 * filled, every plural form present, the placeholders of each form the same set as its source's, and the spacing
 * of its locale's punctuation (D-158: in fr_CA a no-break space before a colon and inside « »).
 *
 * Run: php tools/i18n-check.php tmp/i18n-check.pot languages/creative-search.pot languages/*.po
 * (`composer i18n:check` makes the first file from the code with WP-CLI in wp-env.)
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

require_once __DIR__ . '/i18n-lib.php';

/**
 * A readable name for an entry key: the msgid, with its context when it has one.
 *
 * @param string $key Context, EOT, msgid.
 */
function csearch_po_name( string $key ): string {
	[ $ctx, $id ] = explode( "\4", $key, 2 ) + array( '', '' );
	return '"' . $id . '"' . ( '' === $ctx ? '' : ' (context "' . $ctx . '")' );
}

/**
 * The placeholders of a string, as a sorted list (`%s`, `%1$s`, `%d`; `%%` is not one).
 *
 * @param string $text The text.
 * @return string[]
 */
function csearch_placeholders( string $text ): array {
	preg_match_all( '/%(?:\d+\$)?[sdfF]/', str_replace( '%%', '', $text ), $m );
	$found = $m[0];
	sort( $found );
	return $found;
}

[ , $csearch_fresh, $csearch_pot ] = $argv + array( '', '', '' );
$csearch_pos = array_slice( $argv, 3 );
foreach ( array_merge( array( $csearch_fresh, $csearch_pot ), $csearch_pos ) as $csearch_file ) {
	if ( '' === $csearch_file || ! is_readable( $csearch_file ) ) {
		fwrite( STDERR, "Usage: php tools/i18n-check.php <fresh.pot> <committed.pot> [translation.po ...]; cannot read '{$csearch_file}'.\n" );
		exit( 1 );
	}
}

$csearch_problems = array();
$csearch_code     = array_keys( csearch_po_entries( $csearch_fresh ) );
$csearch_kept     = array_keys( csearch_po_entries( $csearch_pot ) );
foreach ( array_diff( $csearch_code, $csearch_kept ) as $csearch_key ) {
	$csearch_problems[] = "{$csearch_pot}: missing " . csearch_po_name( $csearch_key ) . ' (run composer i18n:pot)';
}
foreach ( array_diff( $csearch_kept, $csearch_code ) as $csearch_key ) {
	$csearch_problems[] = "{$csearch_pot}: keeps " . csearch_po_name( $csearch_key ) . ', which the code no longer has (run composer i18n:pot)';
}
foreach ( $csearch_pos as $csearch_po ) {
	$csearch_entries = csearch_po_entries( $csearch_po );
	$csearch_locale  = csearch_po_locale( $csearch_po );
	foreach ( array_diff( $csearch_kept, array_keys( $csearch_entries ) ) as $csearch_key ) {
		$csearch_problems[] = "{$csearch_po}: has no entry for " . csearch_po_name( $csearch_key ) . ' (run composer i18n:po)';
	}
	foreach ( $csearch_entries as $csearch_entry ) {
		$csearch_name = '"' . $csearch_entry['id'] . '"';
		if ( $csearch_entry['fuzzy'] ) {
			$csearch_problems[] = "{$csearch_po}: {$csearch_name} is fuzzy";
		}
		$csearch_forms = null === $csearch_entry['plural'] ? array( 0 ) : array( 0, 1 );
		foreach ( $csearch_forms as $csearch_n ) {
			$csearch_str = $csearch_entry['strs'][ $csearch_n ] ?? '';
			if ( '' === $csearch_str ) {
				$csearch_problems[] = "{$csearch_po}: {$csearch_name} form {$csearch_n} is not translated";
				continue;
			}
			$csearch_source = 1 === $csearch_n ? (string) $csearch_entry['plural'] : $csearch_entry['id'];
			$csearch_want = csearch_placeholders( $csearch_source );
			$csearch_have = csearch_placeholders( $csearch_str );
			if ( $csearch_want !== $csearch_have ) {
				$csearch_problems[] = "{$csearch_po}: {$csearch_name} form {$csearch_n} has placeholders " . implode( ' ', $csearch_have ) . ', the source ' . implode( ' ', $csearch_want );
			}
			foreach ( csearch_typography( $csearch_locale, $csearch_str ) as $csearch_typo ) {
				$csearch_problems[] = "{$csearch_po}: {$csearch_name} form {$csearch_n} {$csearch_typo}";
			}
		}
	}
}

if ( array() !== $csearch_problems ) {
	fwrite( STDERR, implode( "\n", $csearch_problems ) . "\n" . count( $csearch_problems ) . " i18n problem(s).\n" );
	exit( 1 );
}
echo 'i18n: ' . count( $csearch_kept ) . ' strings, the POT current, ' . count( $csearch_pos ) . " translation(s) complete.\n";
