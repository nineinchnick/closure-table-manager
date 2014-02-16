closure-table-manager
=====================

PHP library that helps maintain adjacency list SQL structures.

TL;DR: It allows fetching all ancestors/descendants (indirect parents/children) in a single query, without using recursive queries.

Inspired by:
* [SQL::Tree Perl module](https://github.com/mlawren/sqltree)
* http://www.depesz.com/index.php/2008/04/11/my-take-on-trees-in-sql/

Currently supported databases:
* PostgreSQL
* SQLite 3
* MySQL and MariaDB

Pull requests with other databases support are very welcome.

## Usage

Call `Manager::getQueries()` to get an array of SQL queries that create a helper table to store ancestor/descendant relationships from the main table and triggers that maintain it.

When installed, triggers will block the following operations:
* Changing the primary key value
* Creating loops

A command line script is provided:
~~~
Usage: ./vendor/bin/closureTable.php [options] [operands]
Options:
  -d, --dsn <arg>         DSN connection string or just the driver name (pgsql, sqlite, mysql).
  -t, --table <arg>       Table name.
  -p, --parent <arg>      Parent foreign key column name.
  -i, --pk <arg>          Primary key column name.
  --pk_type <arg>          Primary key and parent column type.
  --path <arg>            Path column name; if set, additional triggers will be generated.
  --path_from <arg>       Column which value will be used to build a path. Its values cant't contain path_separator.
  --path_separator <arg>  Path separator character.
  --table_suffix <arg>    Suffix of the closure table.
~~~

## Example

Having the following tables:

~~~sql
CREATE TABLE products (
  id INTEGER,
  category_id INTEGER NOT NULL REFERENCES categories (id),
  -- ...
  PRIMARY KEY(id)
);

CREATE TABLE categories (
  id INTEGER,
  parent_id INTEGER NOT NULL REFERENCES categories (id),
  -- ...
  PRIMARY KEY(id)
);
~~~

It is quite common to ask database for all products in given category and it's subcategories.

~~~sql
    SELECT p.*
      FROM products p
INNER JOIN categories_tree c on p.category_id = c.id
     WHERE c.parent_id = <SOME_ID>;
~~~

When user is _in_ some category, we would like to show him _path_ to this category. So he could easily move to some parent category.

~~~sql
    SELECT c.*
      FROM categories c
INNER JOIN categories_tree t on c.id = t.parent_id
     WHERE c.id = 4
  ORDER BY t.depth DESC;
~~~

