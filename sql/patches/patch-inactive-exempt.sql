ALTER TABLE /*$wgDBprefix*/cw_wikis
  ADD COLUMN wiki_inactive_exempt TINYINT NOT NULL DEFAULT '0' AFTER wiki_inactice_timestamp;
