-- 四季のなぞあて オンライン対戦用テーブル
-- 既存のWordPress用MySQLデータベースにそのまま追加できます（テーブル名は suiri_ 接頭辞で衝突を避けています）。
-- phpMyAdmin等のSQL実行画面にこの内容を貼り付けて実行してください。

CREATE TABLE IF NOT EXISTS `suiri_rooms` (
  `room_code` CHAR(6) NOT NULL,
  `hand_size` TINYINT UNSIGNED NOT NULL,
  `status` ENUM('waiting','active','finished') NOT NULL DEFAULT 'waiting',
  `player1_token_hash` CHAR(64) NOT NULL,
  `player2_token_hash` CHAR(64) DEFAULT NULL,
  `state_json` LONGTEXT DEFAULT NULL,
  `version` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`room_code`),
  KEY `idx_updated_at` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
