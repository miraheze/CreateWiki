ALTER TABLE /*$wgDBprefix*/cw_wikis
  ADD COLUMN wiki_locked TINYINT NOT NULL DEFAULT '0' AFTER wiki_deleted_timestamp,
