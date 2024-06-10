<?php
class MultiDbTest extends SimpleTest {
  public $last_func;

  function skip() {
    if (! $this->db2) return "no support for multiple databases";
  }

  function __construct() {
    DB::removeHooks('pre_run');
    DB::addHook('pre_run', function($hash) {
      $this->last_func = $hash['func_name'];
    });
  }

  // * can tableList() tables in foreign database
  function test_01_init() {
    foreach (DB::tableList($this->db2) as $table) {
      DB::query("DROP TABLE %b", array($this->db2, $table));
    }
  }

  // * can switch dbs with useDB()/setDB()
  function test_02_create_table() {
    DB::useDB($this->db2);
    $this->assert($this->last_func === 'useDB');

    DB::query($this->get_sql('mini_table'));
    DB::setDB($this->db);
    $this->assert($this->last_func === 'useDB');
    $table = array($this->db2, 'accounts');
    $columns = DB::columnList($table);
  }

  // * can insert() to foreign database
  // * can update() to foreign database
  // * can replace() to foreign database
  // * can delete() to foreign database
  // * all of these correctly return affected_rows
  function test_03_insert_table() {
    $table = array($this->db2, 'accounts');
    $affected = DB::insert($table, array(
      'myname' => 'Paulie',
    ));
    $this->assert($affected === 1);

    $id = DB::queryFirstField("SELECT id FROM %b WHERE myname=%s", $table, 'Paulie');
    $this->assert($id === '1');

    $affected = DB::update($table, array('myname' => 'Nickie'), array('myname' => 'Paulie'));
    $this->assert($affected === 1);
    $id = DB::queryFirstField("SELECT id FROM %b WHERE myname=%s", $table, 'Nickie');
    $this->assert($id === '1');

    DB::replace($table, array('id' => 1, 'myname' => 'Jamie'));
    $this->assert($this->last_func === 'replace');
    $count = DB::queryFirstField("SELECT COUNT(*) FROM %b", $table);
    $this->assert($count === '1');
    $name = DB::queryFirstField("SELECT myname FROM %b WHERE id=%i", $table, 1);
    $this->assert($name == 'Jamie');

    $affected = DB::delete($table, array('myname' => 'Jamie'));
    $this->assert($affected === 1);
    $count = DB::queryFirstField("SELECT COUNT(*) FROM %b", $table);
    $this->assert($count === '0');
  }

  // * can insertIgnore() to foreign database
  // * can insertUpdate() to foreign database
  // * all of these correctly return affected_rows
  function test_04_mysql_unique_actions() {
    // pgsql doesn't support insertIgnore() and insertUpdate()
    if ($this->db_type == 'pgsql') return;

    $table = array($this->db2, 'accounts');
    $affected = DB::insert($table, array(
      'myname' => 'Sam',
    ));
    $this->assert($affected === 1);

    $affected = DB::insertIgnore($table, array(
      'id' => 2,
      'myname' => 'Jake',
    ));
    $this->assert($affected === 0);
    $count = DB::queryFirstField("SELECT COUNT(*) FROM %b", $table);
    $this->assert($count === '1');

    DB::insertUpdate($table, array('id' => 2, 'myname' => 'Steve'));
    $count = DB::queryFirstField("SELECT COUNT(*) FROM %b", $table);
    $this->assert($count === '1');
    $name = DB::queryFirstField("SELECT myname FROM %b WHERE id=%i", $table, 2);
    $this->assert($name == 'Steve');
  }



}