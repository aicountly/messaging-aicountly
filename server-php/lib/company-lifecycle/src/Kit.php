<?php
declare(strict_types=1);

namespace Aicountly\Lifecycle;

use PDO;

/**
 * One object a product builds once and asks for the pieces it needs, so framework adapters stay three
 * lines:
 *
 *   $kit = Kit::fromEnv('books', manifest: __DIR__ . '/lifecycle-manifest.php');
 *   [$status, $json] = $kit->receiver()->handle($rawBody, $headers);      // the route
 *   $refusal = $kit->guard($allowList)->check($cmpId, $method, $path);     // the write gate (null = go)
 *   $kit->worker()->run();                                                 // cron (bin/worker.php does this)
 *
 * The PDO defaults to pdoFromEnv(); pass the product's own when it resolves its database differently
 * (e.g. names from Console) — the kit never needs more than one PDO on the product's database.
 */
final class Kit
{
    private ?EventStore $store = null;

    private ?PDO $pdo;

    /** @var (callable(string, string): void)|null */
    private $log;

    /** @var callable(): int */
    private $clock;

    /** @var callable|null */
    private $http;

    /**
     * @param array{hooks?: array<string, list<callable>>, log?: callable, clock?: callable, http?: callable, on_finish?: callable, legal_hold?: callable} $options
     *   hooks: company / account (after the engine) and, 1.1, company_before / account_before;
     *   legal_hold (1.1): fn (string $scope 'company'|'account', int|string $id): ?string — a reason holds the purge
     */
    public function __construct(
        public readonly Config $config,
        ?PDO $pdo = null,
        private ?string $manifestPath = null,
        private array $options = [],
    ) {
        $this->pdo = $pdo;
        $this->log = $options['log'] ?? null;
        $this->clock = $options['clock'] ?? static fn (): int => time();
        $this->http = $options['http'] ?? null;
    }

    /**
     * @param (callable(string): ?string)|null $env
     * @param list<string> $aliases
     * @param array<string,mixed> $options see __construct
     */
    public static function fromEnv(string $appKey, ?PDO $pdo = null, ?string $manifest = null, ?callable $env = null, array $aliases = [], array $options = []): self
    {
        $env ??= [Config::class, 'env'];
        $path = trim((string) ($env('LIFECYCLE_MANIFEST') ?? ''));

        return new self(Config::fromEnv($appKey, $env, $aliases), $pdo, $path !== '' ? $path : $manifest, $options + ['env' => $env]);
    }

    public function pdo(): PDO
    {
        return $this->pdo ??= self::pdoFromEnv($this->options['env'] ?? null);
    }

    public function store(): EventStore
    {
        return $this->store ??= new EventStore($this->pdo(), $this->clock);
    }

    public function receiver(): Receiver
    {
        return new Receiver($this->config, $this->store(), $this->log, $this->clock);
    }

    /**
     * @param list<string> $allow "METHOD /path" patterns that stay open while restricted
     * @param list<string> $machineAllow (1.1) writes a machine caller may make into a restricted company
     *                                   (checkMachine()): money already moved, provider webhooks, Manage callbacks
     */
    public function guard(array $allow = [], array $machineAllow = []): ReadOnlyGuard
    {
        return new ReadOnlyGuard($this->store(), $allow, $this->log, $machineAllow);
    }

    /** For a sender whose machine writes another product refuses with 423 (1.1). */
    public function deferredSend(): DeferredSend
    {
        return new DeferredSend($this->config, $this->http, $this->clock);
    }

    public function manifest(): ?PurgeManifest
    {
        return $this->manifestPath !== null ? PurgeManifest::load($this->manifestPath) : null;
    }

    public function engine(): ?PurgeEngine
    {
        $m = $this->manifest();

        return $m !== null ? new PurgeEngine($this->pdo(), $m, $this->clock) : null;
    }

    public function ackClient(): AckClient
    {
        return new AckClient($this->config, $this->http, $this->clock);
    }

