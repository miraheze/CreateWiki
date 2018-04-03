CREATE TABLE /*_*/cw_requests (
  cw_id INT NULL AUTO_INCREMENT PRIMARY KEY NOT NULL,
  cw_comment TEXT DEFAULT NULL,
  cw_dbname VARCHAR(64) DEFAULT NULL,
  cw_ip VARCHAR(64) NOT NULL,
  cw_language VARCHAR(12) NOT NULL,
  cw_private SMALLINT DEFAULT NULL,
  cw_sitename VARCHAR(128) NOT NULL,
  cw_status VARCHAR(16) DEFAULT NULL,
  cw_status_comment VARCHAR(512) DEFAULT NULL,
  cw_status_comment_timestamp VARCHAR(32) DEFAULT NULL,
  cw_status_comment_user SMALLINT DEFAULT NULL,
  cw_timestamp varchar(32) NOT NULL,
  cw_url VARCHAR(96) NOT NULL,
  cw_user SMALLINT NOT NULL,
  cw_custom VARCHAR((96) NOT NULL
) /*$wgDBTableOptions*/;
