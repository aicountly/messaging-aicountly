<?php
declare(strict_types=1);

namespace Aicountly\Ops;

/**
 * MaintenanceControl — let Console switch THIS site into or out of maintenance.
 *
 * CANONICAL COPY: console-react-app/ops/estate/kit/php/MaintenanceControl.php
 * COPY, DON'T EDIT. Installed per product by ops/estate/install-maintenance-endpoint.py.
 *
 * Console cannot write into another cPanel account's document root — separate accounts, separate
 * UIDs. It does not need to: it calls this endpoint over HTTPS with that product's key, and the
 * product's OWN PHP, running as the account that owns the document root, writes the flag file.
 *
 *   POST {api}/internal/maintenance   {"action":"on","reason":…,"until":…,"because":…}
 *   POST {api}/internal/maintenance   {"action":"off"}
 *   GET  {api}/internal/maintenance   -> the live state
 *
 * Authenticated with Authorization: Bearer <CONSOLE_CONTROL_KEY>, the same Bearer + hash_equals
 * shape the analytics ingest already uses, so the idiom is one people here have seen.
 *
 * The flag file stays the single source of truth, which is what lets WHM keep working: root can
 * still touch or rm it directly and this endpoint will simply report what it finds. Nothing here
 * caches state or tries to reconcile it, so the two paths cannot fight.
 */
final class MaintenanceControl
{
    /** A key shorter than this is treated as unset: a weak shared secret is worse than none. */
    private const MIN_KEY = 32;

    private const MAX_FIELD = 300;

    /** The page the estate installer puts in every document root. Its presence is how this class
     *  recognises a real document root rather than some directory above or beside one. */
    private const SENTINEL = 'maintenance.html';

    /** @var string absolute path of the document root the flag file belongs in */
    private string $docroot;

    public function __construct(?string $docroot = null)
    {
        // Never from the request body or path — this code runs as the account that owns the whole
        // site, so a document root taken from input is a path-traversal bug waiting to happen.
        $this->docroot = $docroot ?? self::resolveDocroot();
    }

    /**
     * Work out which directory the flag file belongs in.
     *
     * This is the one piece of this class that is not obvious, and getting it wrong is silent: the
     * endpoint would answer 200 after writing .maintenance into a directory Apache never reads, and
     * the site would stay up while Console showed it as down.
     *
     * Three things make it harder than "the api folder's parent":
     *
     *  - Most products deploy the API atomically: <docroot>/api is a SYMLINK into
     *    ~/aicountly-releases/<site>-api/<id>/. PHP resolves symlinks in __FILE__, so __DIR__ is
     *    inside that release folder — outside the document root altogether, with no api/ anywhere
     *    above it. Walking up from __DIR__ cannot find the docroot for those products at all.
     *  - aicountly.com has no api symlink and no framework: there, walking up IS right.
     *  - A couple of products keep the API under its own subdomain.
     *
     * So the order is: an explicit setting, then what Apache says, then the walk — and every
     * candidate must contain maintenance.html before it is accepted. That sentinel is what the
     * estate page installer wrote into the document root the deploy uploads to, which is the same
     * directory whose .htaccess reads the flag. If none of the candidates qualifies, handle()
     * returns 500 rather than writing somewhere hopeful.
     */
    private static function resolveDocroot(): string
    {
        foreach (self::candidates() as $dir) {
            if ($dir !== '' && \is_dir($dir) && \is_file($dir . '/' . self::SENTINEL)) {
                return $dir;
            }
        }
        return '';
    }

    /** @return list<string> */
    private static function candidates(): array
    {
        $out = [];

        // 1. Explicit: api/.env may set MAINTENANCE_DOCROOT for a layout nothing else fits.
        $explicit = \trim((string) ($_ENV['MAINTENANCE_DOCROOT'] ?? \getenv('MAINTENANCE_DOCROOT') ?: ''));
        if ($explicit !== '') {
            $out[] = \rtrim($explicit, '/');
        }

        // 2. Apache's own answer. DOCUMENT_ROOT comes from the vhost, not from the client, so it is
        //    not attacker-controlled — and for every product whose API lives under the site it is
        //    exactly the directory wanted, symlinked release or not.
        $docroot = \rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
        if ($docroot !== '') {
            $out[] = $docroot;
            // An API on its own subdomain (<docroot>/api as the vhost root) answers with the api
            // folder itself; its parent is then the site.
            $out[] = \dirname($docroot);
        }

        // 3. The walk: .../<docroot>/api/<wherever this file sits> -> the api folder's parent.
        $dir = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            if (\basename($dir) === 'api') {
                $out[] = \dirname($dir);
            }
            $out[] = $dir;
            $parent = \dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        return \array_values(\array_unique($out));
    }

    /** Where this instance will write. Exposed so a health check can show it. */
    public function docroot(): string
    {
        return $this->docroot;
    }

