<?php
function new_error_callback($params) {
  global $error_callback_worked;
  
  if (substr_count($params['error'], 'You have an error in your SQL syntax')) $error_callback_worked = 1;
}

function my_debug_handler($params) {
  global $debug_callback_worked;
  if (substr_count($params['query'], 'SELECT')) $debug_callback_worked = 1;
}


class BasicTest extends SimpleTest {
  function __construct() {
    error_reporting(E_ALL);
    require_once '../db.class.php';
    DB::$user = 'libdb_user';
    DB::$password = 'sdf235sklj';
    DB::$dbName = 'libdb_test';
    DB::query("DROP DATABASE libdb_test");
    DB::query("CREATE DATABASE libdb_test");
    DB::useDB('libdb_test');
  }
  
  
  function test_1_create_table() {
    DB::query("CREATE TABLE `accounts` (
    `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY ,
    `username` VARCHAR( 255 ) NOT NULL ,
    `password` VARCHAR( 255 ) NOT NULL ,
    `age` INT NOT NULL DEFAULT '10',
    `height` DOUBLE NOT NULL DEFAULT '10.0'
    ) ENGINE = InnoDB");
  }
  
  function test_1_5_empty_table() {
    $counter = DB::queryFirstField("SELECT COUNT(*) FROM accounts");
    $this->assert($counter === strval(0));
    
    $row = DB::queryFirstRow("SELECT * FROM accounts");
    $this->assert($row === null);
    
    $field = DB::queryFirstField("SELECT * FROM accounts");
    $this->assert($field === null);
    
    $field = DB::queryOneField('nothere', "SELECT * FROM accounts");
    $this->assert($field === null);
    
    $column = DB::queryFirstColumn("SELECT * FROM accounts");
    $this->assert(is_array($column) && count($column) === 0);
    
    $column = DB::queryOneColumn('nothere', "SELECT * FROM accounts"); //TODO: is this what we want?
    $this->assert(is_array($column) && count($column) === 0);
  }
  
  function test_2_insert_row() {
    DB::insert('accounts', array(
      'username' => 'Abe',
      'password' => 'hello'
    ));
    
    $this->assert(DB::affectedRows() === 1);
    
    $counter = DB::queryFirstField("SELECT COUNT(*) FROM accounts");
    $this->assert($counter === strval(1));
  }
  
  function test_3_more_inserts() {
    DB::insert('`accounts`', array(
      'username' => 'Bart',
      'password' => 'hello',
      'age' => 15,
      'height' => 10.371
    ));
    
    DB::insert('`libdb_test`.`accounts`', array(
      'username' => 'Charlie\'s Friend',
      'password' => 'goodbye',
      'age' => 30,
      'height' => 155.23
    ));
    
    $this->assert(DB::insertId() === 3);
    
    $counter = DB::queryFirstField("SELECT COUNT(*) FROM accounts");
    $this->assert($counter === strval(3));
    
    $bart = DB::queryFirstRow("SELECT * FROM accounts WHERE age IN %li AND height IN %ld AND username IN %ls", 
      array(15, 25), array(10.371, 150.123), array('Bart', 'Barts'));
    $this->assert($bart['username'] === 'Bart');
    
    $charlie_password = DB::queryFirstField("SELECT password FROM accounts WHERE username IN %ls AND username = %s", 
      array('Charlie', 'Charlie\'s Friend'), 'Charlie\'s Friend');
    $this->assert($charlie_password === 'goodbye');
    
    $charlie_password = DB::queryOneField('password', "SELECT * FROM accounts WHERE username IN %ls AND username = %s", 
      array('Charlie', 'Charlie\'s Friend'), 'Charlie\'s Friend');
    $this->assert($charlie_password === 'goodbye');
  }
  
  function test_4_query() {
    $results = DB::query("SELECT * FROM accounts WHERE username=%s", 'Charlie\'s Friend');
    $this->assert(count($results) === 1);
    $this->assert($results[0]['age'] == 30 && $results[0]['password'] == 'goodbye');
    
    $results = DB::query("SELECT * FROM accounts WHERE username!=%s", "Charlie's Friend");
    $this->assert(count($results) === 2);
  }
  
  function test_5_error_handler() {
    global $error_callback_worked;
    
    DB::$error_handler = 'new_error_callback';
    DB::query("SELET * FROM accounts");
    $this->assert($error_callback_worked === 1);
  }
  
  function test_6_exception_catch() {
    DB::$error_handler = '';
    DB::$throw_exception_on_error = true;
    try {
      DB::query("SELET * FROM accounts");
    } catch(MeekroDBException $e) {
      $this->assert(substr_count($e->getMessage(), 'You have an error in your SQL syntax'));
      $this->assert($e->getQuery() === 'SELET * FROM accounts');
      $exception_was_caught = 1;
    }
    $this->assert($exception_was_caught === 1);
    
    try {
      DB::insert('`libdb_test`.`accounts`', array(
        'id' => 2,
        'username' => 'Another Dude\'s \'Mom"',
        'password' => 'asdfsdse',
        'age' => 35,
        'height' => 555.23
      ));
    } catch(MeekroDBException $e) {
      $this->assert(substr_count($e->getMessage(), 'Duplicate entry'));
      $exception_was_caught = 2;
    }
    $this->assert($exception_was_caught === 2);
  }
  
  function test_7_debugmode_handler() {
    global $debug_callback_worked;
    
    DB::debugMode('my_debug_handler');
    DB::query("SELECT * FROM accounts WHERE username!=%s", "Charlie's Friend");
    
    $this->assert($debug_callback_worked === 1);
    
    DB::debugMode(false);
  }

}


?>
