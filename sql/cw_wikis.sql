CREATE TABLE /*_*/cw_wikis (
  wiki_dbname VARCHAR(64) NOT NULL PRIMARY KEY,
  wiki_sitename VARCHAR(128) NOT NULL,
  wiki_language VARCHAR(12) NOT NULL,
  wiki_private TINYINT NOT NULL,
  wiki_creation BINARY(14) NULL,
  wiki_url TEXT NULL,
  wiki_closed TINYINT NOT NULL DEFAULT '0',
  wiki_closed_timestamp BINARY(14) NULL,
  wiki_inactive TINYINT NOT NULL DEFAULT '0',
  wiki_inactive_timestamp BINARY(14) NULL,
  wiki_inactive_exempt TINYINT NOT NULL DEFAULT '0',
  wiki_inactive_exempt_reason TEXT NULL,
  wiki_deleted TINYINT NOT NULL DEFAULT '0',
  wiki_deleted_timestamp BINARY(14) NULL,
  wiki_locked TINYINT NOT NULL DEFAULT '0',
  wiki_settings LONGTEXT NULL,
  wiki_dbcluster VARCHAR(5) DEFAULT 'c1',
  wiki_category VARCHAR(64) NOT NULL,
  wiki_extensions MEDIUMTEXT NULL,
  wiki_experimental TINYINT NOT NULL DEFAULT '0'
) /*$wgDBTableOptions*/;

CREATE INDEX /*i*/wiki_dbname ON /*_*/cw_wikis (wiki_dbname);
