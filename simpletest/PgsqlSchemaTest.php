<?php
class SchemaUser extends MeekroORM {
  static $_tablename = 'users';
}

// the same table name in two schemas: the standard public.users, and meekro_other.users
// with different columns and primary key
class PgsqlSchemaTest extends SimpleTest {
  function __construct() {
    $this->resetDatabase();
    if ($this->db_type != 'pgsql') return;

    DB::query("DROP SCHEMA IF EXISTS meekro_other CASCADE");
    DB::query("CREATE SCHEMA meekro_other");
    DB::query("CREATE TABLE meekro_other.users (code VARCHAR(255) PRIMARY KEY, other_col INT NULL)");
    DB::query("CREATE TABLE meekro_other.other_only (id INT)");
  }

  function skip() {
    if ($this->db_type != 'pgsql') return "schema test is pgsql-only";
  }

  // * default search_path: columnList(), tableList() and the ORM only see public.users
  function test_01_default_search_path() {
    $this->assert(array_keys(DB::columnList('users')) === ['user_id', 'employer_id', 'name', 'nickname',
      'age', 'height', 'is_active', 'settings', 'signed_up_at']);

    $tables = DB::tableList();
    $this->assert(count(array_keys($tables, 'users')) === 1);
    $this->assert(!in_array('other_only', $tables));

    $this->assert(SchemaUser::Load(1)->name === 'Abe');
  }

  // * with meekro_other first on search_path, they only see meekro_other.users,
  //   and tableList() includes tables from both schemas, with users listed once
  function test_02_other_schema_first() {
    DB::query("SET search_path TO meekro_other, public");
    MeekroORM::_orm_struct_reset();

    try {
      $this->assert(array_keys(DB::columnList('users')) === ['code', 'other_col']);

      $tables = DB::tableList();
      $this->assert(count(array_keys($tables, 'users')) === 1);
      $this->assert(in_array('other_only', $tables));

      $User = new SchemaUser();
      $User->code = 'A1';
      $User->other_col = 5;
      $User->Save();
      $this->assert(SchemaUser::Load('A1')->other_col === 5);
    } finally {
      DB::query("SET search_path TO DEFAULT");
      DB::query("DROP SCHEMA meekro_other CASCADE");
      MeekroORM::_orm_struct_reset();
    }
  }
}
