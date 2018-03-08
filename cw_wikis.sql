CREATE TABLE /*_*/cw_wikis (
  `wiki_dbname` VARCHAR(64) NOT NULL PRIMARY KEY,
  `wiki_sitename` VARCHAR(128) NOT NULL,
  `wiki_language` VARCHAR(12) NOT NULL,
  `wiki_private` SMALLINT NOT NULL,
  `wiki_closed` SMALLINT NOT NULL,
  `wiki_settings` MEDIUMTEXT NULL,
  `wiki_dbcluster` VARCHAR(5) DEFAULT 'c1'
) /*$wgDBTableOptions*/;

