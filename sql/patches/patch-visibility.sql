ALTER TABLE /*$wgDBprefix*/cw_requests
  ADD COLUMN cw_visibility TINYINT UNSIGNED NOT NULL DEFAULT '0';
