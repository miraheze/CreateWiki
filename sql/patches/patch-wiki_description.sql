ALTER TABLE /*$wgDBprefix*/cw_wikis
  ADD COLUMN wiki_description TEXT NULL AFTER wiki_language;
