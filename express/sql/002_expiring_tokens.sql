-- Migration to expiring offline tokens. Additive only: existing rows keep working until they are exchanged.
-- A row with refresh_token IS NULL still holds a non-expiring token (scripts/migrate-tokens.js exchanges it).
ALTER TABLE shops ADD COLUMN IF NOT EXISTS expires_at TIMESTAMPTZ;
ALTER TABLE shops ADD COLUMN IF NOT EXISTS refresh_token TEXT;
ALTER TABLE shops ADD COLUMN IF NOT EXISTS refresh_token_expires_at TIMESTAMPTZ;
-- Set when the refresh token is dead (expired, or already used by another process): the merchant must reopen the app.
ALTER TABLE shops ADD COLUMN IF NOT EXISTS needs_reauth BOOLEAN NOT NULL DEFAULT false;
ALTER TABLE shops ADD COLUMN IF NOT EXISTS exchanged_at TIMESTAMPTZ;