    /** @return array{0:int,1:array<string,mixed>} [http status, body] */
    public function handle(string $method, string $authorization, string $rawBody): array
    {
        $key = $this->key();
        if ($key === null) {
            // Fail closed and say so plainly. An endpoint that answers 200 while unconfigured
            // would let Console believe a site is switchable when nothing would ever happen.
            return [503, ['success' => false, 'error' => 'not_configured',
                          'message' => 'CONSOLE_CONTROL_KEY is not set (or is under 32 characters).']];
        }

        if (!$this->authorised($authorization, $key)) {
            return [401, ['success' => false, 'error' => 'unauthorized']];
        }

        if ($this->docroot === '') {
            // No directory held maintenance.html, so there is nowhere this flag would be read from.
            // Saying so is far better than writing it into a release folder and reporting success.
            return [500, ['success' => false, 'error' => 'docroot_not_found',
                          'message' => 'Could not locate the document root (no maintenance.html found). '
                                       . 'Set MAINTENANCE_DOCROOT in api/.env.']];
        }

        if (\strtoupper($method) === 'GET') {
            return [200, ['success' => true, 'data' => $this->state()]];
        }
        if (\strtoupper($method) !== 'POST') {
            return [405, ['success' => false, 'error' => 'method_not_allowed']];
        }

        if (\strlen($rawBody) > 8192) {
            return [413, ['success' => false, 'error' => 'payload_too_large']];
        }
        $in = \json_decode($rawBody === '' ? '{}' : $rawBody, true);
        if (!\is_array($in)) {
            return [422, ['success' => false, 'error' => 'invalid_json']];
        }

        $action = \is_string($in['action'] ?? null) ? \strtolower(\trim($in['action'])) : '';
        if ($action !== 'on' && $action !== 'off') {
            return [422, ['success' => false, 'error' => 'invalid_action',
                          'message' => 'action must be "on" or "off".']];
        }

        $ok = $action === 'on'
            ? $this->switchOn(
                $this->field($in['reason'] ?? null),
                $this->field($in['until'] ?? null),
                $this->field($in['because'] ?? null),
            )
            : $this->switchOff();

        if (!$ok) {
            // Almost always the document root not being writable by the PHP user.
            return [500, ['success' => false, 'error' => 'write_failed',
                          'message' => 'Could not write the maintenance flag in the document root.']];
        }

        return [200, ['success' => true, 'data' => $this->state()]];
    }

    // ---------------------------------------------------------------- internals

    /**
     * The shared secret Console authenticates with, from api/.env.
     *
     * This reads the file itself rather than asking the product's own configuration layer, and that
     * is deliberate twice over. First, the estate has four different env loaders and three of them
     * keep values in a private static array — neither $_ENV nor getenv() — so a class that only
     * consulted those would answer a uniform 503 "not configured" on most of the estate while
     * looking perfectly correct in review. Second, this endpoint has to work when the application
     * around it does not: taking a site down for maintenance is exactly the moment its bootstrap,
     * autoloader or database may be unhappy, and nothing here should depend on them.
     *
     * A real environment variable still wins, so a host-level setting can override a deployed file.
     */
    private function key(): ?string
    {
        $raw = \trim((string) ($_ENV['CONSOLE_CONTROL_KEY'] ?? \getenv('CONSOLE_CONTROL_KEY') ?: ''));
        if ($raw === '') {
            $raw = $this->keyFromEnvFile();
        }
        return \strlen($raw) >= self::MIN_KEY ? $raw : null;
    }

    private function keyFromEnvFile(): string
    {
        foreach ($this->envFiles() as $path) {
            if (!\is_file($path) || !\is_readable($path) || (int) @\filesize($path) > 512000) {
                continue;
            }
            // One key, line by line. The file holds the database password and every service key in
            // the product, so nothing here keeps, returns or logs any other line of it.
            $fh = @\fopen($path, 'r');
            if ($fh === false) {
                continue;
            }
            $found = '';
            while (($line = \fgets($fh)) !== false) {
                $line = \trim($line);
                if ($line === '' || $line[0] === '#' || \strncmp($line, 'CONSOLE_CONTROL_KEY', 19) !== 0) {
                    continue;
                }
                $eq = \strpos($line, '=');
                if ($eq === false || \trim(\substr($line, 0, $eq)) !== 'CONSOLE_CONTROL_KEY') {
                    continue;
                }
                $v = \trim(\substr($line, $eq + 1));
                $len = \strlen($v);
                if ($len >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[$len - 1] === $v[0]) {
                    $v = \substr($v, 1, -1);
                }
                $found = \trim($v);
            }
            \fclose($fh);
            if ($found !== '') {
                return $found;
            }
        }
        return '';
    }

