CREATE TABLE /*_*/cw_comments (
  cw_id INT NOT NULL,
  cw_comment BLOB NOT NULL,
  cw_comment_timestamp BINARY(14) NOT NULL,
  cw_comment_user INT(10) NOT NULL
) /*$wgDBTableOptions*/;
