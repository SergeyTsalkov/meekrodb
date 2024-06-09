<?php

function my_error_handler($hash) {
  global $error_callback_worked;

  if (substr_count($hash['error'], 'syntax')) {
    $error_callback_worked = 1;
  }
  return false;
}

function my_success_handler($hash) {
  global $debug_callback_worked;
  if (substr_count($hash['query'], 'SELECT')) $debug_callback_worked = 1;
  return false;
}


class HookTest extends SimpleTest {
  static function static_error_callback($hash) {
    global $static_error_callback_worked;

    if (substr_count($hash['error'], 'syntax')) {
      $static_error_callback_worked = 1;
    }
    return false;
  }

  function nonstatic_error_callback($hash) {
    global $nonstatic_error_callback_worked;

    if (substr_count($hash['error'], 'syntax')) {
      $nonstatic_error_callback_worked = 1;
    }
    return false;
  }

  function test_1_error_handler() {
    global $error_callback_worked, $static_error_callback_worked, $nonstatic_error_callback_worked;
    
    DB::addHook('run_failed', 'my_error_handler');
    DB::query("SELET * FROM accounts");
    $this->assert($error_callback_worked === 1);
    DB::removeHooks('run_failed');

    DB::addHook('run_failed', array('HookTest', 'static_error_callback'));
    DB::query("SELET * FROM accounts");
    $this->assert($static_error_callback_worked === 1);
    DB::removeHooks('run_failed');

    DB::addHook('run_failed', array($this, 'nonstatic_error_callback'));
    DB::query("SELET * FROM accounts");
    $this->assert($nonstatic_error_callback_worked === 1);
    DB::removeHooks('run_failed');
  }
  
