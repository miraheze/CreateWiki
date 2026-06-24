ALTER TABLE /*$wgDBprefix*/cw_wikis
  ADD COLUMN wiki_inactive_exempt_expiry VARBINARY(14) NULL AFTER wiki_inactive_exempt_reason;
