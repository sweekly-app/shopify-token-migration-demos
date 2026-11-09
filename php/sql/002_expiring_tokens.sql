-- Migration to expiring offline tokens (Postgres). Additive only.
-- A row with refresh_token IS NULL still holds a non-expiring token (scripts/migrate-tokens.php exchanges it).
ALTER TABLE shops ADD COLUMN IF NOT EXISTS expires_at TIMESTAMPTZ;
ALTER TABLE shops ADD COLUMN IF NOT EXISTS refresh_token TEXT;
ALTER TABLE shops ADD COLUMN IF NOT EXISTS refresh_token_expires_at TIMESTAMPTZ;
ALTER TABLE shops ADD COLUMN IF NOT EXISTS needs_reauth BOOLEAN NOT NULL DEFAULT false;
ALTER TABLE shops ADD COLUMN IF NOT EXISTS exchanged_at TIMESTAMPTZ;
