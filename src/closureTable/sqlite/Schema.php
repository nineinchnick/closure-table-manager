<?php

namespace nineinchnick\closureTable\sqlite;

class Schema extends nineinchnick\closureTable\Schema
{
	public function getCreateTableQuery($tableName, $primaryKey='id', $primaryKeyType='integer', $tableNameSuffix='_tree')
	{
		$treeTable = $this->getTreeTableName($tableName, $tableNameSuffix);
		$query = <<<SQL
CREATE TABLE "$treeTable" (
	id INTEGER PRIMARY KEY AUTOINCREMENT,
	parent_id $primaryKeyType NOT NULL REFERENCES "$tableName"("$primaryKey") ON DELETE CASCADE,
	child_id $primaryKeyType NOT NULL REFERENCES "$tableName"("$primaryKey") ON DELETE CASCADE,
	depth INTEGER NOT NULL,
	UNIQUE (parent_id, child_id)
)
SQL;
		return $query;
	}

	public function getCreateTriggersQueries($tableName, $parentKey='parent_id', $primaryKey='id', $primaryKeyType='integer', $path=null, $pathFrom=null, $pathSeparator='/', $tableNameSuffix='_tree')
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
CREATE TRIGGER "{$tableName}_tree_ai" AFTER INSERT ON "$tableName"
FOR EACH ROW
BEGIN
  INSERT INTO "$treeTable" (parent_id, child_id, depth)
    VALUES (NEW."$primaryKey", NEW."$primaryKey", 0);
  INSERT INTO "$treeTable" (parent_id, child_id, depth)
    SELECT x.parent_id, NEW."$primaryKey", x.depth + 1
    FROM "$treeTable" x
    WHERE x.child_id = NEW."$parentKey";
END;
SQL;
		$queries[] = <<<SQL
-- This implementation forbids changes to the primary key
CREATE TRIGGER "{$tableName}_tree_bu_1 BEFORE UPDATE ON "$tableName"
FOR EACH ROW WHEN OLD."$primaryKey" != NEW."$primaryKey"
BEGIN
  SELECT RAISE (ABORT, 'Changing ids is forbidden.');
END;
SQL;
		$queries[] = <<<SQL
-- As for moving data around in $tableName freely, we should forbid
-- moves that would create loops:
CREATE TRIGGER "{$tableName}_tree_bu_2" BEFORE UPDATE ON "$tableName"
FOR EACH ROW WHEN NEW."$parentKey" IS NOT NULL AND 0 < (
  SELECT COUNT(child_id)
  FROM "$treeTable"
  WHERE child_id = NEW."$parentKey" AND parent_id = NEW."$primaryKey"
)
BEGIN
  SELECT RAISE (ABORT, 'Update blocked, because it would create loop in tree.');
END;
SQL;

		if ($path !== null) {
			$queries[] = <<<SQL
-- If the from_path column has changed then update the path
CREATE TRIGGER "{$tableName}_tree_au_path_0" AFTER UPDATE ON "$tableName"
FOR EACH ROW WHEN OLD."$pathFrom" != NEW."$pathFrom"
BEGIN
  UPDATE "$tableName"
	SET "$path" = CASE
	  WHEN NEW."$parentKey" IS NOT NULL THEN (
		SELECT "$path"
		FROM "$tableName"
		WHERE "$primaryKey" = NEW."$parentKey"
      ) || '$pathSeparator' || "$pathFrom"
      ELSE "$pathFrom"
    END
    WHERE "$primaryKey" = OLD."$primaryKey" ;
END;
SQL;
			$queries[] = <<<SQL
-- If the from_path column has changed then update the path
CREATE TRIGGER "{$tableName}_tree_au_path_1" AFTER UPDATE ON "$tableName"
FOR EACH ROW WHEN OLD."$pathFrom" != NEW."$pathFrom"
BEGIN
  UPDATE "$tableName"
	SET "$path" = (
	  SELECT "$path"
	  FROM "$tableName"
	  WHERE "$primaryKey" = OLD."$primaryKey"
    ) || substr("$path", length(OLD."$path")+1)
    WHERE "$primaryKey" IN (
      SELECT child_id
      FROM "$treeTable"
      WHERE parent_id = OLD."$primaryKey" AND depth > 0
    );
END;
SQL;
		}

		$queries[] = <<<SQL
-- If there was no change to the parent then we can skip the rest of
-- the triggers
CREATE TRIGGER "{$tableName}_tree_au_1" AFTER UPDATE ON "$tableName"
FOR EACH ROW WHEN
  (OLD."$parentKey" IS NULL AND NEW."$parentKey" IS NULL)
  OR (
	(OLD."$parentKey" IS NOT NULL and NEW."$parentKey" IS NOT NULL)
	AND (OLD."$parentKey" = NEW."$parentKey")
  )
BEGIN
  SELECT RAISE (IGNORE);
END;
SQL;

