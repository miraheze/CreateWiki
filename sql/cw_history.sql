CREATE TABLE /*_*/cw_history (
  cw_history_id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
  cw_id INT NOT NULL,
  cw_history_action VARCHAR(50) NOT NULL,
  cw_history_actor INT(10) NOT NULL,
  cw_history_details BLOB NOT NULL,
  cw_history_timestamp BINARY(14) NOT NULL,
  PRIMARY KEY(cw_history_id)
) /*$wgDBTableOptions*/;
