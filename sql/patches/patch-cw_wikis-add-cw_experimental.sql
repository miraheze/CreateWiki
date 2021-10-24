ALTER TABLE /*$wgDBprefix*/cw_requests
  ADD COLUMN cw_experimental SMALLINT NOT NULL DEFAULT '0';
