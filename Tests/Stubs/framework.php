<?php

/**
 * Laravel-shaped doubles.
 *
 * The service provider is the half of this module that can actually break an
 * install, so it needs real tests - not assertions about its source text. It
 * only touches a small, stable surface of Laravel and FreeScout, and these
 * doubles reproduce exactly that surface so the provider can be booted, its
 * hooks recorded, and the recorded closures invoked.
 *
 * Loaded via composer's autoload-dev "files", so they exist before the
 * provider is autoloaded. They are dev-only and never ship: Tests/ is
 * export-ignored from the release archive.
 *
 * Bracketed namespaces because this file has to declare into the global
 * namespace (Eventy, Log, collect()) alongside namespaced classes.
 *
 * phpcs:ignoreFile -- deliberate multi-class file; these doubles must occupy
 * the same namespaces as the real things they stand in for.
 */

namespace Tests\Support {

    /**
     * Records what the provider registered, so tests can assert on the
     * registration and then invoke the callback.
     */
    class HookRecorder
    {
        /** @var array<string, array<int, array{cb: callable, priority: int, args: int}>> */
        public static $filters = [];

        /** @var array<string, array<int, array{cb: callable, priority: int, args: int}>> */
        public static $actions = [];

        public static function reset(): void
        {
            self::$filters = [];
            self::$actions = [];
        }

        public static function filter(string $hook): array
        {
            if (!isset(self::$filters[$hook])) {
                throw new \RuntimeException("No filter registered for '{$hook}'.");
            }

            return self::$filters[$hook][0];
        }

        public static function action(string $hook): array
        {
            if (!isset(self::$actions[$hook])) {
                throw new \RuntimeException("No action registered for '{$hook}'.");
            }

            return self::$actions[$hook][0];
        }
    }

    /**
     * Minimal stand-in for Illuminate's Collection: the provider only ever
     * returns an empty one, and tests only ever count it.
     */
    class FakeCollection implements \Countable, \IteratorAggregate
    {
        /** @var array */
        private $items;

        public function __construct(array $items = [])
        {
            $this->items = $items;
        }

        public function count(): int
        {
            return count($this->items);
        }

        public function getIterator(): \Traversable
        {
            return new \ArrayIterator($this->items);
        }

        public function all(): array
        {
            return $this->items;
        }
    }
}

namespace Illuminate\Support {

    /**
     * The provider extends this and calls nothing on it.
     */
    class ServiceProvider
    {
        /** @var mixed */
        protected $app;

        public function __construct($app = null)
        {
            $this->app = $app;
        }
    }
}

namespace {

    /**
     * FreeScout exposes the hook system through this global facade.
     */
    class Eventy
    {
        public static function addFilter($hook, $callback, $priority = 20, $args = 1): void
        {
            \Tests\Support\HookRecorder::$filters[$hook][] = [
                'cb' => $callback,
                'priority' => $priority,
                'args' => $args,
            ];
        }

        public static function addAction($hook, $callback, $priority = 20, $args = 1): void
        {
            \Tests\Support\HookRecorder::$actions[$hook][] = [
                'cb' => $callback,
                'priority' => $priority,
                'args' => $args,
            ];
        }
    }

    /**
     * Laravel's Log facade. The provider only ever writes to it from a catch
     * block; tests assert that a failure was reported rather than swallowed.
     */
    class Log
    {
        /** @var string[] */
        public static $errors = [];

        public static function error($message): void
        {
            self::$errors[] = (string) $message;
        }

        public static function reset(): void
        {
            self::$errors = [];
        }
    }

    if (!function_exists('collect')) {
        function collect(array $items = [])
        {
            return new \Tests\Support\FakeCollection($items);
        }
    }
}
