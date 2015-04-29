<?php

/**
 * Class to generate revisioning tables and trigger for MySQL.
 */
class MySQL_Revisioning
{
	/**
	 * Database connection
	 * @var mysqli 
	 */
	protected $conn;
	
	/**
	 * Dump SQL statements
	 * @var boolean 
	 */
	public $verbose = false;
	
	/**
	 * SQL command to signal an error.
	 * @var string
	 */
	protected $signal;
	
	
	/**
	 * Connect to database
	 * 
	 * @param mysqli|array $conn  DB connection or connection settings
	 */
	public function connect($conn)
	{
		$this->conn = $conn instanceof mysqli ? $conn : new mysqli($conn['host'], $conn['user'], $conn['password'], $conn['db']);
		if (!isset($this->signal)) $this->signal = $this->conn->server_version >= 60000 ? 'SIGNAL SQLSTATE \'%errno\' SET MESSAGE_TEXT="%errmsg"' : 'DO `%errmsg`';
	}
	
	/**
	 * Performs a query on the database
	 *
	 * @param string $statement
	 * @return mysqli_result
	 */
	protected function query($statement)
	{
		if ($this->verbose) echo "\n", $statement, "\n";
		
		$result = $this->conn->query($statement);
		if (!$result) throw new Exception("Query failed ({$this->conn->errno}): {$this->conn->error} ");

		return $result;
	}
	
	/**
	 * Create a revision table based to original table.
	 * 
	 * @param string $table
	 * @param array  $info   Table information
	 */	
	protected function createRevisionTable($table, $info)
	{
		$pk = '`' . join('`, `', $info['primarykey']) . '`';
		$change_autoinc = !empty($info['autoinc']) ? "CHANGE `{$info['autoinc']}` `{$info['autoinc']}` {$info['fieldtypes'][$info['autoinc']]}," : null;
		
		$unique_index = "";
		foreach ($info['unique'] as $key=>$rows) $unique_index .= ", DROP INDEX `$key`, ADD INDEX `$key` ($rows)";
		
		$sql = <<<SQL
ALTER TABLE `_revision_$table`
  $change_autoinc
  DROP PRIMARY KEY,
  ADD `_revision` bigint unsigned AUTO_INCREMENT,
  ADD `_revision_previous` bigint unsigned NULL,
  ADD `_revision_action` enum('INSERT','UPDATE') default NULL,
  ADD `_revision_user_id` int(10) unsigned NULL,
  ADD `_revision_timestamp` datetime NULL default NULL,
  ADD `_revision_comment` text NULL,
  ADD PRIMARY KEY (`_revision`),
  ADD INDEX (`_revision_previous`),
  ADD INDEX `org_primary` ($pk)
  $unique_index
SQL;

  		$this->query("CREATE TABLE `_revision_$table` LIKE `$table`");
  		$this->query($sql);
  		$this->query("INSERT INTO `_revision_$table` SELECT *, NULL, NULL, 'INSERT', NULL, NOW(), 'Revisioning initialisation' FROM `$table`");
	}

	/**
	 * Create a revision table based to original table.
	 * 
	 * @param string $table
	 * @param array  $info   Table information
	 */	
	protected function createRevisionChildTable($table, $info)
	{
		if (!empty($info['primarykey'])) $pk = '`' . join('`, `', $info['primarykey']) . '`';
		$change_autoinc = !empty($info['autoinc']) ? "CHANGE `{$info['autoinc']}` `{$info['autoinc']}` {$info['fieldtypes'][$info['autoinc']]}," : null;
		
		$unique_index = "";
		foreach ($info['unique'] as $key=>$rows) $unique_index .= "DROP INDEX `$key`, ADD INDEX `$key` ($rows),";
		
		if (isset($pk)) $sql = <<<SQL
ALTER TABLE `_revision_$table`
  $change_autoinc
  DROP PRIMARY KEY,
  ADD `_revision` bigint unsigned,
  ADD PRIMARY KEY (`_revision`, $pk),
  ADD INDEX `org_primary` ($pk),
  $unique_index
  COMMENT = "Child of `_revision_{$info['parent']}`" 
SQL;
		else $sql = <<<SQL
ALTER TABLE `_revision_$table`
  ADD `_revision` bigint unsigned,
  ADD INDEX `_revision` (`_revision`),
  $unique_index
  COMMENT = "Child of `_revision_{$info['parent']}`"
SQL;
		
  		$this->query("CREATE TABLE `_revision_$table` LIKE `$table`");
  		$this->query($sql);
 		$this->query("INSERT INTO `_revision_$table` SELECT `t`.*, `p`.`_revision` FROM `$table` AS `t` INNER JOIN `{$info['parent']}` AS `p` ON `t`.`{$info['foreign_key']}`=`p`.`{$info['parent_key']}`");
	}

