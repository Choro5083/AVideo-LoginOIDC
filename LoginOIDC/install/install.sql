-- Tables of LoginOIDC. Written to run on both MariaDB and MySQL
-- (no syntax specific to either).

-- Maps a user at the provider to an AVideo user, one to one.
-- issuer and subject must be compared case-sensitively, so their collation
-- is ascii_bin (an OIDC sub is ASCII, up to 255 characters).
--
-- sessions_revoked_at is when every session of the user was revoked (UNIX seconds).
-- Sign-ins from before that moment are ended.
CREATE TABLE IF NOT EXISTS `login_oidc_identities` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `users_id` INT NOT NULL,
  `issuer` VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `subject` VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `disabled_by_sync` TINYINT(1) NOT NULL DEFAULT 0,
  `sessions_revoked_at` INT UNSIGNED NULL,
  `last_login` DATETIME NULL,
  `created` DATETIME NOT NULL,
  `modified` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE INDEX `login_oidc_identities_subject_unique` (`issuer`, `subject`),
  UNIQUE INDEX `login_oidc_identities_users_unique` (`users_id`),
  CONSTRAINT `fk_login_oidc_identities_users`
    FOREIGN KEY (`users_id`)
    REFERENCES `users` (`id`)
    ON DELETE CASCADE
    ON UPDATE CASCADE)
ENGINE = InnoDB;

-- One row per provider session (sid). Used to decide which session to end when a
-- back-channel logout names a sid.
--
-- The PHP session ID is not stored. AVideo replaces session IDs, so a mapping would
-- go stale. Instead the PHP session carries the sid, and every request checks it
-- against this table. Times are UNIX seconds, to compare with PHP's clock without
-- depending on the database time zone.
CREATE TABLE IF NOT EXISTS `login_oidc_sessions` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `users_id` INT NOT NULL,
  `sid` VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `created_at` INT UNSIGNED NOT NULL,
  `revoked_at` INT UNSIGNED NULL,
  PRIMARY KEY (`id`),
  UNIQUE INDEX `login_oidc_sessions_sid_unique` (`sid`),
  INDEX `login_oidc_sessions_users_idx` (`users_id`),
  CONSTRAINT `fk_login_oidc_sessions_users`
    FOREIGN KEY (`users_id`)
    REFERENCES `users` (`id`)
    ON DELETE CASCADE
    ON UPDATE CASCADE)
ENGINE = InnoDB;
