<?php
/**
 * Service container.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search;

use InvalidArgumentException;
use ReflectionClass;
use ReflectionNamedType;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the plugin's services once and hands them out. Hand-written, no magic: constructor arguments are
 * resolved by class name, scalars take their default, interfaces are bound in BINDINGS.
 */
final class Container {

	/**
	 * Interface to implementation, so services can depend on the contract (the index store, the queue, the clock).
	 *
	 * @return array<class-string, class-string>
	 */
	private static function bindings(): array {
		return array();
	}

	/**
	 * Hookable classes, in registration order. Milestone 1 attaches nothing: the first hooks arrive with the indexer.
	 *
	 * @return class-string<Hookable>[]
	 */
	private static function hookableClasses(): array {
		return array();
	}

	/**
	 * Built instances by class name.
	 *
	 * @var array<string, object>
	 */
	private array $instances = array();

	/**
	 * Get (building on first use) a service.
	 *
	 * @template T of object
	 * @param class-string<T> $id Service class or interface.
	 * @return T
	 */
	public function get( string $id ) {
		/** @var T $instance */
		$instance = $this->resolve( $id );
		return $instance;
	}

	/**
	 * Get (building on first use) a service, untyped.
	 *
	 * @param string $id Service class or interface.
	 */
	public function resolve( string $id ): object {
		if ( ! isset( $this->instances[ $id ] ) ) {
			$this->instances[ $id ] = $this->build( $id );
		}
		return $this->instances[ $id ];
	}

	/**
	 * Replace a service (tests, and skatehouse-core when it swaps an implementation).
	 *
	 * @param string $id       Service class or interface.
	 * @param object $instance Replacement.
	 */
	public function set( string $id, object $instance ): void {
		$this->instances[ $id ] = $instance;
	}

	/**
	 * Whether a service has been built or set.
	 *
	 * @param string $id Service class or interface.
	 */
	public function has( string $id ): bool {
		return isset( $this->instances[ $id ] );
	}

	/**
	 * Every class that attaches hooks, built and ready.
	 *
	 * @return Hookable[]
	 */
	public function hookables(): array {
		$out = array();
		foreach ( self::hookableClasses() as $id ) {
			$service = $this->resolve( $id );
			if ( $service instanceof Hookable ) {
				$out[] = $service;
			}
		}
		return $out;
	}

	/**
	 * Build one service with its constructor dependencies resolved from this container.
	 *
	 * @param string $id Service class or interface.
	 * @throws InvalidArgumentException When the class or a constructor argument can't be resolved.
	 */
	private function build( string $id ): object {
		$class = self::bindings()[ $id ] ?? $id;
		if ( ! class_exists( $class ) && ! interface_exists( $class ) ) {
			throw new InvalidArgumentException( esc_html( sprintf( 'Unknown service %s.', $id ) ) );
		}
		$reflection = new ReflectionClass( $class );
		if ( ! $reflection->isInstantiable() ) {
			throw new InvalidArgumentException( esc_html( sprintf( 'Service %s is not instantiable and has no binding.', $id ) ) );
		}
		$constructor = $reflection->getConstructor();
		if ( null === $constructor ) {
			return new $class();
		}
		$args = array();
		foreach ( $constructor->getParameters() as $parameter ) {
			$type = $parameter->getType();
			if ( $type instanceof ReflectionNamedType && ! $type->isBuiltin() ) {
				/** @var class-string $dependency */
				$dependency = $type->getName();
				$args[]     = $this->resolve( $dependency );
				continue;
			}
			if ( $parameter->isDefaultValueAvailable() ) {
				$args[] = $parameter->getDefaultValue();
				continue;
			}
			throw new InvalidArgumentException( esc_html( sprintf( 'Cannot resolve parameter $%s of %s.', $parameter->getName(), $class ) ) );
		}
		return $reflection->newInstanceArgs( $args );
	}
}
