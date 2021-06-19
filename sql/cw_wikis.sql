CREATE TABLE /*_*/cw_wikis (
  wiki_dbname VARCHAR(64) NOT NULL PRIMARY KEY,
  wiki_sitename VARCHAR(128) NOT NULL,
  wiki_language VARCHAR(12) NOT NULL,
  wiki_private SMALLINT NOT NULL,
  wiki_creation BINARY(14) NULL,
  wiki_url TEXT NULL,
  wiki_closed SMALLINT NOT NULL,
  wiki_closed_timestamp BINARY(14) NULL,
  wiki_inactive SMALLINT NOT NULL,
  wiki_inactive_timestamp BINARY(14) NULL,
  wiki_inactive_exempt SMALLINT NOT NULL,
  wiki_inactive_exempt_reason TEXT NULL,
  wiki_deleted SMALLINT NOT NULL,
  wiki_deleted_timestamp BINARY(14) NULL,
  wiki_locked TINYINT NOT NULL,
  wiki_settings LONGTEXT NULL,
  wiki_dbcluster VARCHAR(5) DEFAULT 'c1',
  wiki_category VARCHAR(64) NOT NULL,
  wiki_extensions MEDIUMTEXT NULL
) /*$wgDBTableOptions*/;

CREATE INDEX /*i*/wiki_dbname ON /*_*/cw_wikis (wiki_dbname);
