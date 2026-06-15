-- Plan & Remind – PostgreSQL schema
--
-- If you use a custom db_prefix, prepend it to the table names below.

CREATE TABLE IF NOT EXISTS plan_and_remind (
  id                SERIAL PRIMARY KEY,
  user_id           INTEGER NOT NULL
                        REFERENCES users (user_id) ON DELETE CASCADE ON UPDATE CASCADE,
  "type"            VARCHAR(16)  NOT NULL DEFAULT 'scheduled',
  status            VARCHAR(16)  NOT NULL DEFAULT 'pending',
  send_at           TIMESTAMP    NOT NULL,
  created_at        TIMESTAMP    NOT NULL,
  sent_at           TIMESTAMP    DEFAULT NULL,
  mail_from         VARCHAR(255) NOT NULL,
  recipients        TEXT         NOT NULL,
  subject           VARCHAR(512) DEFAULT NULL,
  store_target      VARCHAR(255) DEFAULT NULL,
  delivery          TEXT         DEFAULT NULL,
  mime_message      TEXT         NOT NULL,
  sent_copy_pending SMALLINT     NOT NULL DEFAULT 0,
  error_message     TEXT         DEFAULT NULL,
  attempts          INTEGER      NOT NULL DEFAULT 0
);

CREATE INDEX ix_pnr_user ON plan_and_remind (user_id);
CREATE INDEX ix_pnr_due  ON plan_and_remind (status, send_at);
CREATE INDEX ix_pnr_copy ON plan_and_remind (user_id, sent_copy_pending);
