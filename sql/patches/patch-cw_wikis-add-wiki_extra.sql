ALTER TABLE /*$wgDBprefix*/cw_wikis
  ADD COLUMN wiki_extra JSON NULL AFTER wiki_experimental;