    /** @return list<string> the .env candidates, nearest first */
    private function envFiles(): array
    {
        $out = [];
        // Up from this file: api/.env for a framework product, and for an atomic release the .env
        // that release.sh carried into the release folder.
        $dir = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            $out[] = $dir . '/.env';
            $parent = \dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }
        if ($this->docroot !== '') {
            // aicountly.com keeps its .env at the site root, beside the pages.
            $out[] = $this->docroot . '/.env';
            $out[] = $this->docroot . '/api/.env';
        }
        return \array_values(\array_unique($out));
    }

    private function authorised(string $header, string $key): bool
    {
        if (\stripos($header, 'bearer ') !== 0) {
            return false;
        }
        // hash_equals, not ===, so the comparison does not leak the key one byte at a time.
        return \hash_equals($key, \trim(\substr($header, 7)));
    }

    private function field(mixed $v): string
    {
        if (!\is_string($v)) {
            return '';
        }
        // Collapse newlines: these land in a JSON file that other products read cross-origin, and
        // a stray control character would make it unparseable for every one of them.
        $v = \preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $v) ?? '';
        return \mb_substr(\trim($v), 0, self::MAX_FIELD);
    }

    private function switchOn(string $reason, string $until, string $because): bool
    {
        $notice = ['status' => 'maintenance', 'started' => \gmdate('Y-m-d\TH:i:s\Z')];
        if ($reason !== '')  { $notice['reason']  = $reason; }
        if ($until !== '')   { $notice['until']   = $until; }
        if ($because !== '') { $notice['because'] = $because; }

        $json = \json_encode($notice, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }
        // Notice first, flag second. The .htaccess serves the page the instant the flag exists, so
        // writing the flag first opens a window where the page renders with no reason on it.
        return $this->put($this->docroot . '/.maintenance.json', $json . "\n")
            && $this->put($this->docroot . '/.maintenance', '');
    }

    private function switchOff(): bool
    {
        // Flag first this time, for the same reason in reverse: the site is live again the moment
        // it goes, and a leftover notice file is harmless.
        @\unlink($this->docroot . '/.maintenance');
        @\unlink($this->docroot . '/.maintenance.json');
        return !\is_file($this->docroot . '/.maintenance');
    }

    private function put(string $path, string $contents): bool
    {
        // Write-and-rename: a reader never sees a half-written notice, and rename is atomic within
        // the one filesystem. A plain fopen/fwrite can be read mid-write by the very request the
        // flag was meant to catch.
        $tmp = $path . '.tmp' . \bin2hex(\random_bytes(4));
        if (@\file_put_contents($tmp, $contents) === false) {
            @\unlink($tmp);
            return false;
        }
        @\chmod($tmp, 0644);
        if (!@\rename($tmp, $path)) {
            @\unlink($tmp);
            return false;
        }
        return true;
    }

    /** @return array<string,mixed> */
    public function state(): array
    {
        $on = \is_file($this->docroot . '/.maintenance');
        $out = ['maintenance' => $on];
        if ($on && \is_file($this->docroot . '/.maintenance.json')) {
            $j = \json_decode((string) @\file_get_contents($this->docroot . '/.maintenance.json'), true);
            if (\is_array($j)) {
                $out['notice'] = $j;
            }
        }
        return $out;
    }
}

/**
 * Is THIS request the maintenance endpoint?
 *
 * Asked before the application bootstrap by the products that dispatch with a hand-written
 * if-chain, so the endpoint answers even when the bootstrap itself refuses to run — which is the
 * state a half-finished migration leaves a site in, and exactly when maintenance has to be
 * switched back off. The path is read from REQUEST_URI rather than from whatever variable the
 * dispatcher would compute later, because that variable does not exist yet.
 *
 * Mounted at <docroot>/api on most products and at the vhost root on a few, so the /api prefix is
 * optional. Nothing else is: no prefix wildcard, no query string, no case folding.
 */
function maintenance_requested(): bool
{
    $path = (string) \parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    return \preg_match('#^/(?:api/)?internal/maintenance/?$#', $path) === 1;
}

/**
 * Answer a maintenance request with no framework at all.
 *
 * Products in this estate dispatch in four different ways: CodeIgniter routes, a small house router
 * in src/Routes.php, a hand-written if-chain in index.php, and aicountly.com's plain files. The
 * first two get a thin adapter each; the other two call this, because the alternative is six
 * slightly different copies of the same header-and-echo code, which is how one of them ends up
 * answering 200 on a failed write.
 *
 * It reads the Authorization header itself: some cPanel/Apache setups drop it before PHP sees it
 * unless mod_rewrite put it back, and a product that 401s for that reason looks like a wrong key.
 */
function maintenance_emit(?string $docroot = null): void
{
    $auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if ($auth === '' && \function_exists('apache_request_headers')) {
        $headers = \array_change_key_case((array) \apache_request_headers());
        $auth = (string) ($headers['authorization'] ?? '');
    }

    [$status, $body] = (new MaintenanceControl($docroot))->handle(
        (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
        $auth,
        (string) \file_get_contents('php://input'),
    );

    if (!\headers_sent()) {
        \http_response_code($status);
        \header('Content-Type: application/json; charset=utf-8');
        \header('Cache-Control: no-store');
    }
    echo \json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
}