	/**
	 * Alter the existing table.
	 * 
	 * @param string $table
	 * @param array  $info   Table information
	 */
	protected function alterTable($table, $info)
	{
		foreach ($info['primarykey'] as $field) $pk_join[] = "`t`.`$field` = `r`.`$field`";
		$pk_join = join(' AND ', $pk_join);

		$sql = <<<SQL
ALTER TABLE `$table`
  ADD `_revision` bigint unsigned NULL,
  ADD `_revision_comment` text NULL,
  ADD UNIQUE INDEX (`_revision`)
SQL;

		$this->query($sql);
  		$this->query("UPDATE `$table` AS `t` INNER JOIN `_revision_$table` AS `r` ON $pk_join SET `t`.`_revision` = `r`.`_revision`");
	}
	
	/**
	 * Alter the existing table.
	 * 
	 * @param string $table
	 * @param array  $info   Table information
	 */
	function createHistoryTable($table, $info)
	{
		$pk = '`' . join('`, `', $info['primarykey']) . '`';
		foreach ($info['primarykey'] as $field) $pk_type[] = "`$field` {$info['fieldtypes'][$field]}";
		$pk_type = join(',', $pk_type);
		
		$sql = <<<SQL
CREATE TABLE `_revhistory_$table` (
  $pk_type,
  `_revision` bigint unsigned NULL,
  `_revhistory_user_id` int(10) unsigned NULL,
  `_revhistory_timestamp` timestamp NOT NULL default CURRENT_TIMESTAMP,
  INDEX ($pk),
  INDEX (_revision),
  INDEX (_revhistory_user_id),
  INDEX (_revhistory_timestamp)
) ENGINE=InnoDB
SQL;
		
  		$this->query($sql);
  		$this->query("INSERT INTO `_revhistory_$table` SELECT $pk, `_revision`, NULL, `_revision_timestamp` FROM `_revision_$table`");
	}
	
	
	/**
	 * Create before insert trigger
	 * 
	 * @param string $table
	 * @param array  $info      Table information
	 */
	protected function beforeInsert($table, $info)
	{
		$fields = '`' . join('`, `', $info['fieldnames']) . '`';
		$var_fields = '`var-' . join('`, `var-', $info['fieldnames']) . '`';
		
		foreach ($info['fieldnames'] as $field) {
			$declare_var_fields[] = "DECLARE `var-$field` {$info['fieldtypes'][$field]}";
			$new_to_var[] = "NEW.`$field` = `var-$field`";
		}
		$declare_var_fields = join(";\n", $declare_var_fields);
		$new_to_var = join(', ', $new_to_var);
		
		$sql = <<<SQL
CREATE TRIGGER `$table-beforeinsert` BEFORE INSERT ON `$table`
  FOR EACH ROW BEGIN
    $declare_var_fields;
    DECLARE `var-_revision` BIGINT UNSIGNED;
    DECLARE revisionCursor CURSOR FOR SELECT $fields FROM `_revision_$table` WHERE `_revision`=`var-_revision` LIMIT 1;
  
    IF NEW.`_revision` IS NULL THEN
      INSERT INTO `_revision_$table` (`_revision_comment`, `_revision_user_id`, `_revision_timestamp`) VALUES (NEW.`_revision_comment`, @auth_uid, NOW());
	  SET NEW.`_revision` = LAST_INSERT_ID(); 
    ELSE
      SET `var-_revision`=NEW.`_revision`;
      OPEN revisionCursor;
      FETCH revisionCursor INTO $var_fields;
      CLOSE revisionCursor;
      
      SET $new_to_var;
    END IF;
    
    SET NEW.`_revision_comment` = NULL;
  END
SQL;
		
  		$this->query("DROP TRIGGER IF EXISTS `$table-beforeinsert`");
		$this->query($sql);
	}
	
