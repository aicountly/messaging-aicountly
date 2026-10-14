<?php
declare(strict_types=1);

namespace Aicountly\Lifecycle;

use PDO;

/**
 * The kit's tables (schema.sql) on the product's PostgreSQL, through PDO.
 *
 * Rules it keeps:
 *  - an event is stored once (event_id); a redelivery changes nothing new;
 *  - the company's state follows the NEWEST event by occurred_at: an older event delivered late is
 *    stored as stale and changes nothing;
 *  - Manage's companyinfo is the source of truth: syncFromCompanyInfo() overwrites what events said;
 *  - one purge job per purge event; claiming uses SKIP LOCKED so overlapping cron runs never take the
 *    same job; a job left running by a dead worker is reclaimed after $staleAfter seconds;
 *  - a finished job and its ack row are written in one transaction, so an ack is never lost.
 *
 * Times cross this boundary as ISO-8601 UTC strings; "now" comes from the injectable clock so tests can
 * pin it.
 */
final class EventStore
{
    public const STATES = ['active', 'restricted', 'purge_pending', 'purged'];

    private const ISO = "'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"'";

    /** @var callable(): int */
    private $clock;

    /** @param (callable(): int)|null $clock */
    public function __construct(private PDO $db, ?callable $clock = null)
    {
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function pdo(): PDO
    {
        return $this->db;
    }

    public function now(): int
    {
        return ($this->clock)();
    }

    public static function iso(int $ts): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $ts);
    }

    /** Apply schema.sql (idempotent). Products normally ship it as a migration instead. */
    public function installSchema(): void
    {
        $this->db->exec((string) file_get_contents(dirname(__DIR__) . '/schema.sql'));
    }

    /**
     * Store a verified, parsed event once. False when its event_id is already stored.
     *
     * @param array{event_id:string, event:string, cmp_id:?int, uuid:?string, app_key:string, reason:?string, occurred_at:string, restricted_until:?string} $e
     */
    public function recordEvent(array $e, string $rawBody): bool
    {
        $stmt = $this->db->prepare('
            INSERT INTO aic_lifecycle_events (event_id, event, target_cmp_id, target_uuid, app_key, reason, occurred_at, restricted_until, body, received_at)
            VALUES (CAST(:id AS UUID), :event, :cmp, :uuid, :app, :reason, CAST(:occ AS TIMESTAMPTZ), CAST(:until AS TIMESTAMPTZ), :body, CAST(:now AS TIMESTAMPTZ))
            ON CONFLICT (event_id) DO NOTHING
            RETURNING event_id
        ');
        $stmt->execute([
            ':id' => $e['event_id'], ':event' => $e['event'], ':cmp' => $e['cmp_id'], ':uuid' => $e['uuid'],
            ':app' => $e['app_key'], ':reason' => $e['reason'], ':occ' => $e['occurred_at'], ':until' => $e['restricted_until'],
            ':body' => $rawBody, ':now' => self::iso($this->now()),
        ]);

        return $stmt->fetchColumn() !== false;
    }

    public function setOutcome(string $eventId, string $outcome): void
    {
        $this->db->prepare('UPDATE aic_lifecycle_events SET outcome = :o WHERE event_id = CAST(:id AS UUID)')
            ->execute([':o' => $outcome, ':id' => $eventId]);
    }

    /** @return array<string,mixed>|null the stored event */
    public function event(string $eventId): ?array
    {
        $stmt = $this->db->prepare('SELECT event_id::text AS event_id, event, target_cmp_id, target_uuid, app_key, reason, outcome,
                to_char(occurred_at AT TIME ZONE \'UTC\', ' . self::ISO . ') AS occurred_at
            FROM aic_lifecycle_events WHERE event_id = CAST(:id AS UUID)');
        $stmt->execute([':id' => $eventId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * The company's state here, or null when nothing was ever recorded (= active).
     *
     * @return array{state:string, restricted_until:?string, reason:?string, source:string, last_event_id:?string, last_event_at:?string}|null
     */
    public function state(int $cmpId): ?array
    {
        if ($cmpId <= 0) {
            return null;
        }
        $stmt = $this->db->prepare('
            SELECT state, reason, source, last_event_id::text AS last_event_id,
                   to_char(restricted_until AT TIME ZONE \'UTC\', ' . self::ISO . ') AS restricted_until,
                   to_char(last_event_at AT TIME ZONE \'UTC\', ' . self::ISO . ') AS last_event_at
              FROM aic_lifecycle_state WHERE target_cmp_id = :c
        ');
        $stmt->execute([':c' => $cmpId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : [
            'state'            => (string) $row['state'],
            'restricted_until' => $row['restricted_until'],
            'reason'           => $row['reason'],
            'source'           => (string) $row['source'],
            'last_event_id'    => $row['last_event_id'],
            'last_event_at'    => $row['last_event_at'],
        ];
    }

    /**
     * Apply a signed event's state unless a newer event (by occurred_at) was already applied.
     * True when applied, false when stale.
     */
    public function applyEvent(int $cmpId, string $state, ?string $restrictedUntil, ?string $reason, string $eventId, string $occurredAt): bool
    {
        $stmt = $this->db->prepare("
            INSERT INTO aic_lifecycle_state AS l (target_cmp_id, state, restricted_until, reason, source, last_event_id, last_event_at, updated_at)
            VALUES (:c, :s, CAST(:u AS TIMESTAMPTZ), :r, 'event', CAST(:e AS UUID), CAST(:o AS TIMESTAMPTZ), CAST(:now AS TIMESTAMPTZ))
            ON CONFLICT (target_cmp_id) DO UPDATE SET
                state = EXCLUDED.state,
                -- a purge request need not repeat the date; the restriction's own date is kept
                restricted_until = CASE WHEN EXCLUDED.state = 'active' THEN NULL
                                        ELSE COALESCE(EXCLUDED.restricted_until, l.restricted_until) END,
                reason = EXCLUDED.reason,
                source = 'event',
                last_event_id = EXCLUDED.last_event_id,
                last_event_at = EXCLUDED.last_event_at,
                updated_at = EXCLUDED.updated_at
            WHERE l.last_event_at IS NULL OR l.last_event_at <= EXCLUDED.last_event_at
            RETURNING target_cmp_id
        ");
        $stmt->execute([
            ':c' => $cmpId, ':s' => $state, ':u' => $state === 'active' ? null : $restrictedUntil, ':r' => $reason,
            ':e' => $eventId, ':o' => $occurredAt, ':now' => self::iso($this->now()),
        ]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Manage's companyinfo said $state: it is the source of truth, so record it when it differs. An
     * active answer only ever changes an existing row (the ordinary company costs no write). True when
     * the row changed.
     */
    public function syncFromCompanyInfo(int $cmpId, string $state, ?string $restrictedUntil): bool
    {
        if (!in_array($state, ['active', 'restricted', 'purge_pending'], true) || $cmpId <= 0) {
            return false;
        }
        $now = self::iso($this->now());
        if ($state === 'active') {
            // A company this product already purged stays purged until an event (renewal) says so.
            $stmt = $this->db->prepare("
                UPDATE aic_lifecycle_state SET state = 'active', restricted_until = NULL, source = 'companyinfo', updated_at = CAST(:now AS TIMESTAMPTZ)
                WHERE target_cmp_id = :c AND state IN ('restricted', 'purge_pending')
                RETURNING target_cmp_id
            ");
            $stmt->execute([':c' => $cmpId, ':now' => $now]);

            return $stmt->fetchColumn() !== false;
        }
        $stmt = $this->db->prepare("
            INSERT INTO aic_lifecycle_state AS l (target_cmp_id, state, restricted_until, source, updated_at)
            VALUES (:c, :s, CAST(:u AS TIMESTAMPTZ), 'companyinfo', CAST(:now AS TIMESTAMPTZ))
            ON CONFLICT (target_cmp_id) DO UPDATE SET
                state = EXCLUDED.state, restricted_until = EXCLUDED.restricted_until, source = 'companyinfo', updated_at = EXCLUDED.updated_at
            WHERE l.state <> 'purged' AND (l.state IS DISTINCT FROM EXCLUDED.state OR l.restricted_until IS DISTINCT FROM EXCLUDED.restricted_until)
            RETURNING target_cmp_id
        ");
        $stmt->execute([':c' => $cmpId, ':s' => $state, ':u' => $restrictedUntil, ':now' => $now]);

        return $stmt->fetchColumn() !== false;
    }

    public function markPurged(int $cmpId): void
    {
        $this->db->prepare("
            INSERT INTO aic_lifecycle_state AS l (target_cmp_id, state, source, updated_at)
            VALUES (:c, 'purged', 'worker', CAST(:now AS TIMESTAMPTZ))
            ON CONFLICT (target_cmp_id) DO UPDATE SET state = 'purged', source = 'worker', updated_at = EXCLUDED.updated_at
        ")->execute([':c' => $cmpId, ':now' => self::iso($this->now())]);
    }

    /** Queue a purge for a purge event. False when already queued. */
    public function enqueueJob(string $eventId, string $kind, ?int $cmpId, ?string $uuid): bool
    {
        $stmt = $this->db->prepare('
            INSERT INTO aic_lifecycle_jobs (event_id, kind, target_cmp_id, target_uuid, next_attempt_at, created_at, updated_at)
            VALUES (CAST(:e AS UUID), :k, :c, :u, CAST(:now AS TIMESTAMPTZ), CAST(:now2 AS TIMESTAMPTZ), CAST(:now3 AS TIMESTAMPTZ))
            ON CONFLICT (event_id) DO NOTHING
            RETURNING id
        ');
        $now = self::iso($this->now());
        $stmt->execute([':e' => $eventId, ':k' => $kind, ':c' => $cmpId, ':u' => $uuid, ':now' => $now, ':now2' => $now, ':now3' => $now]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Claim the next due job (pending and due, or running but abandoned longer than $staleAfter
     * seconds), marking it running and counting the attempt.
     *
     * @return array{id:int, event_id:string, kind:string, cmp_id:?int, uuid:?string, attempts:int}|null
     */
    public function claimJob(int $staleAfter = 3600): ?array
    {
        $now = $this->now();
        $stmt = $this->db->prepare("
            UPDATE aic_lifecycle_jobs j
               SET status = 'running', attempts = j.attempts + 1, locked_at = CAST(:now AS TIMESTAMPTZ),
                   started_at = COALESCE(j.started_at, CAST(:now2 AS TIMESTAMPTZ)), updated_at = CAST(:now3 AS TIMESTAMPTZ)
             WHERE j.id = (
                SELECT id FROM aic_lifecycle_jobs
                 WHERE (status = 'pending' AND next_attempt_at <= CAST(:now4 AS TIMESTAMPTZ))
                    OR (status = 'running' AND locked_at < CAST(:stale AS TIMESTAMPTZ))
                 ORDER BY next_attempt_at, id
                 LIMIT 1
                 FOR UPDATE SKIP LOCKED
             )
            RETURNING j.id, j.event_id::text AS event_id, j.kind, j.target_cmp_id, j.target_uuid, j.attempts
        ");
        $iso = self::iso($now);
        $stmt->execute([':now' => $iso, ':now2' => $iso, ':now3' => $iso, ':now4' => $iso, ':stale' => self::iso($now - $staleAfter)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return [
            'id'       => (int) $row['id'],
            'event_id' => (string) $row['event_id'],
            'kind'     => (string) $row['kind'],
            'cmp_id'   => $row['target_cmp_id'] !== null ? (int) $row['target_cmp_id'] : null,
            'uuid'     => $row['target_uuid'] !== null ? (string) $row['target_uuid'] : null,
            'attempts' => (int) $row['attempts'],
        ];
    }

    /** Put a running job back to pending, due in $delay seconds. */
    public function retryJob(int $jobId, int $delay, string $detail): void
    {
        $now = $this->now();
        $this->db->prepare("
            UPDATE aic_lifecycle_jobs SET status = 'pending', locked_at = NULL, detail = :d,
                   next_attempt_at = CAST(:next AS TIMESTAMPTZ), updated_at = CAST(:now AS TIMESTAMPTZ)
             WHERE id = :id
        ")->execute([':d' => $detail, ':next' => self::iso($now + max(0, $delay)), ':now' => self::iso($now), ':id' => $jobId]);
    }

    /**
     * Finish a job (purged | nothing_to_purge | failed) and owe Manage its ack, in one transaction.
     *
     * @param list<string> $retained
     */
    public function finishJob(int $jobId, string $status, string $detail, ?string $archive, int $rows, array $retained, string $eventId, string $ackBody): void
    {
        $now = self::iso($this->now());
        $own = !$this->db->inTransaction();
        if ($own) {
            $this->db->beginTransaction();
        }
        try {
            $this->db->prepare("
                UPDATE aic_lifecycle_jobs SET status = :s, detail = :d, archive_name = :a, rows_purged = :r, retained = CAST(:ret AS JSONB),
                       locked_at = NULL, finished_at = CAST(:now AS TIMESTAMPTZ), updated_at = CAST(:now2 AS TIMESTAMPTZ)
                 WHERE id = :id
            ")->execute([':s' => $status, ':d' => $detail, ':a' => $archive, ':r' => $rows, ':ret' => json_encode(array_values($retained)),
                ':now' => $now, ':now2' => $now, ':id' => $jobId]);
            // A requeued job supersedes its earlier, still-open ack.
            $this->db->prepare("UPDATE aic_lifecycle_acks SET status = 'failed', last_error = 'superseded by a newer ack' WHERE job_id = :j AND status = 'pending'")
                ->execute([':j' => $jobId]);
            $this->db->prepare('
                INSERT INTO aic_lifecycle_acks (job_id, event_id, body, next_attempt_at, created_at)
                VALUES (:j, CAST(:e AS UUID), :b, CAST(:now AS TIMESTAMPTZ), CAST(:now2 AS TIMESTAMPTZ))
            ')->execute([':j' => $jobId, ':e' => $eventId, ':b' => $ackBody, ':now' => $now, ':now2' => $now]);
            if ($own) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($own && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Acks owed and due.
     *
     * @return list<array{id:int, job_id:int, event_id:string, body:string, attempts:int}>
     */
    public function dueAcks(int $limit = 50): array
    {
        $stmt = $this->db->prepare("
            SELECT id, job_id, event_id::text AS event_id, body, attempts FROM aic_lifecycle_acks
             WHERE status = 'pending' AND next_attempt_at <= CAST(:now AS TIMESTAMPTZ)
             ORDER BY next_attempt_at, id LIMIT " . max(1, min(500, $limit)));
        $stmt->execute([':now' => self::iso($this->now())]);

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'job_id' => (int) $r['job_id'], 'event_id' => (string) $r['event_id'],
            'body' => (string) $r['body'], 'attempts' => (int) $r['attempts'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Record one ack attempt: sent; or not sent and retried in $retryIn seconds; or (retryIn null)
     * given up — status failed, logged for ops.
     */
    public function recordAck(int $ackId, bool $sent, int $http, ?string $error, ?int $retryIn): void
    {
        $now = $this->now();
        $status = $sent ? 'sent' : ($retryIn === null ? 'failed' : 'pending');
        $this->db->prepare('
            UPDATE aic_lifecycle_acks SET status = :s, attempts = attempts + 1, last_http = :h, last_error = :e,
                   next_attempt_at = CAST(:next AS TIMESTAMPTZ), sent_at = CASE WHEN :sent THEN CAST(:now AS TIMESTAMPTZ) ELSE sent_at END
             WHERE id = :id
        ')->execute([
            ':s' => $status, ':h' => $http > 0 ? $http : null, ':e' => $error !== null ? substr($error, 0, 1000) : null,
            ':next' => self::iso($now + (int) ($retryIn ?? 0)), ':sent' => $sent ? 'true' : 'false', ':now' => self::iso($now), ':id' => $ackId,
        ]);
    }

    /**
     * Operator: run a finished (typically failed) job again — after fixing the product, so Manage gets
     * a fresh answer (Manage accepts purged after failed). True when a job was requeued.
     */
    public function requeueJob(string $eventId): bool
    {
        $stmt = $this->db->prepare("
            UPDATE aic_lifecycle_jobs SET status = 'pending', attempts = 0, locked_at = NULL, next_attempt_at = CAST(:now AS TIMESTAMPTZ),
                   finished_at = NULL, updated_at = CAST(:now2 AS TIMESTAMPTZ)
             WHERE event_id = CAST(:e AS UUID) AND status IN ('failed', 'purged', 'nothing_to_purge')
            RETURNING id
        ");
        $now = self::iso($this->now());
        $stmt->execute([':now' => $now, ':now2' => $now, ':e' => $eventId]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            return false;
        }
        // A company job may run only from purge_pending / purged; put the state back if it moved on.
        $this->db->prepare("
            UPDATE aic_lifecycle_state s SET state = 'purge_pending', source = 'worker', updated_at = CAST(:now AS TIMESTAMPTZ)
              FROM aic_lifecycle_jobs j WHERE j.id = :id AND j.kind = 'company' AND s.target_cmp_id = j.target_cmp_id AND s.state = 'purged'
        ")->execute([':now' => $now, ':id' => (int) $id]);

        return true;
    }

    /** @return array<string,mixed>|null */
    public function job(string $eventId): ?array
    {
        $stmt = $this->db->prepare('SELECT j.*, j.event_id::text AS event_id FROM aic_lifecycle_jobs j WHERE j.event_id = CAST(:e AS UUID)');
        $stmt->execute([':e' => $eventId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** @return array{jobs: array<string,int>, acks: array<string,int>, failed_acks: list<array<string,mixed>>} */
    public function summary(): array
    {
        $jobs = [];
        foreach ($this->db->query('SELECT status, COUNT(*) AS n FROM aic_lifecycle_jobs GROUP BY status')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $jobs[(string) $r['status']] = (int) $r['n'];
        }
        $acks = [];
        foreach ($this->db->query('SELECT status, COUNT(*) AS n FROM aic_lifecycle_acks GROUP BY status')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $acks[(string) $r['status']] = (int) $r['n'];
        }
        $failed = $this->db->query("SELECT event_id::text AS event_id, attempts, last_http, last_error FROM aic_lifecycle_acks
            WHERE status = 'failed' AND COALESCE(last_error, '') <> 'superseded by a newer ack' ORDER BY id DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);

        return ['jobs' => $jobs, 'acks' => $acks, 'failed_acks' => $failed];
    }
}
