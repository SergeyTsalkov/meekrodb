<?php

function my_error_handler($params) {
  global $error_callback_worked;
  if (substr_count($params['error'], 'You have an error in your SQL syntax')) $error_callback_worked = 1;
  return false;
}

function my_success_handler($params) {
  global $debug_callback_worked;
  if (substr_count($params['query'], 'SELECT')) $debug_callback_worked = 1;
  return false;
}


class ErrorTest extends SimpleTest {
  static function static_error_callback($params) {
    global $static_error_callback_worked;
    if (substr_count($params['error'], 'You have an error in your SQL syntax')) $static_error_callback_worked = 1;
    return false;
  }

  function nonstatic_error_callback($params) {
    global $nonstatic_error_callback_worked;
    if (substr_count($params['error'], 'You have an error in your SQL syntax')) $nonstatic_error_callback_worked = 1;
    return false;
  }

  function test_1_error_handler() {
    global $error_callback_worked, $static_error_callback_worked, $nonstatic_error_callback_worked;
    
    DB::addHook('run_failed', 'my_error_handler');
    DB::query("SELET * FROM accounts");
    $this->assert($error_callback_worked === 1);
    
    DB::removeHooks('run_failed');
    DB::addHook('run_failed', array('ErrorTest', 'static_error_callback'));
    DB::query("SELET * FROM accounts");
    $this->assert($static_error_callback_worked === 1);
    
    DB::removeHooks('run_failed');
    DB::addHook('run_failed', array($this, 'nonstatic_error_callback'));
    DB::query("SELET * FROM accounts");
    $this->assert($nonstatic_error_callback_worked === 1);
    DB::removeHooks('run_failed');
  }
  
  function test_2_exception_catch() {
    $dbname = DB::$dbName;
    try {
      DB::query("SELET * FROM accounts");
    } catch(MeekroDBException $e) {
      $this->assert(substr_count($e->getMessage(), 'You have an error in your SQL syntax'));
      $this->assert($e->getQuery() === 'SELET * FROM accounts');
      $exception_was_caught = 1;
    }
    $this->assert($exception_was_caught === 1);
    
    try {
      DB::insert("`$dbname`.`accounts`", array(
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
  
  function test_3_success_handler() {
    global $debug_callback_worked;
    
    DB::addHook('run_success', 'my_success_handler');
    DB::query("SELECT * FROM accounts WHERE username!=%s", "Charlie's Friend");
    $this->assert($debug_callback_worked === 1);
    DB::removeHooks('run_success');
  }

  function test_4_error_handler() {
    global $anonymous_error_callback_worked;
    
    $error_handler = function($params) {
      global $anonymous_error_callback_worked;
      if (substr_count($params['error'], 'You have an error in your SQL syntax')) {
        $anonymous_error_callback_worked = 1;
      }
      return false;
    };
    DB::addHook('run_failed', $error_handler);
    DB::query("SELET * FROM accounts");
    $this->assert($anonymous_error_callback_worked === 1);
    DB::removeHooks('run_failed');
  }

}

?>
