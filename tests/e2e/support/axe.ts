import { expect, Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

/**
 * Fails on serious and critical WCAG 2.2 AA violations inside a region; shared by every spec.
 *
 * @param page    The page.
 * @param name    What is being checked, for the message.
 * @param include The CSS selector of the region.
 * @param exempt  Rules not run here, each with its reason in docs/DECISIONS.md at the call.
 */
export async function axe(
	page: Page,
	name: string,
	include = 'body',
	exempt: string[] = []
) {
	const results = await new AxeBuilder( { page } )
		.withTags( [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa' ] )
		.include( include )
		.disableRules( exempt )
		.analyze();
	const serious = results.violations.filter(
		( v ) => v.impact === 'serious' || v.impact === 'critical'
	);
	expect(
		serious,
		`${ name }: ${ JSON.stringify(
			serious.map( ( v ) => ( {
				id: v.id,
				nodes: v.nodes.map( ( n ) => n.html ),
			} ) ),
			null,
			2
		) }`
	).toEqual( [] );
}
