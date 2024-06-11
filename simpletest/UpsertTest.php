<?php
class UpsertTest extends SimpleTest {
  function __construct() {
    DB::removeHooks('pre_run');
    DB::addHook('pre_run', function($hash) {
      $this->last_func = $hash['func_name'];
    });
  }

  function skip() {
    if ($this->db_type == 'pgsql') return "pgsql does not support upserts";
  }

  function test_1_insert_ignore() {
    $affected_rows = DB::insertIgnore('accounts', array(
      'id' => 1, //duplicate primary key
      'username' => 'gonesoon',
      'password' => 'something',
      'age' => 61,
      'height' => 199.194
    ));
    $this->assert($this->last_func === 'insertIgnore');
    $this->assert($affected_rows === 0);
  }

  function test_2_insert_update() {
    DB::insertUpdate('accounts', array(
      'id' => 2, //duplicate primary key
      'username' => 'gonesoon',
      'password' => 'something',
      'age' => 61,
      'height' => 199.194
    ), 'age = age + %i', 1);
    
    $result = DB::query("SELECT * FROM accounts WHERE age = %i", 16);
    $this->assert(count($result) === 1);
    $this->assert($result[0]['height'] === '10.371');
    
    DB::insertUpdate('accounts', array(
      'id' => 2, //duplicate primary key
      'username' => 'blahblahdude',
      'age' => 233,
      'height' => 199.194
    ));
    
    $result = DB::query("SELECT * FROM accounts WHERE `age` = %i", 233);
    $this->assert(count($result) === 1);
    $this->assert($result[0]['height'] === '199.194');
    $this->assert($result[0]['username'] === 'blahblahdude');
    
    DB::insertUpdate('accounts', array(
      'id' => 2, //duplicate primary key
      'username' => 'gonesoon',
      'password' => 'something',
      'age' => 61,
      'height' => 199.194
    ), array(
      'age' => 74,
    ));
    
    $result = DB::query("SELECT * FROM accounts WHERE `age` = %i", 74);
    $this->assert(count($result) === 1);
    $this->assert($result[0]['height'] === '199.194');
    $this->assert($result[0]['username'] === 'blahblahdude');
    
    $multiples[] = array(
      'id' => 3, //duplicate primary key
      'username' => 'gonesoon',
      'password' => 'something',
      'age' => 61,
      'height' => 199.194
    );
    $multiples[] = array(
      'id' => 1, //duplicate primary key
      'username' => 'gonesoon',
      'password' => 'something',
      'age' => 61,
      'height' => 199.194
    );
    
    DB::insertUpdate('accounts', $multiples, array('age' => 914));
    $this->assert($this->last_func === 'insertUpdate');
    
    $result = DB::query("SELECT * FROM accounts WHERE `age`=914 ORDER BY id ASC");
    $this->assert(count($result) === 2);
    $this->assert($result[0]['username'] === 'Abe');
    $this->assert($result[1]['username'] === 'Charlie\'s Friend');
    
    $affected_rows = DB::query("UPDATE accounts SET `age`=15, username='Bart' WHERE `age`=%i", 74);
    $this->assert($affected_rows === 1);
    $this->assert(DB::affectedRows() === 1);
  }
}