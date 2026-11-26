<?php
class MultiDbTest extends SimpleTest {
  function __construct() {
    $this->resetDatabase();
    DB::addHook('pre_run', function($hash) {
      $this->last_func = $hash['func_name'];
    });
  }

  function skip() {
    if (! $this->db2) return "no support for multiple databases";
  }

  // * tableList() and %b with [db, table] reach another database
  // * useDB() and setDB() switch databases
  function test_1_switch_db() {
    foreach (DB::tableList($this->db2) as $table) {
      DB::query("DROP TABLE %b", [$this->db2, $table]);
    }

    DB::useDB($this->db2);
    $this->assert($this->last_func === 'useDB');
    DB::query($this->get_sql('create_users'));
    $this->assert(DB::tableList() === ['users']);

    DB::setDB($this->db);
    $this->assert($this->last_func === 'useDB');
    $this->assert(DB::tableList($this->db2) === ['users']);
    $this->assert(count(DB::columnList([$this->db2, 'users'])) === 9);
  }

  // * insert(), update(), replace(), insertIgnore(), insertUpdate() and delete() work on [db, table],
  //   and return the affected rows
  function test_2_other_db() {
    $table = [$this->db2, 'users'];

    $this->assert(DB::insert($table, ['name' => 'Paulie']) === 1);
    $this->assert(DB::queryFirstField("SELECT user_id FROM %b WHERE name=%s", $table, 'Paulie') === '1');

    $this->assert(DB::update($table, ['name' => 'Nickie'], ['name' => 'Paulie']) === 1);
    $this->assert(DB::queryFirstField("SELECT name FROM %b WHERE user_id=1", $table) === 'Nickie');

    DB::replace($table, ['user_id' => 1, 'name' => 'Jamie']);
    $this->assert($this->last_func === 'replace');
    $this->assert(DB::queryAllLists("SELECT user_id, name FROM %b", $table) === [['1', 'Jamie']]);

    $this->assert(DB::insertIgnore($table, ['user_id' => 1, 'name' => 'Jake']) === 0);
    DB::insertUpdate($table, ['user_id' => 1, 'name' => 'Steve']);
    $this->assert(DB::queryAllLists("SELECT user_id, name FROM %b", $table) === [['1', 'Steve']]);

    $this->assert(DB::delete($table, ['name' => 'Steve']) === 1);
    $this->assert(DB::queryFirstField("SELECT COUNT(*) FROM %b", $table) === '0');
  }
}
