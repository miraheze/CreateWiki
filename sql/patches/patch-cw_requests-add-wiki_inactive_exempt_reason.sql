ALTER TABLE /*$wgDBprefix*/cw_wikis
  ADD COLUMN wiki_inactive_exempt_reason TEXT NULL AFTER wiki_inactive_exempt;
