<?php

declare(strict_types=1);

/**
 * NTDST REST Registrar — namespaced REST route registration (INV-10).
 *
 * This is the Phase 3 convergence point being built up in stages:
 *  - 3.1 (this file): registration core + required-permission enforcement.
 *  - 3.2: permission_callback memoization (WP-core quirk 2).
 *  - 3.3: request-body size caps.
 *  - 3.4: handler-return normalization + Router::rest() facade + CORS wiring.
 *
 * Route syntax is WP-native regex (D5) — routes/methods are passed straight
 * through to register_rest_route(), never translated or reinterpreted.
 *
 * `permission` is a REQUIRED option with NO default (threat-model mitigation
 * 6). A route queued without a callable `permission` is never registered —
 * register_rest_route() is not called for it at all, _doing_it_wrong() fires,
 * and the failure is logged via ntdst_log('api')->error(). There is no
 * "public route" fallback baked in here: a caller that genuinely wants an
 * unauthenticated route must pass an explicit always-true permission
 * callable, so the intent is visible in the call site rather than implied by
 * a missing option.
 */

defined('ABSPATH') || exit;

final class NTDST_Rest_Registrar
{
    private string $namespace;

    /**
     * Routes queued for registration, drained by flush().
     *
     * @var list<array{route: string, methods: string|list<string>, handler: callable, options: array}>
     */
    private array $queue = [];

    /**
     * Whether this instance has already hooked flush() onto rest_api_init —
     * guards against double add_action() if get/post/etc. are called
     * repeatedly after rest_api_init already fired (each of those calls
     * registers immediately in that case and never needs the hook).
     */
    private bool $hooked = false;

    public function __construct(string $namespace)
    {
        $this->namespace = $namespace;
    }

    public function get(string $route, callable $handler, array $options = []): self
    {
        return $this->route($route, 'GET', $handler, $options);
    }

    public function post(string $route, callable $handler, array $options = []): self
    {
        return $this->route($route, 'POST', $handler, $options);
    }

    public function put(string $route, callable $handler, array $options = []): self
    {
        return $this->route($route, 'PUT', $handler, $options);
    }

    public function patch(string $route, callable $handler, array $options = []): self
    {
        return $this->route($route, 'PATCH', $handler, $options);
    }

    public function delete(string $route, callable $handler, array $options = []): self
    {
        return $this->route($route, 'DELETE', $handler, $options);
    }

    /**
     * @param string|list<string> $methods
     */
    public function route(string $route, string|array $methods, callable $handler, array $options = []): self
    {
        $entry = [
            'route' => $route,
            'methods' => $methods,
            'handler' => $handler,
            'options' => $options,
        ];

        // D1✦ (doubt-pass correction) — the silent-no-op trap: if
        // rest_api_init has ALREADY fired (e.g. this registrar is built
        // lazily, after WP's own bootstrap sequence has passed the hook),
        // queueing the route and waiting for a flush() that add_action()
        // would schedule for a rest_api_init that will never fire again
        // means the route silently never registers. Registering
        // immediately in that case is what makes route()/get()/post()/etc.
        // safe to call at any time in the request lifecycle.
        if (did_action('rest_api_init')) {
            $this->registerOne($entry);
            return $this;
        }

        $this->queue[] = $entry;
        $this->ensureHooked();

        return $this;
    }

    /**
     * Hook flush() onto rest_api_init exactly once per instance.
     */
    private function ensureHooked(): void
    {
        if ($this->hooked) {
            return;
        }

        $this->hooked = true;
        add_action('rest_api_init', [$this, 'flush']);
    }

    /**
     * Drain the queue, registering every route via register_rest_route().
     * Idempotent: once the queue is empty, a repeat call is a no-op — a
     * route is only ever registered once per queue() call, never
     * re-registered by a second flush() (e.g. a defensive direct call, or
     * rest_api_init somehow firing twice).
     */
    public function flush(): void
    {
        $pending = $this->queue;
        $this->queue = [];

        foreach ($pending as $entry) {
            $this->registerOne($entry);
        }
    }

    /**
     * @param array{route: string, methods: string|list<string>, handler: callable, options: array} $entry
     */
    private function registerOne(array $entry): void
    {
        $options = $entry['options'];

        $permission = $options['permission'] ?? null;

        if (!is_callable($permission)) {
            // Mitigation 6: a route with no callable permission is never
            // handed to register_rest_route() at all — fail closed, not
            // open. Surfaced loudly (both to developers via
            // _doing_it_wrong() and to logs) rather than silently skipped,
            // so a missing permission is caught in development, not
            // discovered as a live open route.
            _doing_it_wrong(
                self::class . '::route',
                sprintf(
                    'Route "%s%s" was not registered — "permission" is a required option and must be a callable. Refusing to register a REST route with no permission check.',
                    $this->namespace,
                    $entry['route'],
                ),
                '1.0.0',
            );

            ntdst_log('api')->error(
                sprintf('REST route registration refused — missing/non-callable permission for %s%s', $this->namespace, $entry['route']),
                [
                    'namespace' => $this->namespace,
                    'route' => $entry['route'],
                    'methods' => $entry['methods'],
                ],
            );

            return;
        }

        $args = [
            'methods' => $entry['methods'],
            'callback' => $entry['handler'],
            'permission_callback' => $permission,
        ];

        if (array_key_exists('args', $options)) {
            $args['args'] = $options['args'];
        }

        register_rest_route($this->namespace, $entry['route'], $args);
    }
}
