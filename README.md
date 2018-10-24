# Versioning MySQL data
As a developer you’re probably using a versioning control system, like subversion or git, to safeguard your data. Advantages of using a VCS are that you can walk to the individual changes for a document, see who made each change and revert back to specific revision if needed. These are features which would also be nice for data stored in a database. With the use of triggers we can implement versioning for data stored in a MySQL db.

### BEWARE! This is a prove of concept. Do not use this in a production environment.

## How it works

### The revisioning table
We will not store the different versions of the records in the original table. We want this solution to be in the database layer instead of putting all the logic in the application layer. Instead we’ll create a new table, which stores all the different versions and lives next to the original table, which only contains the current version of each record. This revisioning table is copy of the original table, with a couple of additional fields.

```
CREATE TABLE `_revision_mytable` LIKE `mytable`;

ALTER TABLE `_revision_mytable`
  CHANGE `id` `id` int(10) unsigned,
  DROP PRIMARY KEY,
  ADD `_revision` bigint unsigned AUTO_INCREMENT,
  ADD `_revision_previous` bigint unsigned NULL,
  ADD `_revision_action` enum('INSERT','UPDATE') default NULL,
  ADD `_revision_user_id` int(10) unsigned NULL,
  ADD `_revision_timestamp` datetime NULL default NULL,
  ADD `_revision_comment` text NULL,
  ADD PRIMARY KEY (`_revision`),
  ADD INDEX (`_revision_previous`),
  ADD INDEX `org_primary` (`id`);
```

The most important field is `_revision`. This field contains a unique identifier for a version of a record from the table. Since this is the unique identifier in the revisioning table, the original id field becomes a normal (indexed) field.

We’ll also store some additional information in the revisioning table. The `_revision_previous` field hold the revision nr of the version that was updated to create this revision. Field `_revision_action` holds the action that was executed to create this revision. This field has an extra function that will discussed later. The user id and timestamp are useful for blaming changes on someone. We can add some comment per revision.

The database user is probably always the same. Storing this in the user id field is not useful. Instead, we can set variable @auth_id after logging in and on connecting to the database to the session user.

## Altering the original table
The original table needs 2 additional fields: `_revision` and `_revision_comment`. The `_revision` field holds the current active version. The field can also be used to revert to a different revision. The value of `_revision_comment` set on an update or insert will end up in the revisioning table. The field in the original table will always be empty.

```
ALTER TABLE `mytable`
  ADD `_revision` bigint unsigned NULL,
  ADD `_revision_comment` text NULL,
  ADD UNIQUE INDEX (`_revision`);
```

### The history table

Saving each version is not enough. Since we can revert back to older revisions and of course delete the record altogether, we want to store which version of the record was enabled at what time. The history table only needs to hold the revision number and a timestamp. We’ll add the primary key fields, so it’s easier to query. A user id field is included to blame.

```
CREATE TABLE `_revhistory_mytable` (
  `id` int(10) unsigned,
  `_revision` bigint unsigned NULL,
  `_revhistory_user_id` int(10) unsigned NULL,
  `_revhistory_timestamp` timestamp NOT NULL default CURRENT_TIMESTAMP,
  INDEX (`id`),
  INDEX (_revision),
  INDEX (_revhistory_user_id),
  INDEX (_revhistory_timestamp)
) ENGINE=InnoDB;
```

## How to use
Inserting, updating and deleting data should work as normal, including the INSERT … ON DUPLICATE KEY UPDATE syntax. When updating the _revision field shouldn’t be changed.

To switch to a different version, we would do something like

```
UPDATE mytable SET _revision=$rev WHERE id=$id;
```
However if the record has been deleted, there will be no record in the original table, therefore the update won’t do anything. Instead we could insert a record, specifying the revision.

```
INSERT INTO mytable SET _revision=$rev;
```
We can combine these two into a statement that works either way.

```
INSERT INTO mytable SET id=$id, _revision=$rev ON DUPLICATE KEY UPDATE _revision=VALUES(_revision);
```
The above query shows that there an additional constraint. The only thing that indicates that different versions is of the same record, is the primary key. Therefore value of the primary key can’t change on update. This might mean that some tables need to start using surrogate keys if they are not.

### On Insert
Let’s dive into the triggers. We’ll start with before insert. This trigger should get the values of a revision when the _revision field is set, or otherwise add a new row to the revision table.

