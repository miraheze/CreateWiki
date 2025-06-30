ALTER TABLE /*$wgDBprefix*/cw_requests
  ADD COLUMN cw_extra JSON NULL AFTER cw_bio;
