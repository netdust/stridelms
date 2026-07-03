<?php

declare(strict_types=1);

/**
 * NTDST CORS Policy — the CORS decision surface.
 *
 * Threat-model attack 8 / mitigation 8: exact string identity via
 * in_array($origin, $list, true) against full scheme://host[:port] strings.
 * The literal 'null' and '' are rejected BEFORE any list/callable consult,
 * so a misconfigured allow-list (or an overly permissive callable resolver)
 * can never be tricked into matching an opaque/sandboxed-iframe origin.
 * '*' is refused at construction — this class never emits a wildcard.
 */

defined('ABSPATH') || exit;

final class NTDST_Cors_Policy
{
    /** @var list<string>|callable */
    private $origins;

    /** @var list<string> */
    private array $methods;

    /** @var list<string> */
    private array $headers;

    private ?int $max_age;

    /**
     * @param array{
     *     origins: list<string>|callable,
     *     methods?: list<string>,
     *     headers?: list<string>,
     *     max_age?: int|null,
     * } $config
     */
    public function __construct(array $config)
    {
        if (!array_key_exists('origins', $config)) {
            throw new InvalidArgumentException('NTDST_Cors_Policy requires an "origins" config key.');
        }

        $origins = $config['origins'];

        // Array checked BEFORE is_callable(): a plain list of two strings
        // (e.g. ['SomeClass', 'someMethod']) is technically PHP-callable and
        // must never be silently reinterpreted as a resolver instead of an
        // exact-match allow-list.
        if (is_array($origins)) {
            if (!array_is_list($origins)) {
                throw new InvalidArgumentException('NTDST_Cors_Policy "origins" must be a list of strings or a callable.');
            }
            foreach ($origins as $origin) {
                if (!is_string($origin)) {
                    throw new InvalidArgumentException('NTDST_Cors_Policy origins list must contain only strings.');
                }
                if ($origin === '*') {
                    throw new InvalidArgumentException('NTDST_Cors_Policy does not allow "*" as an origin — this class never emits a wildcard.');
                }
            }
            $this->origins = $origins;
        } elseif (is_callable($origins)) {
            $this->origins = $origins;
        } else {
            throw new InvalidArgumentException('NTDST_Cors_Policy "origins" must be a list of strings or a callable.');
        }

        $this->methods = $config['methods'] ?? ['GET', 'POST', 'OPTIONS'];
        $this->headers = $config['headers'] ?? ['Content-Type'];
        $this->max_age = $config['max_age'] ?? null;
    }

    /**
     * Decide whether $origin is allowed.
     *
     * The '' and 'null' guard runs BEFORE any list/callable consult — a
     * configured resolver that matches everything (or a list that
     * literally contains 'null'/'') can never approve an opaque origin.
     */
    public function allowsOrigin(string $origin, WP_REST_Request $request): bool
    {
        if ($origin === '' || $origin === 'null') {
            return false;
        }

        if (is_callable($this->origins)) {
            return (bool) ($this->origins)($origin, $request);
        }

        return in_array($origin, $this->origins, true);
    }

    /** @return list<string> */
    public function getMethods(): array
    {
        return $this->methods;
    }

    /** @return list<string> */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getMaxAge(): ?int
    {
        return $this->max_age;
    }
}
