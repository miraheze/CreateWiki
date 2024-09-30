ALTER TABLE /*$wgDBprefix*/cw_requests
  ADD COLUMN cw_locked TINYINT UNSIGNED NOT NULL DEFAULT '0' AFTER cw_visibility;
