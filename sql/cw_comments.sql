CREATE TABLE /*_*/cw_comments (
  cw_id INT NOT NULL,
  cw_comment VARCHAR(2000) NOT NULL,
  cw_comment_timestamp VARCHAR(32) NOT NULL,
  cw_comment_user INT(10) NOT NULL
) /*$wgDBTableOptions*/;
