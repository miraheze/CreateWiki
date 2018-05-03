CREATE TABLE /*_*/cw_comments (
  cw_id INT NOT NULL,
  cw_comment VARCHAR(512) NOT NULL,
  cw_comment_timestamp VARCHAR(32) NOT NULL,
  cw_comment_user SMALLINT NOT NULL
) /*$wgDBTableOptions*/;
