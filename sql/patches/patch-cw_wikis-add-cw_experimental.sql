ALTER TABLE /*$wgDBprefix*/cw_wikis
  ADD COLUMN cw_experimental SMALLINT NOT NULL DEFAULT '0';
