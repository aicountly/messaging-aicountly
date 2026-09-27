-- ---------------------------------------------------------------------------
-- AI runs through AI Pulse.
--
-- Messaging's AI now runs on the AI Pulse gateway instead of on a model key
-- this product resolved from Console. Pulse picks the model, enforces the
-- budgets and reports usage to Console per product and feature, so the run log
-- here stays what it was — minimal, content-free metadata — with one addition:
-- Pulse's own id for the call (its `data.id`). That id is how a row here is
-- matched with Pulse's record of the same call. Pulse keeps no prompt or
-- answer either; neither does this table.
--
-- `provider` and `model` stay, and now record the model Pulse chose. The token
-- columns are filled from Pulse's usage figures.
--
-- Additive and idempotent, like every migration here.
-- ---------------------------------------------------------------------------

ALTER TABLE messaging_ai_runs
    ADD COLUMN IF NOT EXISTS pulse_task_id VARCHAR(64) NULL;

CREATE INDEX IF NOT EXISTS ix_messaging_ai_runs_pulse_task
    ON messaging_ai_runs (pulse_task_id)
    WHERE pulse_task_id IS NOT NULL;
