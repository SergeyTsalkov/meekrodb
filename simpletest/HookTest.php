<?php
function hooktest_run_failed($hash) {
  HookTest::$calls[] = 'function';
  return false;
}

class HookTest extends SimpleTest {
  public static $calls = [];

  function __construct() {
    $this->resetDatabase();
    self::$calls = [];
  }

  static function static_run_failed($hash) {
    self::$calls[] = 'static';
    return false;
  }

  function method_run_failed($hash) {
    self::$calls[] = 'method';
    return false;
  }

  // * a hook can be a function name, a static method, an instance method or a closure
  // * a run_failed hook returning false stops the exception, and the query returns false
  // * run_failed hooks get the error message and the exception, with its codes
  function test_01_run_failed() {
    $hooks = [
      'hooktest_run_failed',
      ['HookTest', 'static_run_failed'],
      [$this, 'method_run_failed'],
      function($hash) { self::$calls[] = 'closure'; return false; },
    ];
    foreach ($hooks as $hook) {
      $hook_id = DB::addHook('run_failed', $hook);
      $this->assert(DB::query("SELET * FROM users") === false);
      DB::removeHook('run_failed', $hook_id);
    }
    $this->assert(self::$calls === ['function', 'static', 'method', 'closure']);

    $hash = null;
    DB::addHook('run_failed', function($h) use (&$hash) {
      $hash = $h;
      return false;
    });
    DB::query("SELET * FROM users");
    DB::removeHooks('run_failed');

    $this->assert($this->match_set($hash['error'], ['syntax']));
    $this->assert($hash['exception'] instanceof MeekroDBException);
    if ($this->db_type == 'mysql') {
      $this->assert($hash['exception']->getStrCode() === '42000');
      $this->assert($hash['exception']->getCode() === 1064);
    }
  }

  // * without a hook, a failed query throws a MeekroDBException that has the query and params
  // * lastQuery() is set even when the query fails
  function test_02_exceptions() {
    $e = $this->assertThrows(function() {
      DB::query("SELET * FROM users WHERE name=%s", 'Abe');
    }, 'syntax');
    $this->assert($e instanceof MeekroDBException);
    $this->assert($e->getQuery() === 'SELET * FROM users WHERE name=?');
    $this->assert($e->getParams() === ['Abe']);
    $this->assert(DB::lastQuery() === 'SELET * FROM users WHERE name=?');

    $this->assertThrows(function() {
      DB::insert('users', ['user_id' => 1, 'name' => 'Dup']);
    }, ['Duplicate entry', 'UNIQUE constraint', 'duplicate key']);
  }

  // * run_success hooks get the query, params, func_name, and the rows returned or affected
  // * post_run hooks run after every query, successful or not, and also get the error and exception
  function test_03_run_success_and_post_run() {
    $success = $post = [];
    DB::addHook('run_success', function($hash) use (&$success) { $success[] = $hash; });
    DB::addHook('post_run', function($hash) use (&$post) { $post[] = $hash; });
    DB::addHook('run_failed', function() { return false; });

    DB::query("SELECT * FROM users WHERE age=%i", 15);
    DB::query("SELEC * FROM users WHERE age=%i", 15);
    DB::update('users', ['nickname' => 'Cle'], ['user_id' => 3]);

    DB::removeHooks('run_success');
    DB::removeHooks('post_run');
    DB::removeHooks('run_failed');

    $this->assert(count($success) === 2);
    $this->assert($success[0]['query'] === 'SELECT * FROM users WHERE age=?');
    $this->assert($success[0]['params'] === [15]);
    $this->assert($success[0]['func_name'] === 'query');
    $this->assert($success[0]['rows'] === 2);
    $this->assert($success[1]['func_name'] === 'update');
    $this->assert($success[1]['affected'] === 1);

    $this->assert(count($post) === 3);
    $this->assert($post[0]['error'] === null && $post[0]['exception'] === null);
    $this->assert($post[1]['query'] === 'SELEC * FROM users WHERE age=?');
    $this->assert($post[1]['params'] === [15]);
    $this->assert($this->match_set($post[1]['error'], ['syntax']));
    $this->assert($post[1]['exception']->getQuery() === $post[1]['query']);
    $this->assert($post[1]['exception']->getParams() === [15]);

    DB::update('users', ['nickname' => null], ['user_id' => 3]);
  }

  // * pre_run hooks run in order, and can rewrite the query (by returning a string),
  //   or the query and params (by returning [query, params])
  // * removeHook() removes just one hook, removeHooks() all of a type
  // * a pre_run hook returning anything else, or an unknown hook type, throws
  function test_04_pre_run() {
    $ran = false;
    DB::addHook('pre_run', function($hash) {
      return [str_replace('SLCT', 'SELET', $hash['query']), [41]];
    });
    DB::addHook('pre_run', function($hash) {
      return str_replace('SELET', 'SELECT', $hash['query']);
    });
    $hook_id = DB::addHook('pre_run', function($hash) use (&$ran) {
      $ran = true;
    });

    $this->assert(DB::queryFirstColumn("SLCT name FROM users WHERE age=%i", 15) === ['Cleo']);
    $this->assert($ran);

    $ran = false;
    DB::removeHook('pre_run', $hook_id);
    $this->assert(DB::queryFirstColumn("SLCT name FROM users WHERE age=%i", 15) === ['Cleo']);
    $this->assert(! $ran);

    DB::removeHooks('pre_run');
    DB::addHook('pre_run', function() { return 5; });
    $this->assertThrows(function() { DB::query("SELECT * FROM users"); }, 'pre_run hook must return');
    DB::removeHooks('pre_run');

    $this->assertThrows(function() { DB::addHook('nonsense', function() {}); }, 'Hook type nonsense is not recognized');
  }

  // * logfile gets each query, with its params, runtime, and the rows returned or affected, or the error
  function test_05_logfile() {
    $log = fopen('php://memory', 'w+');
    DB::$logfile = $log;
    DB::addHook('run_failed', function() { return false; });
    try {
      DB::query("SELECT name FROM users WHERE age=%i", 41);
      DB::update('users', ['nickname' => 'Cle'], ['user_id' => 3]);
      DB::query("SELEC 1");
    } finally {
      DB::$logfile = null;
      DB::removeHooks('run_failed');
    }
    DB::update('users', ['nickname' => null], ['user_id' => 3]);

    rewind($log);
    $text = stream_get_contents($log);
    $this->assert(substr_count($text, 'QUERY: SELECT name FROM users WHERE age=?') === 1);
    $this->assert(substr_count($text, 'PARAMS: 41') === 1);
    $this->assert(substr_count($text, 'RETURNED ROWS: 1') === 1);
    $this->assert(substr_count($text, 'AFFECTED ROWS: 1') === 1);
    $this->assert(substr_count($text, "\nERROR: ") === 1); // pgsql's own message contains "ERROR:" too
    $this->assert(substr_count($text, 'RUNTIME: ') === 3);
  }

  // * failing to connect is reported to run_failed hooks
  function test_06_cant_connect() {
    if ($this->db_type == 'sqlite') return; // no logins

    $hash = null;
    $old_user = DB::$user;
    DB::$user = 'asdf';
    DB::disconnect();

    DB::addHook('run_failed', function($h) use (&$hash) {
      $hash = $h;
      return false;
    });
    try {
      DB::query("SELECT * FROM users");
    } finally {
      DB::removeHooks('run_failed');
      DB::$user = $old_user;
      DB::disconnect();
    }
    $this->assert($this->match_set($hash['error'], ['access denied', 'connection to server']));
  }
}
