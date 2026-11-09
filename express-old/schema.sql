CREATE TABLE IF NOT EXISTS shops (
  shop TEXT PRIMARY KEY,
  access_token TEXT NOT NULL,
  scope TEXT,
  installed_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