```
CREATE TRIGGER `mytable-beforeinsert` BEFORE INSERT ON `mytable`
  FOR EACH ROW BEGIN
    DECLARE `var-id` int(10) unsigned;
    DECLARE `var-title` varchar(45);
    DECLARE `var-body` text;
    DECLARE `var-_revision` BIGINT UNSIGNED;
    DECLARE revisionCursor CURSOR FOR SELECT `id`, `title`, `body` FROM `_revision_mytable` WHERE `_revision`=`var-_revision` LIMIT 1;
  
    IF NEW.`_revision` IS NULL THEN
      INSERT INTO `_revision_mytable` (`_revision_comment`, `_revision_user_id`, `_revision_timestamp`) VALUES (NEW.`_revision_comment`, @auth_uid, NOW());
      SET NEW.`_revision` = LAST_INSERT_ID();
    ELSE
      SET `var-_revision`=NEW.`_revision`;
      OPEN revisionCursor;
      FETCH revisionCursor INTO `var-id`, `var-title`, `var-body`;
      CLOSE revisionCursor;
      
      SET NEW.`id` = `var-id`, NEW.`title` = `var-title`, NEW.`body` = `var-body`;
    END IF;
    
    SET NEW.`_revision_comment` = NULL;
  END

CREATE TRIGGER `mytable-afterinsert` AFTER INSERT ON `mytable`
  FOR EACH ROW BEGIN
    UPDATE `_revision_mytable` SET `id` = NEW.`id`, `title` = NEW.`title`, `body` = NEW.`body`, `_revision_action`='INSERT' WHERE `_revision`=NEW.`_revision` AND `_revision_action` IS NULL;
    INSERT INTO `_revhistory_mytable` VALUES (NEW.`id`, NEW.`_revision`, @auth_uid, NOW());
  END
```

If the `_revision` field is NULL, we insert a new row into the revision table. This action is primarily to get a revision number. We set the comment, user id and timestamp. We won’t set the values, action and previous id yet. The insert might fail or be converted into an update action by insert on duplicate key update. If the insert action fails, we’ll have an unused row in the revisioning table. This is a problem, since the primary key has not been set, so it won’t show up anywhere. We can clean up these phantom records once in a while to keep the table clean.

When `_revision` is set, we use a cursor to get the values from the revision table. We can’t fetch to values directly into NEW, therefore we first fetch them into variables and than copy that into NEW.

After insert, we’ll update the revision, setting the values and the action. However, the insert might have been an undelete action. In that case `_revision_action` is already set and we don’t need to update the revision. We also add an entry in the history table.

### On Update
The before and after update trigger do more or less the same as the before and after insert trigger.

```
CREATE TRIGGER `mytable-beforeupdate` BEFORE UPDATE ON `mytable`
  FOR EACH ROW BEGIN
    DECLARE `var-id` int(10) unsigned;
    DECLARE `var-title` varchar(45);
    DECLARE `var-body` text;
    DECLARE `var-_revision` BIGINT UNSIGNED;
    DECLARE `var-_revision_action` enum('INSERT','UPDATE','DELETE');
    DECLARE revisionCursor CURSOR FOR SELECT `id`, `title`, `body`, `_revision_action` FROM `_revision_mytable` WHERE `_revision`=`var-_revision` LIMIT 1;
    
    IF NEW.`_revision` = OLD.`_revision` THEN
      SET NEW.`_revision` = NULL;
      
    ELSEIF NEW.`_revision` IS NOT NULL THEN 
      SET `var-_revision` = NEW.`_revision`;
      
      OPEN revisionCursor;
      FETCH revisionCursor INTO `var-id`, `var-title`, `var-body`, `var-_revision_action`;
      CLOSE revisionCursor;
      
      IF `var-_revision_action` IS NOT NULL THEN
        SET NEW.`id` = `var-id`, NEW.`title` = `var-title`, NEW.`body` = `var-body`;
      END IF;
    END IF;

    IF (NEW.`id` != OLD.`id` OR NEW.`id` IS NULL != OLD.`id` IS NULL) THEN
-- Workaround for missing SIGNAL command
      DO `Can't change the value of the primary key of table 'mytable' because of revisioning`;
    END IF;

    IF NEW.`_revision` IS NULL THEN
      INSERT INTO `_revision_mytable` (`_revision_previous`, `_revision_comment`, `_revision_user_id`, `_revision_timestamp`) VALUES (OLD.`_revision`, NEW.`_revision_comment`, @auth_uid, NOW());
      SET NEW.`_revision` = LAST_INSERT_ID();
    END IF;
    
    SET NEW.`_revision_comment` = NULL;
  END

CREATE TRIGGER `mytable-afterupdate` AFTER UPDATE ON `mytable`
  FOR EACH ROW BEGIN
    UPDATE `_revision_mytable` SET `id` = NEW.`id`, `title` = NEW.`title`, `body` = NEW.`body`, `_revision_action`='UPDATE' WHERE `_revision`=NEW.`_revision` AND `_revision_action` IS NULL;
    INSERT INTO `_revhistory_mytable` VALUES (NEW.`id`, NEW.`_revision`, @auth_uid, NOW());
  END
