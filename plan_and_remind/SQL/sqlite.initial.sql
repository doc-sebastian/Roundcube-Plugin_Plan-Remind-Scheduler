-- Plan & Remind – SQLite schema
--
-- NOTE: This works only when your Roundcube instance uses a *single* shared
-- SQLite database file. Some setups (e.g. certain cPanel installs) use a
-- separate database per user; the cron worker cannot reach those, so the
-- scheduler will not deliver. Prefer MySQL/MariaDB or PostgreSQL in production.

CREATE TABLE IF NOT EXISTS plan_and_remind (
  id                INTEGER PRIMARY KEY,
  user_id           INTEGER NOT NULL
                        REFERENCES users (user_id) ON DELETE CASCADE ON UPDATE CASCADE,
  type              TEXT    NOT NULL DEFAULT 'scheduled',
  status            TEXT    NOT NULL DEFAULT 'pending',
  send_at           DATETIME NOT NULL,
  created_at        DATETIME NOT NULL,
  sent_at           DATETIME DEFAULT NULL,
  mail_from         TEXT    NOT NULL,
  recipients        TEXT    NOT NULL,
  subject           TEXT    DEFAULT NULL,
  store_target      TEXT    DEFAULT NULL,
  delivery          TEXT    DEFAULT NULL,
  imap_folder       TEXT    DEFAULT NULL,
  imap_uid          INTEGER DEFAULT NULL,
  mime_message      TEXT    NOT NULL,
  sent_copy_pending INTEGER NOT NULL DEFAULT 0,
  error_message     TEXT    DEFAULT NULL,
  attempts          INTEGER NOT NULL DEFAULT 0
);

CREATE INDEX IF NOT EXISTS ix_pnr_user ON plan_and_remind (user_id);
CREATE INDEX IF NOT EXISTS ix_pnr_due  ON plan_and_remind (status, send_at);
CREATE INDEX IF NOT EXISTS ix_pnr_copy ON plan_and_remind (user_id, sent_copy_pending);
CREATE INDEX IF NOT EXISTS ix_pnr_imap ON plan_and_remind (imap_folder, imap_uid);
