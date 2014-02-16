<?php

namespace nineinchnick\closureTable;

/**
 * A tool that creates a SQL closure table and matching triggers.
 * A closure table is used along with an adjacency list tree implementation.
 * It's a helper table, filled automatically by triggers, that helps to traverse
 * the tree without using recursive queries.
 *
 * Inspired by:
 * - Perl SQL::Tree https://github.com/mlawren/sqltree
 * - http://www.depesz.com/index.php/2008/04/11/my-take-on-trees-in-sql/
 *
 * @author Jan Waś <janek.jan@gmail.com>
 */
class Manager
{
	/**
	 * @var array mapping between PDO driver names and [[Schema]] classes.
	 * The keys of the array are PDO driver names while the values the corresponding
	 * schema class name.
	 */
	public $schemaMap = array(
		'pgsql' => '\nineinchnick\closureTable\pgsql\Schema',    // PostgreSQL
		'mysqli' => '\nineinchnick\closureTable\mysql\Schema',   // MariaDB and MySQL
		'mysql' => '\nineinchnick\closureTable\mysql\Schema',    // MariaDB and MySQL
		'sqlite' => '\nineinchnick\closureTable\sqlite\Schema',  // sqlite 3
		//'sqlite2' => '\nineinchnick\closureTable\sqlite\Schema', // sqlite 2
		//'sqlsrv' => '\nineinchnick\closureTable\mssql\Schema',   // newer MSSQL driver on MS Windows hosts
		//'oci' => '\nineinchnick\closureTable\oci\Schema',        // Oracle driver
		//'mssql' => '\nineinchnick\closureTable\mssql\Schema',    // older MSSQL driver on MS Windows hosts
		//'dblib' => '\nineinchnick\closureTable\mssql\Schema',    // dblib drivers on GNU/Linux (and maybe other OSes) hosts
		//'cubrid' => '\nineinchnick\closureTable\cubrid\Schema',  // CUBRID
	);


	/**
	 * Returns the schema information for the database opened by this connection.
	 * @return Schema the schema information for the database opened by this connection.
	 * @throws \Exception if there is no support for the current driver type or DSN is missing the driver name
	 */
	public function getSchema($dsn)
	{
		$driver = $this->getDriverName($dsn);
		if (isset($this->schemaMap[$driver])) {
			$schemaClassName = $this->schemaMap[$driver];
			return new $schemaClassName;
		} else {
			throw new \Exception("Connection does not support reading schema information for '$driver' DBMS.");
		}
	}

	/**
	 * Returns the name of the DB driver for the current dsn.
	 * @return string name of the DB driver
	 * @throws \Exception if DSN is missing the driver name
	 */
	public function getDriverName($dsn)
	{
		if (($pos = strpos($dsn, ':')) !== false) {
			return strtolower(substr($dsn, 0, $pos));
		} else {
			return strtolower($dsn);
			//throw new \Exception("The '$dsn' DSN is missing the driver name.");
			//! @todo try to connect and fetch PDO::ATTR_DRIVER_NAME
			//return strtolower($pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
		}
	}

	public function run($dsn, $tableName, $parentKey='parent_id', $primaryKey='id', $primaryKeyType='integer', $path=null, $pathFrom=null, $pathSeparator='/', $tableNameSuffix='_tree')
	{
		$queries = $this->getQueries($dsn, $tableName, $parentKey, $primaryKey, $primaryKeyType, $path, $pathFrom, $tableNameSuffix);

		foreach($queries as $query)
		{
			echo $query.PHP_EOL.PHP_EOL;
		}
	}

	public function getQueries($dsn, $tableName, $parentKey='parent_id', $primaryKey='id', $primaryKeyType='integer', $path=null, $pathFrom=null, $pathSeparator='/', $tableNameSuffix='_tree')
	{
		$schema = $this->getSchema($dsn);

		$tableQuery = $schema->getCreateTableQuery($tableName, $primaryKey, $primaryKeyType, $tableNameSuffix);
		$triggerQueries = $schema->getCreateTriggerQueries($tableName, $parentKey, $primaryKey, $primaryKeyType, $path, $pathFrom, $pathSeparator, $tableNameSuffix);

		$dropTableQuery = $schema->getDropTableQuery($tableName, $tableNameSuffix);
		$dropTriggerQueries = $schema->getDropTriggerQueries($tableName, $tableNameSuffix);

		return array_merge(array($dropTableQuery, $tableQuery), $dropTriggerQueries, $triggerQueries);
	}
}
