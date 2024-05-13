ALTER TABLE /*$wgDBprefix*/cw_wikis
  ADD COLUMN wiki_tags VARCHAR(128) NOT NULL DEFAULT '' AFTER wiki_category;
