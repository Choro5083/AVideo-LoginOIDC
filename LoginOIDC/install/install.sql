-- LoginOIDC のテーブル。MariaDB と MySQL の両方で動く書き方にする
-- （どちらか専用の構文を使わない）。

-- IdP のユーザと AVideo のユーザの対応。1対1。
-- issuer と subject は大文字と小文字を区別して突き合わせる必要があるため、
-- 照合順序を ascii_bin にする（OIDC の sub は ASCII で 255 文字まで）。
--
-- sessions_revoked_at は、このユーザの全セッションを無効にした時刻（UNIX 秒）。
-- これ以前のログインは終わらせる。
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

-- IdP のセッション（sid）ごとの記録。Back-Channel Logout で sid を指定されたときに、
-- どのセッションを終わらせるかを決めるのに使う。
--
-- PHP のセッション ID は持たない。本体がセッション ID を付け替えることがあり、
-- 対応がずれるため。代わりに、PHP のセッションに sid を持たせ、リクエストのたびに
-- ここと照合する。時刻は UNIX 秒で持つ（PHP 側の時刻と比べるため、DB の
-- タイムゾーンの設定に左右されないようにする）。
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
