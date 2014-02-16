<?php

namespace nineinchnick\closureTable\pgsql;

class Schema extends \nineinchnick\closureTable\Schema
{
	public function getCreateTableQuery($tableName, $primaryKey='id', $primaryKeyType='integer', $tableNameSuffix='_tree')
	{
		$treeTable = $this->getTreeTableName($tableName, $tableNameSuffix);
		$query = <<<SQL
CREATE TABLE "$treeTable" (
	id SERIAL PRIMARY KEY,
	parent_id $primaryKeyType NOT NULL REFERENCES "$tableName"("$primaryKey") ON DELETE CASCADE,
	child_id $primaryKeyType NOT NULL REFERENCES "$tableName"("$primaryKey") ON DELETE CASCADE,
	depth INTEGER NOT NULL,
	UNIQUE (parent_id, child_id)
) 
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
CREATE OR REPLACE FUNCTION "{$tableName}_tree_ai"() RETURNS TRIGGER AS
\$BODY\$
BEGIN
  INSERT INTO "$treeTable" (parent_id, child_id, depth)
    VALUES (NEW."$primaryKey", NEW."$primaryKey", 0);
  INSERT INTO "$treeTable" (parent_id, child_id, depth)
    SELECT x.parent_id, NEW."$primaryKey", x.depth + 1
    FROM "$treeTable" x
    WHERE x.child_id = NEW."$parentKey";
  RETURN NEW;
END;
\$BODY\$
LANGUAGE 'plpgsql'
SQL;
		$queries[] = <<<SQL
-- As for moving data around in $tableName freely, we should forbid
-- moves that would create loops:
CREATE OR REPLACE FUNCTION "${tableName}_tree_bu"() RETURNS TRIGGER AS
\$BODY\$
BEGIN
  IF NEW."$primaryKey" <> OLD."$primaryKey" THEN
    RAISE EXCEPTION 'Changing ids is forbidden.';
  END IF;
  IF OLD."$parentKey" IS NOT DISTINCT FROM NEW."$parentKey" THEN
    RETURN NEW;
  END IF;
  IF NEW."$parentKey" IS NULL THEN
    RETURN NEW;
  END IF;
  PERFORM 1 FROM "$treeTable" WHERE ( parent_id, child_id ) = ( NEW."$primaryKey", NEW."$parentKey" );
  IF FOUND THEN
    RAISE EXCEPTION 'Update blocked, because it would create loop in tree.';
  END IF;
  RETURN NEW;
END;
\$BODY\$
LANGUAGE 'plpgsql'
SQL;
		$queries[] = <<<SQL
CREATE OR REPLACE FUNCTION "{$tableName}_tree_au"() RETURNS TRIGGER AS
\$BODY\$
BEGIN
  IF OLD."$parentKey" IS NOT DISTINCT FROM NEW."$parentKey" THEN
    RETURN NEW;
  END IF;
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
  RETURN NEW;
END;
\$BODY\$
LANGUAGE 'plpgsql'
SQL;
		if ($path !== null) {
			$queries[] = <<<SQL
-- Generate path urls based on $pathFrom and position in the tree.
CREATE OR REPLACE FUNCTION "{$tableName}_tree_bi_path"() RETURNS TRIGGER AS
\$BODY\$
BEGIN
  IF NEW."$parent" IS NULL THEN
    NEW."$path" := NEW."$pathFrom";
  ELSE
    SELECT "$path" || '$pathSeparator' || NEW."$pathFrom" INTO NEW."$path"
    FROM "$tableName"
    WHERE "$primaryKey" = NEW."$parentKey";
  END IF;
  RETURN NEW;
END;
\$BODY\$
LANGUAGE 'plpgsql'
SQL;
			$queries[] = <<<SQL
CREATE OR REPLACE FUNCTION "{$tableName}_tree_bu_path"() RETURNS TRIGGER AS
\$BODY\$
DECLARE
  replace_from TEXT := '^';
  replace_to TEXT := '';
BEGIN
  IF OLD."$parentKey" IS NOT DISTINCT FROM NEW."$parentKey" THEN
    RETURN NEW;
  END IF;
  IF OLD."$parentKey" IS NOT NULL THEN
    SELECT '^' || $path || '$pathSeparator' INTO replace_from
    FROM "$tableName"
    WHERE "$primaryKey" = OLD."$parentKey";
  END IF;
  IF NEW."$parentKey" IS NOT NULL THEN
    SELECT "$path" || '$pathSeparator' INTO replace_to
    FROM "$tableName"
    WHERE "$primaryKey" = NEW."$parentKey";
  END IF;
  NEW."$path" := regexp_replace( NEW."$path", replace_from, replace_to );
  UPDATE "$tableName"
    SET "$path" = regexp_replace("$path", replace_from, replace_to )
    WHERE "$primaryKey" IN (
      SELECT child_id
      FROM "$treeTable"
      WHERE parent_id = NEW."$primaryKey" AND depth > 0
  );
  RETURN NEW;
END;
\$BODY\$
LANGUAGE 'plpgsql';
SQL;
		}
		$queries[] = "CREATE TRIGGER \"{$tableName}_tree_ai\" AFTER INSERT ON \"$tableName\" FOR EACH ROW EXECUTE PROCEDURE \"{$tableName}_tree_bi\"()";
		$queries[] = "CREATE TRIGGER \"{$tableName}_tree_bu\" BEFORE UPDATE ON \"$tableName\" FOR EACH ROW EXECUTE PROCEDURE \"{$tableName}_tree_bu\"()";
		$queries[] = "CREATE TRIGGER \"{$tableName}_tree_au\" AFTER UPDATE ON \"$tableName\" FOR EACH ROW EXECUTE PROCEDURE \"{$tableName}_tree_au\"()";
		if ($path !== null) {
			$queries[] = "CREATE TRIGGER \"{$tableName}_tree_bi_path\" BEFORE INSERT ON \"$tableName\" FOR EACH ROW EXECUTE PROCEDURE \"{$tableName}_tree_bi_path\"()";
			$queries[] = "CREATE TRIGGER \"{$tableName}_tree_bu_path\" BEFORE UPDATE ON \"$tableName\" FOR EACH ROW EXECUTE PROCEDURE \"{$tableName}_tree_bu_path\"()";
		}
		return $queries;
	}

	/**
	 * @param string $tableName
	 * @return array
	 */
	public function getDropTriggerQueries($tableName, $tableNameSuffix)
	{
		$suffixes = array('ai', 'bu', 'au', 'bi_path', 'bu_path');
		$queries = array();
		foreach($suffixes as $suffix) {
			$queries[] = "DROP TRIGGER IF EXISTS \"{$tableName}_tree_{$suffix}\" ON TABLE \"$treeTable\"";
		}
		foreach($suffixes as $suffix) {
			$queries[] = "DROP FUNCTION IF EXISTS \"{$tableName}_tree_{$suffix}\"()";
		}
		return $queries;
	}
}