```

If `_revision` is not set, it has the old value. In that case a new revision should be created. Setting `_revision` to NULL will have the same behaviour of not setting `_revision`. Next to the comment, user id and timestamp, we add also set the previous revision.

As said before, it’s very important that the value of primary key doesn’t change. We need to check this and trigger an error, if it would be changed.

### On Delete
Deleting won’t create a new revisiong. However we do want to log that the record has been deleted. Therefore we add an entry to the history table with `_revision` set to NULL.

```
CREATE TRIGGER `mytable-afterdelete` AFTER DELETE ON `mytable`
  FOR EACH ROW BEGIN
    INSERT INTO `_revhistory_mytable` VALUES (OLD.`id`, NULL, @auth_uid, NOW());
  END
```

## Multi-table records
often the data of a record is spread across multiple tables, like an invoice with multiple invoice lines. Having each invoice line versioned individually isn’t really useful. Instead we want a new revision of the whole invoice on each change.

Ideally a change of one or more parts of the invoice would be changed, a new revision would be created. There are several issues in actually creating this those. Detecting the change of multiple parts of the invoice at once, generating a single revision, would mean we need to know if the actions are done within the same transaction. Unfortunately there is a connection\_id(), but no transaction\_id() function in MySQL. Also, the query would fail when a query inserts or updates a record in the child table, using the parent table. We need to come up with something else.

One solution is to version the rows in the parent as well in the child tables. For each version of the parent row, we register which versions of the child rows ware set. This however has really complicated the trigger code and tends to need a lot of checking an querying slowing the write process down. Since nobody ever looks at the versions of the child rows, the application forces a new version of the parent row. The benefits of versioning both are therefor minimal.

### Only versioning the parent
For this new (simplified) implementation, we will only have one revision number across all tables of the record. Changing data from the parent table, will trigger a new version. This will not only copy the parent row to the revisioning table, but also the rows of the children.

Writing to the child will not trigger a new version, instead it will update the data in the revisioning table. This means that when changing the record, you need to write to the parent table, before writing to the child tables. To force a new version without changing values use

```
UPDATE mytable SET _revision=NULL where id=$id
```

The parent and child tables are defined as

```
CREATE TABLE `mytable` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL DEFAULT '',
  `description` text,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB

CREATE TABLE `mychild` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `mytable_id` int(10) unsigned NOT NULL DEFAULT '0',
  `title` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `mytable_id` (`mytable_id`),
  CONSTRAINT `mychild_ibfk_1` FOREIGN KEY (`mytable_id`) REFERENCES `mytable` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB
```

Note that we are using InnoDB tables here. MyISAM doesn’t have foreign key constraints, therefor it’s not possible to define a parent-child relationship.

### Insert, update and delete

In the parent trigger, two different things happen concerning the child rows. When a new version is created, the data of `mychild` is copied to the revisioning table. On a revision switch, data will be copied from the revisioning table into `mychild`. The “`_revision_action` IS NULL” condition, means that `_revision_mytable` is only updated when a new revision is created.

```
CREATE TRIGGER `mytable-afterupdate` AFTER update ON `mytable`
  FOR EACH ROW BEGIN
    DECLARE `newrev` BOOLEAN;
    
    UPDATE `_revision_mytable` SET `id` = NEW.`id`, `name` = NEW.`name`, `description` = NEW.`description`, `_revision_action`='update' WHERE `_revision`=NEW.`_revision` AND `_revision_action` IS NULL;
    SET newrev = (ROW_COUNT() > 0);
    INSERT INTO `_revhistory_mytable` VALUES (NEW.`id`, NEW.`_revision`, @auth_uid, NOW());
    
    IF newrev THEN
       INSERT INTO `_revision_mychild` SELECT *, NEW.`_revision` FROM `mychild` WHERE `mytable_id` = NEW.`id`;
    ELSE
       DELETE `t`.* FROM `mychild` AS `t` LEFT JOIN `_revision_mychild` AS `r` ON 0=1 WHERE `t`.`mytable_id` = NEW.`id`;
       INSERT INTO `mychild` SELECT `id`, `mytable_id`, `title` FROM `_revision_mychild` WHERE `_revision` = NEW.`_revision`;
    END IF;
  END

CREATE TRIGGER `mychild-afterinsert` AFTER INSERT ON `mychild`
  FOR EACH ROW BEGIN
    DECLARE CONTINUE HANDLER FOR 1442 BEGIN END;
    INSERT IGNORE INTO `_revision_mychild` (`id`, `mytable_id`, `title`, `_revision`) SELECT NEW.`id`, NEW.`mytable_id`, NEW.`title`, `_revision` FROM `mytable` AS `p` WHERE `p`.`id`=NEW.`mytable_id`;
  END

