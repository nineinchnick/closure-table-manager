#!/usr/bin/env php
<?php

namespace nineinchnick\closureTable;

require_once __DIR__ . '/../../../autoload.php';

use Ulrichsg\Getopt\Getopt;

$getopt = new Getopt(array(
    array('d', 'dsn', Getopt::REQUIRED_ARGUMENT, 'DSN connection string or just the driver name (pgsql, sqlite, mysql).'),
    array('t', 'table', Getopt::REQUIRED_ARGUMENT, 'Table name.'),
    array('p', 'parent', Getopt::REQUIRED_ARGUMENT, 'Parent foreign key column name.', 'parent_id'),
    array('i', 'pk', Getopt::REQUIRED_ARGUMENT, 'Primary key column name.', 'id'),
    array(null, 'pk_type', Getopt::REQUIRED_ARGUMENT,' Primary key and parent column type.', 'integer'),
    array(null, 'path', Getopt::REQUIRED_ARGUMENT, 'Path column name; if set, additional triggers will be generated.'),
    array(null, 'path_from', Getopt::REQUIRED_ARGUMENT, 'Column which value will be used to build a path. Its values cant\'t contain path_separator.'),
    array(null, 'path_separator', Getopt::REQUIRED_ARGUMENT, 'Path separator character.', '/'),
    array(null, 'table_suffix', Getopt::REQUIRED_ARGUMENT, 'Suffix of the closure table.', '_tree'),
));

$getopt->parse();

if ($getopt['dsn'] === null || $getopt['table'] === null) {
	echo $getopt->getHelpText();
	exit(1);
}

$manager = new Manager;
$manager->run($getopt['dsn'], $getopt['table'], $getopt['parent'], $getopt['pk'], $getopt['pk_type'], $getopt['path'], $getopt['path_from'], $getopt['path_separator'], $getopt['table_suffix']);
