BEGIN;

DROP INDEX /*i*/wiki_dbname ON /*_*/cw_wikis;

ALTER TABLE /*_*/cw_wikis
  MODIFY wiki_private TINYINT NOT NULL,
  MODIFY wiki_closed TINYINT NOT NULL DEFAULT '0',
  MODIFY wiki_inactive TINYINT NOT NULL DEFAULT '0',
  MODIFY wiki_inactive_exempt TINYINT NOT NULL DEFAULT '0',
  MODIFY wiki_deleted TINYINT NOT NULL DEFAULT '0',
  MODIFY wiki_locked TINYINT NOT NULL DEFAULT '0',
  MODIFY wiki_experimental TINYINT NOT NULL DEFAULT '0';

CREATE INDEX /*i*/wiki_dbname ON /*_*/cw_wikis (wiki_dbname);

COMMIT;
