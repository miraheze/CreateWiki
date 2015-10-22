CREATE TABLE /*_*/cw_wikis (
  `wiki_dbname` VARCHAR(64) NOT NULL,
  `wiki_sitename` VARCHAR(32) NOT NULL,
  `wiki_language` VARCHAR(12) NOT NULL,
  `wiki_private` SMALLINT NOT NULL,
  `wiki_closed` SMALLINT NOT NULL
) /*$wgDBTableOptions*/;

