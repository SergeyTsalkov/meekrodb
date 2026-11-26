<?php
class WalkTest extends SimpleTest {
  function __construct() {
    $this->resetDatabase();
    DB::addHook('pre_run', function($hash) {
      $this->last_func = $hash['func_name'];
    });
  }

  // all the rows left in a walk
  function walkAll(MeekroDBWalk $Walk) {
    $rows = [];
    while ($row = $Walk->next()) {
      $rows[] = $row;
    }
    return $rows;
  }

  // * queryWalk() goes through the rows one at a time
  function test_1_walk() {
    $rows = $this->walkAll(DB::queryWalk("SELECT name FROM users ORDER BY user_id"));
    $this->assert($this->last_func === 'queryWalk');
    $this->assert(array_column($rows, 'name') === ['Abe', 'Bart', 'Cleo', "Dana O'Neil"]);

    $this->assert($this->walkAll(DB::queryWalk("SELECT * FROM users WHERE user_id>100")) === []);
  }

  // * queryWalk() of a statement with no result set gives no rows, and still runs it
  function test_2_walk_insert() {
    // old mysql pdo driver chokes on this
    if ($this->db_type == 'mysql' && phpversion() < '8') return;

    $this->assert($this->walkAll(DB::queryWalk("INSERT INTO companies (name) VALUES ('Walk Co')")) === []);
    $this->assert(DB::queryFirstField("SELECT COUNT(*) FROM companies") === '3');
  }

  // * an unfinished walk is cleaned up when it goes away, so the next query works
  function test_3_walk_incomplete() {
    $Walk = DB::queryWalk("SELECT * FROM users");
    $Walk->next();
    unset($Walk);

    // if $Walk hadn't been freed, this would get an out of sync error
    DB::query("SELECT * FROM users");
  }

  // * (mysql) the rows aren't buffered, so while a walk is unfinished, other queries fail
  function test_4_walk_unbuffered() {
    // other drivers seem to buffer results whenever they want to
    if ($this->db_type != 'mysql') return;

    $Walk = DB::queryWalk("SELECT * FROM users");
    $Walk->next();
    $this->assertThrows(function() { DB::query("SELECT * FROM users"); }, ['out of sync', 'unbuffered queries']);
    $Walk->free();
  }
}
