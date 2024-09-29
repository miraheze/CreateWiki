ALTER TABLE /*$wgDBprefix*/cw_requests
  ADD COLUMN cw_locked TINYINT UNSIGNED NOT NULL AFTER cw_visibility DEFAULT '0';
