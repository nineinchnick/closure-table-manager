<?php

namespace nineinchnick\closureTable\mysql;

class Schema extends \nineinchnick\closureTable\Schema
{
	public function getCreateTableQuery($tableName, $primaryKey='id', $primaryKeyType='integer', $tableNameSuffix='_tree')
	{
		$treeTable = $this->getTreeTableName($tableName, $tableNameSuffix);
		$query = <<<SQL
CREATE TABLE `$treeTable` (
	id INTEGER AUTO_INCREMENT PRIMARY KEY,
	parent_id $primaryKeyType NOT NULL REFERENCES `$tableName`(`$primaryKey`) ON DELETE CASCADE,
	child_id $primaryKeyType NOT NULL REFERENCES `$tableName`(`$primaryKey`) ON DELETE CASCADE,
	depth INTEGER NOT NULL,
	UNIQUE (parent_id, child_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 
SQL;
		return $query;
	}

	public function getCreateTriggerQueries($tableName, $parentKey='parent_id', $primaryKey='id', $primaryKeyType='integer', $path=null, $pathFrom=null, $pathSeparator='/', $tableNameSuffix='_tree')
	{
		$treeTable = $this->getTreeTableName($tableName, $tableNameSuffix);
		$queries = array();
		$queries[] = <<<SQL
-- --------------------------------------------------------------------
-- INSERT:
-- 1. Insert a matching row in $treeTable where both parent and child
-- are set to the id of the newly inserted object. Depth is set to 0 as
-- both child and parent are on the same level.
--
-- 2. Copy all rows that our parent had as its parents, but we modify
-- the child id in these rows to be the id of currently inserted row,
-- and increase depth by one.
-- --------------------------------------------------------------------
DELIMITER $$
CREATE TRIGGER `{$tableName}_tree_ai` AFTER INSERT ON `$tableName`
FOR EACH ROW BEGIN
  INSERT INTO "$treeTable" (parent_id, child_id, depth)
    VALUES (NEW."$primaryKey", NEW."$primaryKey", 0);
  INSERT INTO "$treeTable" (parent_id, child_id, depth)
    SELECT x.parent_id, NEW."$primaryKey", x.depth + 1
    FROM "$treeTable" x
    WHERE x.child_id = NEW."$parentKey";
END;$$
DELIMITER ;
SQL;
		$queries[] = <<<SQL
-- As for moving data around in $tableName freely, we should forbid
-- moves that would create loops:
DELIMITER $$
CREATE TRIGGER `${tableName}_tree_bu` BEFORE UPDATE ON `$tableName`
FOR EACH ROW BEGIN
  IF NEW."$primaryKey" <> OLD."$primaryKey" THEN
    UPDATE `Changing ids is forbidden.` SET x=1;
  END IF;
  
  IF NEW."$parentKey" IS NOT NULL AND 0 < (
	SELECT COUNT(child_id)
	FROM "$treeTable"
    WHERE child_id = NEW."$parentKey" AND parent_id = NEW."$primaryKey"
  )
  THEN
    UPDATE `Update blocked, because it would create loop in tree.` SET x=1;
  END IF;
END;$$
DELIMITER ;
SQL;
		$queries[] = <<<SQL
DELIMITER $$
CREATE TRIGGER `{$tableName}_tree_au` AFTER UPDATE ON `$tableName`
FOR EACH ROW BEGIN
  IF (OLD."$parentKey" IS NULL AND NEW."$parentKey" IS NOT NULL)
	OR (OLD."$parentKey" IS NOT NULL AND NEW."$parentKey" IS NULL)
	OR (OLD."$parentKey" IS NOT NULL AND NEW."$parentKey" IS NOT NULL AND OLD."$parentKey" <> NEW."$parentKey")
  THEN
    IF OLD."$parentKey" IS NOT NULL THEN
      DELETE FROM "$treeTable" WHERE id IN (
        SELECT r2.id
        FROM "$treeTable" r1
        INNER JOIN "$treeTable" r2 ON r1.child_id = r2.child_id
        WHERE r1.parent_id = NEW."$primaryKey" AND r2.depth > r1.depth
      );
    END IF;
    IF NEW."$parentKey" IS NOT NULL THEN
      INSERT INTO "$treeTable" (parent_id, child_id, depth)
        SELECT r1.parent_id, r2.child_id, r1.depth + r2.depth + 1
        FROM "$treeTable" r1
        INNER JOIN "$treeTable" r2 ON r2.parent_id = NEW."$primaryKey"
        WHERE r1.child_id = NEW."$parentKey";
    END IF;
  END IF;
END;$$
DELIMITER ;
SQL;
		if ($path !== null) {
			$queries[] = <<<SQL
-- Generate path urls based on $pathFrom and position in the tree.
DELIMITER $$
CREATE TRIGGER `{$tableName}_tree_bi_path` BEFORE INSERT ON `$tableName`
FOR EACH ROW BEGIN
  IF NEW."$parent" IS NULL THEN
    SET NEW."$path" = NEW."$pathFrom";
  ELSE
    SELECT "$path" || '$pathSeparator' || NEW."$pathFrom" INTO NEW."$path"
    FROM "$tableName"
    WHERE "$primaryKey" = NEW."$parentKey";
  END IF;
END;$$
DELIMITER ;
SQL;
			$queries[] = <<<SQL
CREATE TRIGGER `{$tableName}_tree_bu_path` BEFORE UPDATE ON `$tableName`
FOR EACH ROW BEGIN
  SET @replace_from = '^';
  SET @replace_to = '';
  IF (OLD."$parentKey" IS NULL AND NEW."$parentKey" IS NOT NULL)
	OR (OLD."$parentKey" IS NOT NULL AND NEW."$parentKey" IS NULL)
	OR (OLD."$parentKey" IS NOT NULL AND NEW."$parentKey" IS NOT NULL AND OLD."$parentKey" <> NEW."$parentKey")
  THEN
    IF OLD."$parentKey" IS NOT NULL THEN
      SELECT '^' || $path || '$pathSeparator' INTO @replace_from
      FROM "$tableName"
      WHERE "$primaryKey" = OLD."$parentKey";
    END IF;
    IF NEW."$parentKey" IS NOT NULL THEN
      SELECT "$path" || '$pathSeparator' INTO @replace_to
      FROM "$tableName"
      WHERE "$primaryKey" = NEW."$parentKey";
    END IF;
    SET NEW."$path" = regexp_replace( NEW."$path", replace_from, replace_to );
    UPDATE "$tableName"
      SET "$path" = regexp_replace("$path", replace_from, replace_to )
      WHERE "$primaryKey" IN (
        SELECT child_id
        FROM "$treeTable"
        WHERE parent_id = NEW."$primaryKey" AND depth > 0
    );
  END IF;
END;$$
DELIMITER ;
SQL;
		}
		return $queries;
	}

	public function getDropTableQuery($tableName, $tableNameSuffix='_tree')
	{
		$treeTable = $this->getTreeTableName($tableName, $tableNameSuffix);
		return "DROP TABLE IF EXISTS `$treeTable`";
	}

	/**
	 * @param string $tableName
	 * @return array
	 */
	public function getDropTriggerQueries($tableName, $tableNameSuffix)
	{
		$suffixes = array('ai', 'bu', 'au', 'bi_path', 'bu_path');
		$treeTable = $this->getTreeTableName($tableName, $tableNameSuffix);
		$queries = array();
		foreach($suffixes as $suffix) {
			$queries[] = "DROP TRIGGER /*!50032 IF EXISTS */ `{$tableName}_tree_{$suffix}`";
		}
		return $queries;
	}
}
