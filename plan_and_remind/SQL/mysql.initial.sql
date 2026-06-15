-- Plan & Remind – MySQL / MariaDB schema
--
-- If you use a custom db_prefix, prepend it to the table names below
-- (both `plan_and_remind` and the referenced `users` table).

CREATE TABLE IF NOT EXISTS `plan_and_remind` (
  `id`                INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`           INT(10) UNSIGNED NOT NULL,
  `type`              VARCHAR(16)  NOT NULL DEFAULT 'scheduled',
  `status`            VARCHAR(16)  NOT NULL DEFAULT 'pending',
  `send_at`           DATETIME     NOT NULL,
  `created_at`        DATETIME     NOT NULL,
  `sent_at`           DATETIME     DEFAULT NULL,
  `mail_from`         VARCHAR(255) NOT NULL,
  `recipients`        TEXT         NOT NULL,
  `subject`           VARCHAR(512) DEFAULT NULL,
  `store_target`      VARCHAR(255) DEFAULT NULL,
  `delivery`          TEXT         DEFAULT NULL,
  `mime_message`      LONGTEXT     NOT NULL,
  `sent_copy_pending` TINYINT(1)   NOT NULL DEFAULT 0,
  `error_message`     TEXT         DEFAULT NULL,
  `attempts`          INT(10) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  INDEX `ix_pnr_user` (`user_id`),
  INDEX `ix_pnr_due` (`status`, `send_at`),
  INDEX `ix_pnr_copy` (`user_id`, `sent_copy_pending`),
  CONSTRAINT `fk_pnr_user` FOREIGN KEY (`user_id`)
      REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
