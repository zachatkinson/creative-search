<?php
/**
 * Container tests.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Unit;

use CreativeBranding\Search\Container;
use CreativeBranding\Search\Hookable;
use CreativeBranding\Search\Support\PluginException;
use PHPUnit\Framework\TestCase;

/**
 * Services are built once, dependencies by type, scalars by default; the unresolvable throws.
 */
final class ContainerTest extends TestCase {

	public function test_builds_once_and_resolves_dependencies_by_type(): void {
		$c = new Container();
		$a = $c->get( ContainerFixtureNeedsLeaf::class );
		self::assertInstanceOf( ContainerFixtureLeaf::class, $a->leaf );
		self::assertSame( $a, $c->get( ContainerFixtureNeedsLeaf::class ), 'the same instance every time' );
		self::assertSame( $a->leaf, $c->get( ContainerFixtureLeaf::class ), 'the dependency is the shared instance' );
		self::assertSame( 3, $a->retries, 'a scalar with a default takes the default' );
		self::assertTrue( $c->has( ContainerFixtureLeaf::class ) );
		self::assertFalse( $c->has( ContainerFixtureHookable::class ) );
	}

	public function test_set_replaces_a_service(): void {
		$c    = new Container();
		$leaf = new ContainerFixtureLeaf();
		$c->set( ContainerFixtureLeaf::class, $leaf );
		self::assertSame( $leaf, $c->get( ContainerFixtureNeedsLeaf::class )->leaf );
		self::assertTrue( $c->has( ContainerFixtureLeaf::class ) );
	}

	public function test_resolve_is_the_untyped_get(): void {
		$c = new Container();
		self::assertSame( $c->get( ContainerFixtureLeaf::class ), $c->resolve( ContainerFixtureLeaf::class ) );
	}

	public function test_unknown_class_throws(): void {
		$this->expectException( PluginException::class );
		$this->expectExceptionMessage( 'Unknown service Nope\Missing.' );
		( new Container() )->resolve( 'Nope\Missing' );
	}

	public function test_unbound_interface_throws(): void {
		$this->expectException( PluginException::class );
		$this->expectExceptionMessage( 'not instantiable' );
		( new Container() )->resolve( Hookable::class );
	}

	public function test_unresolvable_scalar_throws(): void {
		$this->expectException( PluginException::class );
		$this->expectExceptionMessage( 'Cannot resolve parameter $name of ' . ContainerFixtureNeedsScalar::class );
		( new Container() )->resolve( ContainerFixtureNeedsScalar::class );
	}

	public function test_hookables_are_built_once_in_registration_order(): void {
		$container = new Container();
		$hookables = $container->hookables();
		self::assertSame(
			array( \CreativeBranding\Search\Hooks\IndexHooks::class, \CreativeBranding\Search\Hooks\JobHooks::class, \CreativeBranding\Search\Hooks\CliHooks::class, \CreativeBranding\Search\Hooks\InstallHooks::class, \CreativeBranding\Search\Hooks\RestHooks::class, \CreativeBranding\Search\Hooks\LogHooks::class ),
			array_map( static fn( object $h ): string => $h::class, $hookables )
		);
		self::assertSame( $hookables, $container->hookables(), 'the same instances on a second call' );
	}

	public function test_the_index_store_is_bound_to_the_sql_repository(): void {
		self::assertInstanceOf( \CreativeBranding\Search\Repository\IndexRepository::class, ( new Container() )->get( \CreativeBranding\Search\Domain\IndexStore::class ) );
	}
}

/**
 * A service with no constructor.
 */
final class ContainerFixtureLeaf {
}

/**
 * A service that needs the leaf and a scalar with a default.
 */
final class ContainerFixtureNeedsLeaf {

	/**
	 * Constructor.
	 *
	 * @param ContainerFixtureLeaf $leaf    Dependency.
	 * @param int                  $retries Scalar with default.
	 */
	public function __construct( public ContainerFixtureLeaf $leaf, public int $retries = 3 ) {
	}
}

/**
 * A service the container cannot build.
 */
final class ContainerFixtureNeedsScalar {

	/**
	 * Constructor.
	 *
	 * @param string $name No default.
	 */
	public function __construct( public string $name ) {
	}
}

/**
 * A hookable nobody registers.
 */
final class ContainerFixtureHookable implements Hookable {

	/**
	 * Attach hooks.
	 */
	public function register(): void {
	}
}
