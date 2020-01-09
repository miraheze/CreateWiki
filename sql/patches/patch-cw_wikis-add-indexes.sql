-- Convert cw_wikis table to use indexes for the db column
ALTER TABLE /*$wgDBprefix*/cw_wikis
  ADD INDEX wiki_dbname (wiki_dbname);
