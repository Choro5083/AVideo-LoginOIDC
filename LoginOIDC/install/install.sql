-- LoginOIDC のテーブル。MariaDB と MySQL の両方で動く書き方にする
-- （どちらか専用の構文を使わない）。

-- IdP のユーザと AVideo のユーザの対応。1対1。
-- issuer と subject は大文字と小文字を区別して突き合わせる必要があるため、
-- 照合順序を ascii_bin にする（OIDC の sub は ASCII で 255 文字まで）。
CREATE TABLE IF NOT EXISTS `login_oidc_identities` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `users_id` INT NOT NULL,
  `issuer` VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `subject` VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `disabled_by_sync` TINYINT(1) NOT NULL DEFAULT 0,
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

-- IdP のセッション（sid）と PHP のセッションの対応。
-- Back-Channel Logout と、同期で無効化したユーザのセッションを切るのに使う。
CREATE TABLE IF NOT EXISTS `login_oidc_sessions` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `users_id` INT NOT NULL,
  `php_session_id` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `sid` VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `created` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE INDEX `login_oidc_sessions_php_unique` (`php_session_id`),
  INDEX `login_oidc_sessions_sid_idx` (`sid`),
  INDEX `login_oidc_sessions_users_idx` (`users_id`),
  CONSTRAINT `fk_login_oidc_sessions_users`
    FOREIGN KEY (`users_id`)
    REFERENCES `users` (`id`)
    ON DELETE CASCADE
    ON UPDATE CASCADE)
ENGINE = InnoDB;
