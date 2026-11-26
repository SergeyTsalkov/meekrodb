<?php
class SchemaRecord extends MeekroORM {
  static $_tablename = 'schema_test';
}

// the same table name in two schemas: public.schema_test and meekro_other.schema_test,
// with different columns and primary keys
class PgsqlSchemaTest extends SimpleTest {
  function __construct() {
    DB::$param_char = '%';
    SchemaRecord::_orm_struct_reset();
  }

  function skip() {
    if ($this->db_type != 'pgsql') return "schema test is pgsql-only";
  }

  function test_01_create_tables() {
    DB::query("DROP SCHEMA IF EXISTS meekro_other CASCADE");
    DB::query("DROP TABLE IF EXISTS schema_test");
    DB::query("CREATE SCHEMA meekro_other");
    DB::query("CREATE TABLE schema_test (id SERIAL PRIMARY KEY, name VARCHAR(255) NOT NULL DEFAULT '')");
    DB::query("CREATE TABLE meekro_other.schema_test (code VARCHAR(255) PRIMARY KEY, other_col INT NULL)");
    DB::query("CREATE TABLE meekro_other.other_only (id INT)");
  }

  // * default search_path: columnList(), tableList() and the ORM only see public.schema_test
  function test_02_default_search_path() {
    $this->assert(array_keys(DB::columnList('schema_test')) === ['id', 'name']);

    $tables = DB::tableList();
    $this->assert(count(array_keys($tables, 'schema_test')) === 1);
    $this->assert(!in_array('other_only', $tables));

    SchemaRecord::_orm_struct_reset();
    $Record = new SchemaRecord();
    $Record->name = 'public';
    $Record->Save();
    $this->assert(SchemaRecord::Load($Record->id)->name === 'public');
  }

  // * with meekro_other first on search_path, they only see meekro_other.schema_test,
  //   and tableList() includes tables from both schemas, with schema_test listed once
  function test_03_other_schema_first() {
    DB::query("SET search_path TO meekro_other, public");

    $this->assert(array_keys(DB::columnList('schema_test')) === ['code', 'other_col']);

    $tables = DB::tableList();
    $this->assert(count(array_keys($tables, 'schema_test')) === 1);
    $this->assert(in_array('other_only', $tables));

    SchemaRecord::_orm_struct_reset();
    $Record = new SchemaRecord();
    $Record->code = 'A1';
    $Record->other_col = 5;
    $Record->Save();
    $this->assert(SchemaRecord::Load('A1')->other_col === 5);
  }

  function test_04_cleanup() {
    DB::query("SET search_path TO DEFAULT");
    DB::query("DROP SCHEMA meekro_other CASCADE");
    DB::query("DROP TABLE schema_test");
    SchemaRecord::_orm_struct_reset();
  }
}
