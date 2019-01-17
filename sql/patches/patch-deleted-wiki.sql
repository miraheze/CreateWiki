ALTER TABLE /*$wgDBprefix*/cw_wikis
  ADD COLUMN wiki_deleted TINYINT NOT NULL DEFAULT '0' AFTER wiki_closed_timestamp,
  ADD COLUMN wiki_deleted_timestamp BINARY(14) NULL AFTER wiki_deleted;
