CREATE TABLE /*_*/cw_comments (
  cw_comment_id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
  cw_id BIGINT UNSIGNED NOT NULL,
  cw_comment BLOB NOT NULL,
  cw_comment_timestamp BINARY(14) NOT NULL,
  cw_comment_actor INT(10) NOT NULL,
  PRIMARY KEY(cw_comment_id)
) /*$wgDBTableOptions*/;
