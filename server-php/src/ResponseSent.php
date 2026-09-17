<?php

declare(strict_types=1);

namespace Aicountly\Api;

/**
 * The response a controller produced, thrown instead of exiting under CLI.
 *
 * Http::json() exits under a web SAPI, which a test cannot observe. Throwing
 * lets the suite call a real controller — through the real auth, the real
 * permission assertion and the real tenant check — and assert on what came
 * back, rather than reaching past the controllers into the services where
 * those checks are not.
 */
final class ResponseSent extends \RuntimeException
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public readonly int $status,
        public readonly array $payload,
    ) {
        parent::__construct('HTTP ' . $status);
    }
}
