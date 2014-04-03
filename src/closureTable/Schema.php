<?php

namespace nineinchnick\closureTable;

abstract class Schema
{
	abstract public function getCreateTableQuery($schemaName, $tableName, $primaryKey='id', $primaryKeyType='integer', $tableNameSuffix='_tree');

	abstract public function getCreateTriggerQueries($schemaName, $tableName, $parentKey='parent_id', $primaryKey='id', $primaryKeyType='integer', $path=null, $pathFrom=null, $pathSeparator='/', $tableNameSuffix='_tree');

	public function getDropTableQuery($schemaName, $tableName, $tableNameSuffix='_tree')
	{
		$treeTable = $this->getTreeTableName($tableName, $tableNameSuffix);
		return "DROP TABLE IF EXISTS \"$treeTable\"";
	}

	/**
	 * @param string $tableName
	 * @return array
	 */
	abstract public function getDropTriggerQueries($schemaName, $tableName, $tableNameSuffix);

	public function getTreeTableName($tableName, $tableNameSuffix)
	{
		return $tableName.$tableNameSuffix;
	}
}