		if ($path !== null) {
			$queries[] = <<<SQL
-- path changes - Remove the leading paths of the old parent. This has
-- to happen before we make changes to $treeTable.
CREATE TRIGGER "{$tableName}_tree_au_path_2" AFTER UPDATE ON "$tableName"
FOR EACH ROW WHEN OLD."$parentKey" IS NOT NULL
BEGIN
  UPDATE "$tableName"
    SET "$path" = substr("$path", (
      SELECT length($path || '$pathSeparator') + 1
      FROM "$tableName"
      WHERE "$primaryKey" = OLD."$parentKey"
    ))
    WHERE "$primaryKey" IN (
      SELECT child_id
      FROM "$treeTable"
      WHERE parent_id = OLD."$parentKey" AND depth > 0
    );
END;
SQL;
		}

		$queries[] = <<<SQL
-- Remove the tree data relating to the old parent
CREATE TRIGGER "{$tableName}_tree_au_2" AFTER UPDATE ON "$tableName"
FOR EACH ROW WHEN OLD."$parentKey" IS NOT NULL
BEGIN
  DELETE FROM "$treeTable" WHERE id IN (
    SELECT r2.id
    FROM "$treeTable" r1
    INNER JOIN "$treeTable" r2 ON r1.child_id = r2.child_id AND r2.depth > r1.depth
    WHERE r1.parent_id = NEW."$primaryKey"
  );
END;
-- FIXME: Also trigger when column 'path_from' changes. For the
-- moment, the user work-around is to temporarily re-parent the row.
SQL;
		$queries[] = <<<SQL
-- Finally, insert tree data relating to the new parent
CREATE TRIGGER "{$tableName}_tree_au_3" AFTER UPDATE ON "$tableName"
FOR EACH ROW WHEN NEW."$parentKey" IS NOT NULL
BEGIN
  INSERT INTO "$treeTable" (parent_id, child_id, depth)
    SELECT r1.parent_id, r2.child_id, r1.depth + r2.depth + 1
    FROM "$treeTable" r1
    INNER JOIN "$treeTable" r2 ON r2.parent_id = NEW."$primaryKey"
    WHERE r1.child_id = NEW."$parentKey";
END;
SQL;

		if ($path !== null) {
			$queries[] = <<<SQL
CREATE TRIGGER "{$tableName}_tree_ai_path_1" AFTER INSERT ON "$tableName"
FOR EACH ROW WHEN NEW."$parentKey" IS NULL
BEGIN
  UPDATE "$tableName" SET "$path" = "$pathFrom" WHERE "$primaryKey" = NEW."$primaryKey";
END;
SQL;
			$queries[] = <<<SQL
CREATE TRIGGER "{$tableName}_tree_ai_path_2" AFTER INSERT ON "$tableName"
FOR EACH ROW WHEN NEW."$parentKey" IS NOT NULL
BEGIN
  UPDATE "$tableName"
    SET "$path" = (
      SELECT "$path" || '$pathSeparator' || NEW."$pathFrom"
      FROM "$tableName"
      WHERE "$primaryKey" = NEW."$parentKey"
    )
    WHERE "$primaryKey" = NEW."$primaryKey";
END;
SQL;

			$queries[] = <<<SQL
-- Paths - update all affected rows with the new parent path
CREATE TRIGGER "{$tableName}_tree_au_path_3" AFTER UPDATE ON "$tableName"
FOR EACH ROW WHEN NEW."$parentKey" IS NOT NULL
BEGIN
  UPDATE "$tableName"
    SET "$path" = (
      SELECT "$path"
      FROM "$tableName"
      WHERE "$primaryKey" = NEW."$parentKey"
    ) || '$pathSeparator' || "$path"
    WHERE "$primaryKey" IN (
	  SELECT child_id
      FROM "$treeTable"
      WHERE parent_id = NEW."$parentKey" AND depth > 0
    );
END;
SQL;

		}

		// Triggers in SQLite are apparently executed LIFO, so you need to read these trigger statements from the bottom up.
		return array_reverse($queries);
	}

	/**
	 * @param string $tableName
	 * @return array
	 */
	public function getDropTriggersQueries($tableName, $tableNameSuffix)
	{
		$suffixes = array('ai', 'bu_1', 'bu_2', 'au_path_0', 'au_path_1', 'au_1', 'au_path_2', 'au_2', 'au_3', 'ai_path_2', 'ai_path_1', 'au_path_3');
		$treeTable = $this->getTreeTableName($tableName, $tableNameSuffix);
		$queries = array();
		foreach($suffixes as $suffix) {
			$queries[] = "DROP TRIGGER IF EXISTS \"{$tableName}_tree_{$suffix}\"";
		}
		return $queries;
	}
}
