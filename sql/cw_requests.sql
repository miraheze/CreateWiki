CREATE TABLE /*_*/cw_requests (
  cw_id INT AUTO_INCREMENT PRIMARY KEY NOT NULL,
  cw_comment TEXT DEFAULT NULL,
  cw_dbname VARCHAR(64) DEFAULT NULL,
  cw_language VARCHAR(12) NOT NULL,
  cw_private TINYINT UNSIGNED NOT NULL DEFAULT '0',
  cw_sitename VARCHAR(128) NOT NULL,
  cw_status VARCHAR(16) DEFAULT NULL,
  cw_timestamp BINARY(14) NOT NULL,
  cw_url VARCHAR(96) NOT NULL,
  cw_user INT(10) NOT NULL,
  cw_category VARCHAR(64) NOT NULL,
  cw_visibility TINYINT UNSIGNED NOT NULL DEFAULT '0',
  cw_locked TINYINT UNSIGNED NOT NULL DEFAULT '0',
  cw_bio TINYINT UNSIGNED NOT NULL DEFAULT '0',
  cw_extra LONGTEXT NULL
) /*$wgDBTableOptions*/;

CREATE INDEX /*i*/cw_status ON /*_*/cw_requests (cw_status);
CREATE INDEX /*i*/cw_timestamp ON /*_*/cw_requests (cw_timestamp);
