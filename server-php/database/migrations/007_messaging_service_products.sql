-- 007: which products may act for this company with no person signed in (G19#7).
--
-- A product's service key reaches only the routes ServicePolicy lists for it,
-- and only for a company bound to it. With a person present the binding is
-- that person's Manage membership (their forwarded session). With nobody
-- present — an appointment reminder sent from cron — the company itself says
-- which products may act for it, here. Empty by default: no product may send
-- for a company until somebody with messaging.access.manage allows it in
-- Settings (or ops lists the company in SERVICE_KEY_COMPANIES).
ALTER TABLE messaging_settings
    ADD COLUMN IF NOT EXISTS service_products JSONB NOT NULL DEFAULT '[]'::jsonb;
