CREATE TABLE /*_*/cw_flags (
  cw_flag_id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
  cw_id INT NOT NULL,
  cw_flag_actor INT(10) NOT NULL,
  cw_flag_dbname VARCHAR(64) NOT NULL,
  cw_flag_reason TEXT NOT NULL,
  cw_flag_timestamp BINARY(14) NOT NULL,
  cw_flag_visibility TINYINT UNSIGNED NOT NULL,
  PRIMARY KEY(cw_flag_id)
) /*$wgDBTableOptions*/;