    public function worker(): Worker
    {
        $engine = $this->engine();
        if ($engine !== null && $engine->manifest()->appKey() !== $this->config->appKey) {
            throw new \InvalidArgumentException('The manifest is for ' . $engine->manifest()->appKey() . ', this application is ' . $this->config->appKey);
        }

        return new Worker($this->config, $this->store(), $engine, $this->ackClient(), $this->options['hooks'] ?? [], $this->log, $this->options['on_finish'] ?? null,
            $this->options['legal_hold'] ?? null);
    }

    /**
     * PDO for the product's PostgreSQL from its .env, first match wins:
     *   LIFECYCLE_DB_DSN (+ LIFECYCLE_DB_USER / LIFECYCLE_DB_PASSWORD)
     *   DATABASE_URL     postgres://user:pass@host:port/db
     *   DB_HOST, DB_PORT, DB_NAME|DB_DATABASE, DB_USER|DB_USERNAME, DB_PASSWORD|DB_PASS
     *   database.default.hostname / port / database / username / password (CodeIgniter 4)
     *
     * @param (callable(string): ?string)|null $env
     */
    public static function pdoFromEnv(?callable $env = null): PDO
    {
        $env ??= [Config::class, 'env'];
        $get = static function (string ...$names) use ($env): string {
            foreach ($names as $n) {
                $v = trim((string) ($env($n) ?? ''));
                if ($v !== '') {
                    return trim($v, "\"'");
                }
            }

            return '';
        };
        $opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];

        if (($dsn = $get('LIFECYCLE_DB_DSN')) !== '') {
            return new PDO($dsn, $get('LIFECYCLE_DB_USER') ?: null, $get('LIFECYCLE_DB_PASSWORD') ?: null, $opts);
        }
        if (($url = $get('DATABASE_URL')) !== '' && ($p = parse_url($url)) !== false && isset($p['host'])) {
            $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $p['host'], (int) ($p['port'] ?? 5432), ltrim((string) ($p['path'] ?? ''), '/'));

            return new PDO($dsn, isset($p['user']) ? rawurldecode($p['user']) : null, isset($p['pass']) ? rawurldecode($p['pass']) : null, $opts);
        }
        $host = $get('DB_HOST', 'database.default.hostname');
        $name = $get('DB_NAME', 'DB_DATABASE', 'database.default.database');
        if ($host === '' || $name === '') {
            throw new \RuntimeException('No database settings found for the lifecycle kit (LIFECYCLE_DB_DSN, DATABASE_URL, DB_HOST/DB_NAME or database.default.*).');
        }
        $port = (int) ($get('DB_PORT', 'database.default.port') ?: 5432);

        return new PDO(
            sprintf('pgsql:host=%s;port=%d;dbname=%s', $host, $port, $name),
            $get('DB_USER', 'DB_USERNAME', 'database.default.username'),
            $get('DB_PASSWORD', 'DB_PASS', 'database.default.password'),
            $opts,
        );
    }

    public static function defaultLog(string $level, string $message): void
    {
        error_log('company-lifecycle [' . $level . ']: ' . $message);
    }

    /**
     * Read a .env file (KEY=VALUE and CodeIgniter's `key.name = value`) into a getter; real
     * environment variables win. For the CLI, which runs outside the framework.
     *
     * @return callable(string): ?string
     */
    public static function envFile(?string $path): callable
    {
        $vars = [];
        if ($path !== null && is_readable($path)) {
            foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                    continue;
                }
                [$k, $v] = array_map('trim', explode('=', $line, 2));
                if (str_starts_with($k, 'export ')) {
                    $k = trim(substr($k, 7));
                }
                if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[-1] === $v[0]) {
                    $v = substr($v, 1, -1);
                } elseif (($hash = strpos($v, ' #')) !== false) {
                    $v = rtrim(substr($v, 0, $hash));
                }
                $vars[$k] = $v;
            }
        }

        return static function (string $name) use ($vars): ?string {
            $real = Config::env($name);

            return $real !== null && $real !== '' ? $real : ($vars[$name] ?? null);
        };
    }
}