	/**
	 * Create before insert trigger
	 * 
	 * @param string $table
	 * @param array  $info      Table information
	 */
	protected function beforeUpdate($table, $info)
	{
		$fields = '`' . join('`, `', $info['fieldnames']) . '`';
		$var_fields = '`var-' . join('`, `var-', $info['fieldnames']) . '`';
		
		foreach ($info['fieldnames'] as $field) {
			$declare_var_fields[] = "DECLARE `var-$field` {$info['fieldtypes'][$field]}";
			$new_to_var[] = "NEW.`$field` = `var-$field`";
		}
		$declare_var_fields = join(";\n", $declare_var_fields);
		$new_to_var = join(', ', $new_to_var);
		
		foreach ($info['primarykey'] as $field) $pk_new_ne_old[] = "(NEW.`$field` != OLD.`$field` OR NEW.`$field` IS NULL != OLD.`$field` IS NULL)";
		$pk_new_ne_old = join(' OR ', $pk_new_ne_old);
		$signal_pk_new_ne_old = str_replace(array('%errno', '%errmsg'), array('23000', "Can't change the value of the primary key of table '$table' because of revisioning"), $this->signal);
		
		$sql = <<<SQL
CREATE TRIGGER `$table-beforeupdate` BEFORE UPDATE ON `$table`
  FOR EACH ROW BEGIN
    $declare_var_fields;
    DECLARE `var-_revision` BIGINT UNSIGNED;
    DECLARE `var-_revision_action` enum('INSERT','UPDATE','DELETE');
    DECLARE revisionCursor CURSOR FOR SELECT $fields, `_revision_action` FROM `_revision_$table` WHERE `_revision`=`var-_revision` LIMIT 1;
    
    IF NEW.`_revision` = OLD.`_revision` THEN
      SET NEW.`_revision` = NULL;
      
    ELSEIF NEW.`_revision` IS NOT NULL THEN 
      SET `var-_revision` = NEW.`_revision`;
      
      OPEN revisionCursor;
      FETCH revisionCursor INTO $var_fields, `var-_revision_action`;
      CLOSE revisionCursor;
      
      IF `var-_revision_action` IS NOT NULL THEN
        SET $new_to_var;
      END IF;
    END IF;

    IF $pk_new_ne_old THEN
      $signal_pk_new_ne_old;
    END IF;

    IF NEW.`_revision` IS NULL THEN
      INSERT INTO `_revision_$table` (`_revision_previous`, `_revision_comment`, `_revision_user_id`, `_revision_timestamp`) VALUES (OLD.`_revision`, NEW.`_revision_comment`, @auth_uid, NOW());
      SET NEW.`_revision` = LAST_INSERT_ID();
    END IF;
    
    SET NEW.`_revision_comment` = NULL;
  END
SQL;
		
		$this->query("DROP TRIGGER IF EXISTS `$table-beforeupdate`");
		$this->query($sql);
	}

	
	/**
	 * Create after insert trigger.
	 * 
	 * @param string $table
	 * @param array  $info      Table information
	 * @param array  $children  Information of child tables
	 */
	protected function afterInsert($table, $info, $children)
	{
		$aftertrigger = empty($children) ? 'afterTriggerSingle' : 'afterTriggerParent';
		$this->$aftertrigger('insert', $table, $info, $children);
	}

	/**
	 * Create after update trigger.
	 * 
	 * @param string $table
	 * @param array  $info      Table information
	 * @param array  $children  Information of child tables
	 */
	protected function afterUpdate($table, $info, $children)
	{
		$aftertrigger = empty($children) ? 'afterTriggerSingle' : 'afterTriggerParent';
		$this->$aftertrigger('update', $table, $info, $children);
	}
	
	/**
	 * Create after insert/update trigger for table without children.
	 * 
	 * @param string $action    INSERT or UPDATE
	 * @param string $table
	 * @param array  $info      Table information
	 */
	protected function afterTriggerSingle($action, $table, $info)
	{
		$pk = 'NEW.`' . join('`, NEW.`', $info['primarykey']) . '`';
		foreach ($info['fieldnames'] as $field) $fields_is_new[] = "`$field` = NEW.`$field`";
		$fields_is_new = join(', ', $fields_is_new);
		
		$sql = <<<SQL
CREATE TRIGGER `$table-after$action` AFTER $action ON `$table`
  FOR EACH ROW BEGIN
    UPDATE `_revision_$table` SET $fields_is_new, `_revision_action`='$action' WHERE `_revision`=NEW.`_revision` AND `_revision_action` IS NULL;
    INSERT INTO `_revhistory_$table` VALUES ($pk, NEW.`_revision`, @auth_uid, NOW());
  END
SQL;
		
		$this->query("DROP TRIGGER IF EXISTS `$table-after$action`");
		$this->query($sql);
	}

