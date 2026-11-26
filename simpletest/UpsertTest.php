<?php
class UpsertTest extends SimpleTest {
  function __construct() {
    $this->resetDatabase();
    DB::addHook('pre_run', function($hash) {
      $this->last_func = $hash['func_name'];
    });
  }

  // * insertIgnore() skips a row whose primary key already exists, and inserts one that doesn't
  // * pgsql doesn't support it, and says so
  function test_01_insert_ignore() {
    if (! DB::supports('insert_ignore')) {
      $this->assertThrows(function() {
        DB::insertIgnore('users', ['user_id' => 1, 'name' => 'Dup']);
      }, 'does not support insertIgnore()');
      return;
    }

    $affected = DB::insertIgnore('users', ['user_id' => 1, 'name' => 'Dup']);
    $this->assert($this->last_func === 'insertIgnore');
    $this->assert($affected === 0);
    $this->assert(DB::queryFirstField("SELECT name FROM users WHERE user_id=1") === 'Abe');

    $affected = DB::insertIgnore('users', ['user_id' => 10, 'name' => 'New']);
    $this->assert($affected === 1);
    DB::delete('users', ['user_id' => 10]);
  }

  // * insertUpdate() inserts, or for a duplicate primary key runs the update part instead, which can be
  //   a string with its own args, a hash, or left out (to update with the insert data)
  // * several rows at once, which then need an update part
  // * pgsql doesn't support it, and says so
  function test_02_insert_update() {
    if (! DB::supports('insert_update')) {
      $this->assertThrows(function() {
        DB::insertUpdate('users', ['user_id' => 1, 'name' => 'Dup']);
      }, 'does not support insertUpdate()');
      return;
    }

    $user = function($id) { return DB::queryFirstRow("SELECT name, age FROM users WHERE user_id=%i", $id); };

    DB::insertUpdate('users', ['user_id' => 2, 'name' => 'Ignored', 'age' => 99], 'age = age + %i', 1);
    $this->assert($this->last_func === 'insertUpdate');
    $this->assert($user(2) === ['name' => 'Bart', 'age' => '16']);

    DB::insertUpdate('users', ['user_id' => 2, 'name' => 'Bartholomew', 'age' => 20]);
    $this->assert($user(2) === ['name' => 'Bartholomew', 'age' => '20']);

    DB::insertUpdate('users', ['user_id' => 2, 'name' => 'Ignored', 'age' => 99], ['age' => 74]);
    $this->assert($user(2) === ['name' => 'Bartholomew', 'age' => '74']);

    DB::insertUpdate('users', [
      ['user_id' => 1, 'name' => 'Ignored'],
      ['user_id' => 10, 'name' => 'New'],
    ], ['age' => 914]);
    $this->assert($user(1) === ['name' => 'Abe', 'age' => '914']);
    $this->assert($user(10) === ['name' => 'New', 'age' => '0']);

    $this->assertThrows(function() {
      DB::insertUpdate('users', [['user_id' => 1, 'name' => 'A'], ['user_id' => 2, 'name' => 'B']]);
    }, "you didn't specify the update component");
  }
}
