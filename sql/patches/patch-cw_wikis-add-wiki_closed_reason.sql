ALTER TABLE /*$wgDBprefix*/cw_wikis
  ADD COLUMN wiki_closed_reason TEXT NULL AFTER wiki_closed_timestamp;
