ALTER TABLE /*$wgDBprefix*/cw_wikis
  ADD COLUMN wiki_url TEXT NULL AFTER wiki_creation;
