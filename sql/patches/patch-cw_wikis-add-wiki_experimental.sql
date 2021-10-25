ALTER TABLE /*$wgDBprefix*/cw_wikis
  ADD COLUMN wiki_experimental SMALLINT NOT NULL DEFAULT '0';
