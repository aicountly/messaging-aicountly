-- 014: plan limits (CONTRACTS §3.6). No new table and no new column.
--
-- messaging.max_messages_per_month counts a company's outbound messages put on the send queue since
-- the start of the calendar month (src/PlanEntitlements.php::messagesQueuedThisMonth). It is counted
-- only when the company's plan states that limit, right before a person sends; this partial index keeps
-- that count to the month's rows of one company instead of every message it ever had.
-- messaging.max_channels counts active connections, already served by ix_messaging_conn_scope.

CREATE INDEX IF NOT EXISTS ix_messaging_msg_outbound_queued
    ON messaging_messages (cmp_id, queued_at)
    WHERE direction = 'outbound' AND queued_at IS NOT NULL;