	/**
	 * Create after insert/update trigger for table with children.
	 * 
	 * @param string $action    INSERT or UPDATE
	 * @param string $table
	 * @param array  $info      Table information
	 * @param array  $children  Information of child tables
	 */
	protected function afterTriggerParent($action, $table, $info, $children=array())
	{
		$pk = 'NEW.`' . join('`, NEW.`', $info['primarykey']) . '`';
		foreach ($info['fieldnames'] as $field) $fields_is_new[] = "`$field` = NEW.`$field`";
		$fields_is_new = join(', ', $fields_is_new);
		
		$child_newrev = "";
		$child_switch = "";
		foreach ($children as $child=>&$chinfo) {
			$child_fields = '`' . join('`, `', $chinfo['fieldnames']) . '`';

			$child_newrev .= " INSERT INTO `_revision_$child` SELECT *, NEW.`_revision` FROM `$child` WHERE `{$chinfo['foreign_key']}` = NEW.`{$chinfo['parent_key']}`;";
			if ($action == 'update') $child_switch .= " DELETE `t`.* FROM `$child` AS `t` LEFT JOIN `_revision_{$child}` AS `r` ON 0=1 WHERE `t`.`{$chinfo['foreign_key']}` = NEW.`{$chinfo['parent_key']}`;";
			$child_switch .= " INSERT INTO `$child` SELECT $child_fields FROM `_revision_{$child}` WHERE `_revision` = NEW.`_revision`;";
		}

		$sql = <<<SQL
CREATE TRIGGER `$table-after$action` AFTER $action ON `$table`
  FOR EACH ROW BEGIN
    DECLARE `newrev` BOOLEAN;
    
    UPDATE `_revision_$table` SET $fields_is_new, `_revision_action`='$action' WHERE `_revision`=NEW.`_revision` AND `_revision_action` IS NULL;
    SET newrev = (ROW_COUNT() > 0);
    INSERT INTO `_revhistory_$table` VALUES ($pk, NEW.`_revision`, @auth_uid, NOW());
    
    IF newrev THEN
      $child_newrev
    ELSE
      $child_switch
    END IF;
  END
SQL;

		$this->query("DROP TRIGGER IF EXISTS `$table-after$action`");
		$this->query($sql);
	}
	
	/**
	 * Create after update trigger.
	 * 
	 * @param string $table
	 * @param array  $info   Table information
	 */
	protected function afterDelete($table, $info)
	{
		$pk = 'OLD.`' . join('`, OLD.`', $info['primarykey']) . '`';
		
		$sql = <<<SQL
CREATE TRIGGER `$table-afterdelete` AFTER DELETE ON `$table`
  FOR EACH ROW BEGIN
    INSERT INTO `_revhistory_$table` VALUES ($pk, NULL, @auth_uid, NOW());
  END
SQL;
		
		$this->query("DROP TRIGGER IF EXISTS `$table-afterdelete`");
		$this->query($sql);
	}
	

	/**
	 * Create after insert/update trigger.
	 * 
	 * @param string $table
	 * @param array  $info   Table information
	 */
	protected function afterInsertChild($table, $info)
	{
		$new = 'NEW.`' . join('`, NEW.`', $info['fieldnames']) . '`';
		$fields = '`' . join('`, `', $info['fieldnames']) . '`';
	
		$sql = <<<SQL
CREATE TRIGGER `$table-afterinsert` AFTER INSERT ON `$table`
  FOR EACH ROW BEGIN
    DECLARE CONTINUE HANDLER FOR 1442 BEGIN END;
    INSERT IGNORE INTO `_revision_$table` ($fields, `_revision`) SELECT $new, `_revision` FROM `{$info['parent']}` AS `p` WHERE `p`.`{$info['parent_key']}`=NEW.`{$info['foreign_key']}`;
  END
SQL;
		
		$this->query("DROP TRIGGER IF EXISTS `$table-afterinsert`");
		$this->query($sql);
	}
	
