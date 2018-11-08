ALTER TABLE /*$wgDBprefix*/cw_requests
  DROP COLUMN cw_status_comment,
  DROP COLUMN cw_status_comment_timestamp,
  DROP COLUMN cw_status_comment_user;
