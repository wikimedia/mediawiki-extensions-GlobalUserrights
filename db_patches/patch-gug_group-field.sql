-- Changes the length of groups from 14 to 255 similar to user_group
ALTER TABLE /*_*/global_user_groups MODIFY gug_group varbinary(255) NOT NULL default '';
