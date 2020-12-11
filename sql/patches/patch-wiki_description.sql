ALTER TABLE /*$wgDBprefix*/cw_wikis
  ADD COLUMN wiki_description BLOB NULL AFTER wiki_language;