	/**
	 * Create after insert/update trigger.
	 * 
	 * @param string $table
	 * @param array  $info   Table information
	 */
	protected function afterUpdateChild($table, $info)
	{
		$new = 'NEW.`' . join('`, NEW.`', $info['fieldnames']) . '`';
		$fields = '`' . join('`, `', $info['fieldnames']) . '`';
		$delete = null;
		
		if (empty($info['primarykey'])) {
			foreach ($info['fieldnames'] as $field) $fields_is_old[] = "`$field` = OLD.`$field`";
			$delete = "DELETE FROM `_revision_$table` WHERE `_revision` IN (SELECT `_revision` FROM `{$info['parent']}` WHERE `{$info['parent_key']}` = OLD.`{$info['foreign_key']}`) AND " . join(' AND ', $fields_is_old) . " LIMIT 1;";
		}
		
		$sql = <<<SQL
CREATE TRIGGER `$table-afterupdate` AFTER UPDATE ON `$table`
  FOR EACH ROW BEGIN
    $delete
    REPLACE INTO `_revision_$table` ($fields, `_revision`) SELECT $new, `_revision` FROM `{$info['parent']}` AS `p` WHERE `p`.`{$info['parent_key']}`=NEW.`{$info['foreign_key']}`;
  END
SQL;
		
		$this->query("DROP TRIGGER IF EXISTS `$table-afterupdate`");
		$this->query($sql);
	}
	
	/**
	 * Create after update trigger.
	 * 
	 * @param string $table
	 * @param array  $info   Table information
	 */
	protected function afterDeleteChild($table, $info)
	{
		if (!empty($info['primarykey'])) {
			foreach ($info['primarykey'] as $field) $fields_is_old[] = "`r`.`$field` = OLD.`$field`";
			$delete = "DELETE `r`.* FROM `_revision_$table` AS `r` INNER JOIN `{$info['parent']}` AS `p` ON `r`.`_revision` = `p`.`_revision` WHERE " . join(' AND ', $fields_is_old) . ";";
		
		} else {
			foreach ($info['fieldnames'] as $field) $fields_is_old[] = "`$field` = OLD.`$field`";
			$delete = "DELETE FROM `_revision_$table` WHERE `_revision` IN (SELECT `_revision` FROM `{$info['parent']}` WHERE `{$info['parent_key']}` = OLD.`{$info['foreign_key']}`) AND " . join(' AND ', $fields_is_old) . " LIMIT 1;";
		}

		$sql = <<<SQL
CREATE TRIGGER `$table-afterdelete` AFTER DELETE ON `$table`
  FOR EACH ROW BEGIN
    DECLARE CONTINUE HANDLER FOR 1442 BEGIN END;
    $delete
  END
SQL;
		
		$this->query("DROP TRIGGER IF EXISTS `$table-afterdelete`");
		$this->query($sql);
	}
	
	
	/**
	 * Add revisioning
	 * 
	 * @param array $args
	 */
	public function install($args)
	{
		foreach ($args as $arg) {
			if (is_string($arg)) {
				$matches = null;
				if (!preg_match_all('/[^,()\s]++/', $arg, $matches, PREG_PATTERN_ORDER)) continue;
			} else {
				$matches[0] = $arg;
			}
			
			$exists = false;
			$tables = array();
			
			// Prepare
			foreach ($matches[0] as $i=>$table) {
				$info = array('parent'=>$i > 0 ? $matches[0][0] : null, 'unique'=>array());
				$result = $this->query("DESCRIBE `$table`;");
				
				while ($field = $result->fetch_assoc()) {
					if (preg_match('/^_revision/', $field['Field'])) {
						$exists = true;
						continue;
					}
					
					$info['fieldnames'][] = $field['Field'];
					if ($field['Key'] == 'PRI') $info['primarykey'][] = $field['Field'];
					if (preg_match('/\bauto_increment\b/i', $field['Extra'])) $info['autoinc'] = $field['Field'];
					$info['fieldtypes'][$field['Field']] = $field['Type'];
				}
				
				$result = $this->query("SELECT c.CONSTRAINT_NAME, GROUP_CONCAT(CONCAT('`', k.COLUMN_NAME, '`')) AS cols FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS AS `c` INNER JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE AS `k` ON c.TABLE_SCHEMA=k.TABLE_SCHEMA AND c.TABLE_NAME=k.TABLE_NAME AND c.CONSTRAINT_NAME=k.CONSTRAINT_NAME WHERE c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME='$table' AND c.CONSTRAINT_TYPE='UNIQUE' AND c.CONSTRAINT_NAME != '_revision' GROUP BY c.CONSTRAINT_NAME");
				while ($key = $result->fetch_row()) $info['unique'][$key[0]] = $key[1];
				
				if (empty($info['parent'])) {
					if (empty($info['primarykey'])) {
						trigger_error("Unable to add revisioning table '$table': Table does not have a primary key", E_USER_WARNING);
						continue 2;
					}
				} else {
					$result = $this->query("SELECT `COLUMN_NAME`, `REFERENCED_COLUMN_NAME` FROM `INFORMATION_SCHEMA`.`KEY_COLUMN_USAGE` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = '$table' AND REFERENCED_TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = '{$info['parent']}' AND `REFERENCED_COLUMN_NAME` IS NOT NULL");
					if ($result->num_rows == 0) {
						trigger_error("Unable to add revisioning table '$table' as child of '{$info['parent']}': Table does not have a foreign key reference to parent table", E_USER_WARNING);
						continue;
					}
					list($info['foreign_key'], $info['parent_key']) = $result->fetch_row();
				}
				
				$tables[$table] = $info;
			}

			// Process
			reset($tables);
			$table = key($tables);
			$info = array_shift($tables);

			echo "Installing revisioning for `$table`";
			
			// Parent
			if (!$exists) {
				echo " - tables";
				$this->createRevisionTable($table, $info);
				$this->alterTable($table, $info);
				$this->createHistoryTable($table, $info);
			}
			
			echo " - triggers";
			$this->beforeInsert($table, $info, $tables);
			$this->afterUpdate($table, $info, $tables);
			
			$this->beforeUpdate($table, $info, $tables);
			$this->afterInsert($table, $info, $tables);
			
			$this->afterDelete($table, $info, $tables);
			
			// Children
			foreach ($tables as $table=>&$info) {
				echo "\n  - child `$table`";
				
				if (!$exists) {
					echo " - tables";
					$this->createRevisionChildTable($table, $info);
				}
				
				echo " - triggers";
				$this->afterInsertChild($table, $info);
				$this->afterUpdateChild($table, $info);
				$this->afterDeleteChild($table, $info);
			}
			
			echo "\n";			
		}
	}
	
