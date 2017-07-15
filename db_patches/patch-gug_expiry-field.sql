-- Patch to add the "gug_expiry" field to the global user groups table

ALTER TABLE /*_*/global_user_groups ADD COLUMN gug_expiry varbinary(14) NULL default NULL;