CREATE TRIGGER `mychild-afterupdate` AFTER UPDATE ON `mychild`
  FOR EACH ROW BEGIN
    REPLACE INTO `_revision_mychild` (`id`, `mytable_id`, `title`, `_revision`) SELECT NEW.`id`, NEW.`mytable_id`, NEW.`title`, `_revision` FROM `mytable` AS `p` WHERE `p`.`id`=NEW.`mytable_id`;
  END

CREATE TRIGGER `mychild-afterdelete` AFTER DELETE ON `mychild`
  FOR EACH ROW BEGIN
    DECLARE CONTINUE HANDLER FOR 1442 BEGIN END;
    DELETE `r`.* FROM `_revision_mychild` AS `r` INNER JOIN `mytable` AS `p` ON `r`.`_revision` = `p`.`_revision` WHERE `r`.`id` = OLD.`id`;
  END
```

Changing data in table `mychild` simply updates the data in the revisioning table. The revision number is grabbed from the field in the parent table.

Switching the revision can only be done through the parent table. This will also automatically change the data in the child tables. We simply delete all rows of the record and replace them with data from the revisioning table. This would however trigger the deletion of the data in `_revision_child` on which the insert has nothing to do. To prevent this, we can abuse that fact that a trigger can’t update data of a table using in the insert/update/delete query. This causes error 1442. With a continue handler we can ignore this silently.

The InnoDB constraints will handle the cascading delete. Deleting child data won’t activate the deletion trigger, which is all the better in this case.

### Without a primary key
A primary key is not required for the child table, since versioning is done purely based on the id of `mytable`.

```
CREATE TABLE `mypart` (
  `mytable_id` int(10) unsigned NOT NULL,
  `reference` varchar(255) NOT NULL,
  KEY `mytable_id` (`mytable_id`),
  CONSTRAINT `mypart_ibfk_1` FOREIGN KEY (`mytable_id`) REFERENCES `mytable` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB
```

This does cause an issue for the update and delete triggers of the child table. It can’t use the primary to id to locate the current version of the modified/removed row. This can be solved by a trick I got from PhpMyAdmin. We can simply locate the record by comparing the old values of all fields. There is no constraint for the table enforcing the uniqueness of a row, so we could be targeting multiple identical rows. Since they are identical, it doesn’t matter which one we target, as long as we limit to 1 row.

```
CREATE TRIGGER `mypart-afterupdate` AFTER UPDATE ON `mypart`
  FOR EACH ROW BEGIN
    DELETE FROM `_revision_mypart` WHERE `_revision` IN (SELECT `_revision` FROM `mytable` WHERE `id` = OLD.`mytable_id`) AND `mytable_id` = OLD.`mytable_id` AND `reference` = OLD.`reference` LIMIT 1;
    INSERT INTO `_revision_mypart` (`mytable_id`, `reference`, `_revision`) SELECT NEW.`mytable_id`, NEW.`reference`, `_revision` FROM `mytable` AS `p` WHERE `p`.`id`=NEW.`mytable_id`;
  END

CREATE TRIGGER `mypart-afterdelete` AFTER DELETE ON `mypart`
  FOR EACH ROW BEGIN
    DECLARE CONTINUE HANDLER FOR 1442 BEGIN END;
    DELETE FROM `_revision_mypart` WHERE `_revision` IN (SELECT `_revision` FROM `mytable` WHERE `id` = OLD.`mytable_id`) AND `mytable_id` = OLD.`mytable_id` AND `reference` = OLD.`reference` LIMIT 1;
  END
```

### Unique keys
The revisioning table has multiple versions of a record. Unique indexes from the original table should be converted to non-unique indexes in the revisioning table. This information can be fetched using INFORMATION_SCHEMA.

```
SELECT c.CONSTRAINT_NAME, GROUP_CONCAT(CONCAT('`', k.COLUMN_NAME, '`')) AS cols FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS AS `c` INNER JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE AS `k` ON c.TABLE_SCHEMA=k.TABLE_SCHEMA AND c.TABLE_NAME=k.TABLE_NAME AND c.CONSTRAINT_NAME=k.CONSTRAINT_NAME WHERE c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME='mytable' AND c.CONSTRAINT_TYPE='UNIQUE' AND c.CONSTRAINT_NAME != '_revision' GROUP BY c.CONSTRAINT_NAME
```

## Revisioning and replication

Revisioning using triggers, will only work with [row-based replication](https://dev.mysql.com/doc/refman/5.1/en/replication-sbr-rbr.html). On systems with statement-based replication, there is be a race condition when relying on auto-increment keys in triggers.
