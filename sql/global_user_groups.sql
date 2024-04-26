-- Additional table for the GlobalUserrights extension
-- To be added to $wgSharedDB

CREATE TABLE /*_*/global_user_groups (
  -- Key to user_id
  gug_user int unsigned NOT NULL default 0,
  -- Group name
  gug_group varbinary(255) NOT NULL default '',
  -- Expiry date
  gug_expiry varbinary(14) NULL default NULL,

  PRIMARY KEY (gug_user, gug_group)
) /*$wgDBTableOptions*/;

CREATE INDEX /*i*/gug_group ON /*_*/global_user_groups (gug_group);
CREATE INDEX /*i*/gug_expiry ON /*_*/global_user_groups (gug_expiry);