	/**
	 * Remove revisioning for tables
	 * 
	 * @param $args
	 */
	public function remove($args)
	{
		foreach ($args as $arg) {
			$matches = null;
			if (!preg_match_all('/[^,()\s]++/', $arg, $matches, PREG_PATTERN_ORDER)) return;
			
			// Prepare
			foreach ($matches[0] as $i=>$table) {
				echo "Removing revisioning for `$table`\n";
				
				$this->query("DROP TRIGGER IF EXISTS `$table-afterdelete`;");
				$this->query("DROP TRIGGER IF EXISTS `$table-afterupdate`;");
				$this->query("DROP TRIGGER IF EXISTS `$table-beforeupdate`;");
				$this->query("DROP TRIGGER IF EXISTS `$table-afterinsert`;");
				$this->query("DROP TRIGGER IF EXISTS `$table-beforeinsert`;");
				
				if ($i == 0) $this->query("DROP TABLE IF EXISTS `_revhistory_$table`;");
				$this->query("DROP TABLE IF EXISTS `_revision_$table`;");
				if ($i == 0) $this->query("ALTER TABLE `$table` DROP `_revision`, DROP `_revision_comment`");
			}			
		}
	}
	
	public function help()
	{
		echo <<<MESSAGE
Usage: php {$_SERVER['argv'][0]} [OPTION]... [--install|--remove] TABLE...

Options:
  --host=HOSTNAME  MySQL hostname
  --user=USER      MySQL username
  --password=PWD   MySQL password
  --db=DATABASE    MySQL database

  --signal=CMD     SQL command to signal an error

MESSAGE;
	}
	
	
	/**
	 * Execute command line script
	 */
	public function execCmd()
	{
		$args = array();
		$settings = array('host'=>'localhost', 'user'=>null, 'password'=>null, 'db'=>null);
				
		for ($i=1; $i < $_SERVER['argc']; $i++) {
			if (strncmp($_SERVER['argv'][$i], '--', 2) == 0) {
				list($key, $value) = explode("=", substr($_SERVER['argv'][$i], 2), 2) + array(1=>null);
				
				if (property_exists($this, $key)) $this->$key = isset($value) ? $value : true;
				  elseif (!isset($value)) $cmd = $key;
				  else $settings[$key] = $value;
			} else {
				$args[] = $_SERVER['argv'][$i];
			}
		}
		
		if (!isset($cmd)) $cmd = empty($args) ? 'help' : 'install';
		
		$this->connect($settings);
		$this->$cmd($args);
	}
}

// Execute controller command
if (isset($_SERVER['argv']) && realpath($_SERVER['argv'][0]) == realpath(__FILE__)) {
	$ctl = new MySQL_Revisioning();
	$ctl->execCmd();
}