  function test_2_exception_catch() {
    try {
      DB::query("SELET * FROM accounts");
    } catch(MeekroDBException $e) {
      $this->assert(substr_count($e->getMessage(), 'syntax'));
      $this->assert($e->getQuery() === 'SELET * FROM accounts');
      $exception_was_caught = 1;
    }
    $this->assert($exception_was_caught === 1);
    $this->assert(DB::lastQuery() === 'SELET * FROM accounts');
    
    try {
      DB::insert('accounts', array(
        'id' => 2,
        'username' => 'Another Dude\'s \'Mom"',
        'password' => 'asdfsdse',
        'height' => 555.23
      ));
    } catch(MeekroDBException $e) {
      $this->assert($this->match_set($e->getMessage(), array('Duplicate entry', 'UNIQUE constraint')));
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
    
    $error_handler = function($hash) {
      global $anonymous_error_callback_worked;
      if (substr_count($hash['error'], 'syntax')) {
        $anonymous_error_callback_worked = 1;
      }
      return false;
    };
    DB::addHook('run_failed', $error_handler);
    $result = DB::query("SELET * FROM accounts");
    $this->assert($anonymous_error_callback_worked === 1);
    $this->assert($result === false);
    DB::removeHooks('run_failed');
  }

  function test_5_post_run_success() {
    $callback_worked = false;

    $fn = function($hash) use (&$callback_worked) {
      if (!$hash['error'] && !$hash['exception'] && $hash['rows'] === 7) {
        $callback_worked = true;
      }
    };

    DB::addHook('post_run', $fn);
    DB::query("SELECT * FROM accounts WHERE username!=%s", "Charlie's Friend");
    $this->assert($callback_worked);
    DB::removeHooks('post_run');
  }

  function test_6_post_run_failed() {
    $callback_worked = false;

    $fn = function($hash) use (&$callback_worked) {
      $expected_query = "SELEC * FROM accounts WHERE username!=?";
      $expected_param = "Charlie's Friend";
      $expected_error = "syntax";

      if (!$hash['error'] || !$hash['exception']) return;
      if ($hash['exception']->getQuery() != $expected_query) return;
      if ($hash['exception']->getParams()[0] != $expected_param) return;
      if ($hash['query'] != $expected_query) return;
      if ($hash['params'][0] != $expected_param) return;
      if (!substr_count($hash['error'], $expected_error)) return;

      $callback_worked = true;
    };

    DB::addHook('post_run', $fn);
    DB::addHook('run_failed', function() { return false; }); // disable exception throwing
    DB::query("SELEC * FROM accounts WHERE username!=%s", "Charlie's Friend");
    $this->assert($callback_worked);
    DB::removeHooks('post_run');
    DB::removeHooks('run_failed');
  }

  function test_7_pre_run() {
    $callback_worked = false;

    $fn = function($args) {
      $query = str_replace('SLCT', 'SELET', $args['query']);
      return array($query, $args['params']);
    };
    $fn2 = function($args) { 
      $query = str_replace('SELET', 'SELECT', $args['query']);
      return $query;
    };
    $fn3 = function($args) use (&$callback_worked) { 
      $callback_worked = true;
    };

    DB::addHook('pre_run', $fn);
    DB::addHook('pre_run', $fn2);
    $last_hook = DB::addHook('pre_run', $fn3);
    $results = DB::query("SLCT * FROM accounts WHERE username!=%s", "Charlie's Friend");
    $this->assert(count($results) == 7);
    $this->assert($callback_worked);

    $callback_worked = false;
    DB::removeHook('pre_run', $last_hook);
    $results = DB::query("SLCT * FROM accounts WHERE username!=%s", "Charlie's Friend");
    $this->assert(count($results) == 7);
    $this->assert(!$callback_worked);
    
    DB::removeHooks('pre_run');
  }

  function test_9_enough_args() {
    $error_worked = false;

    try {
      DB::query("SELECT * FROM accounts WHERE id=%i AND username=%s", 1);
    } catch (MeekroDBException $e) {
      if ($e->getMessage() == 'Expected 2 args, but only got 1!') {
        $error_worked = true;
      }
    }

    $this->assert($error_worked);
  }

  function test_10_named_keys_present() {
    $error_worked = false;

    try {
      DB::query("SELECT * FROM accounts WHERE id=%i_id AND username=%s_username", array('username' => 'asdf'));
    } catch (MeekroDBException $e) {
      if ($e->getMessage() == "Couldn't find named arg id!") {
        $error_worked = true;
      }
    }

    $this->assert($error_worked);
  }

  function test_11_expect_array() {
    $error_worked = false;

    try {
      DB::query("SELECT * FROM accounts WHERE id IN %li", 5);
    } catch (MeekroDBException $e) {
      if ($e->getMessage() == "Expected an array for arg 0 but didn't get one!") {
        $error_worked = true;
      }
    }

    $this->assert($error_worked);
  }

  function test_12_named_keys_without_array() {
    $error_worked = false;

    try {
      DB::query("SELECT * FROM accounts WHERE id=%i_named", 1);
    } catch (MeekroDBException $e) {
      if ($e->getMessage() == "If you use named args, you must pass an assoc array of args!") {
        $error_worked = true;
      }
    }

    $this->assert($error_worked);
  }

  function test_13_mix_named_numbered_args() {
    $error_worked = false;

    try {
      DB::query("SELECT * FROM accounts WHERE id=%i_named AND username=%s", array('named' => 1));
    } catch (MeekroDBException $e) {
      if ($e->getMessage() == "You can't mix named and numbered args!") {
        $error_worked = true;
      }
    }

    $this->assert($error_worked);
  }

  function test_14_arrays_not_empty() {
    $error_worked = false;

    try {
      DB::query("SELECT * FROM accounts WHERE id IN %li", array());
    } catch (MeekroDBException $e) {
      if ($e->getMessage() == "Arg 0 array can't be empty!") {
        $error_worked = true;
      }
    }

    $this->assert($error_worked);
  }

  function test_15_named_array_not_empty() {
    $error_worked = false;

    try {
      DB::query("SELECT * FROM accounts WHERE id IN %li_ids", array('ids' => array()));
    } catch (MeekroDBException $e) {
      if ($e->getMessage() == "Arg ids array can't be empty!") {
        $error_worked = true;
      }
    }

    $this->assert($error_worked);
  }

}
