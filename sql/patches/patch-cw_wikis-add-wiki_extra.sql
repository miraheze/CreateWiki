ALTER TABLE /*$wgDBprefix*/cw_wikis
  ADD COLUMN wiki_extra LONGTEXT NULL AFTER wiki_experimental;
