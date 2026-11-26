#!/usr/bin/php
<?php
// WARNING: ALL tables in the database will be dropped
// do not give the test script access to your valuable databases

class SimpleTest {
  public $db_type = 'mysql';
  public $last_func;
  public $data = null;
  public $db = null;
  public $db2 = null;
  public $fast = false;

  public function skip() { return false; }

  protected function assert($boolean) {
    if (! $boolean) $this->fail();
  }

  // run $fn, and fail unless it throws; if $message is given (a string, or a list of alternatives
  // for messages that differ between databases), the exception message must contain it.
  // returns the exception, for any further checks
  protected function assertThrows(callable $fn, $message = null) {
    try {
      $fn();
    } catch (Exception $e) {
      if (!is_null($message) && !$this->match_set($e->getMessage(), (array) $message)) {
        $this->fail(sprintf("expected exception message containing %s, got: %s",
          json_encode($message), $e->getMessage()));
      }
      return $e;
    }
    $this->fail("expected an exception, but none was thrown");
  }

  // put DB's settings back to their defaults, and remove all hooks.
  // drop every table, then create the standard tables (see statements.sql) and fill them:
  //   companies:   1 Acme, 2 Globex
  //   users:       1 Abe (Acme), 2 Bart (Acme), 3 Cleo (Globex), 4 Dana O'Neil (no employer)
  //   profiles:    Abe's (with bio and tags), Cleo's (bio and tags NULL)
  //   memberships: Abe owner at Acme, Bart intern at Acme, Cleo manager at Globex
  protected function resetDatabase() {
    // the runner only sets db_type after the constructor, which is where this usually gets called
    $this->db_type = DB::dbType();

    DB::$param_char = '%';
    DB::$named_param_seperator = '_';
    DB::$nested_transactions = false;
    DB::$logfile = null;
    foreach (['pre_run', 'post_run', 'run_success', 'run_failed'] as $hook_type) {
      DB::removeHooks($hook_type);
    }

    foreach (DB::tableList() as $table) {
      DB::query("DROP TABLE %b", $table);
    }
    foreach (['users', 'companies', 'profiles', 'memberships', 'weird'] as $table) {
      DB::query($this->get_sql("create_{$table}"));
    }

    DB::insert('companies', [['name' => 'Acme'], ['name' => 'Globex']]);

    DB::insert('users', ['name' => 'Abe', 'employer_id' => 1, 'age' => 30, 'height' => 1.8,
      'is_active' => true, 'settings' => '{"theme":"dark"}']);
    DB::insert('users', ['name' => 'Bart', 'employer_id' => 1, 'nickname' => 'Bartman', 'age' => 15,
      'height' => 1.5, 'is_active' => true]);
    DB::insert('users', ['name' => 'Cleo', 'employer_id' => 2, 'nickname' => null, 'age' => 41,
      'height' => 1.65, 'is_active' => false, 'signed_up_at' => '2020-01-02 03:04:05']);
    DB::insert('users', ['name' => "Dana O'Neil", 'nickname' => 'Dee', 'age' => 15, 'height' => 1.5]);

    DB::insert('profiles', ['user_id' => 1, 'bio' => 'Founder of Acme', 'tags' => '["admin","founder"]']);
    DB::insert('profiles', ['user_id' => 3, 'bio' => null, 'tags' => null]);

    DB::insert('memberships', [
      ['user_id' => 1, 'company_id' => 1, 'role' => 'owner'],
      ['user_id' => 2, 'company_id' => 1, 'role' => 'intern'],
      ['user_id' => 3, 'company_id' => 2, 'role' => 'manager'],
    ]);
  }

  protected function match_set($haystack, array $needles) {
    $haystack = strtolower($haystack);
    foreach ($needles as $needle) {
      $needle = strtolower($needle);
      if (substr_count($haystack, $needle)) return true;
    }
    return false;
  }

  protected function init_sqlstore() {
    $file = file_get_contents(__DIR__ . '/statements.sql');
    $lines = explode("\n", $file);

    $data = array();
    $args = array();
    $contents = array();
    foreach ($lines as $line) {
      if (substr($line, 0, 2) == '--') {
        if ($args) {
          $data[] = array_merge($args, array('contents' => trim(implode("\n", $contents))));
          $contents = array();
        }
        $args = array();

        preg_match_all('/(\S+)\s*:\s*(\S+)/', $line, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
          $args[$match[1]] = $match[2];
        }

        continue;
      }

      if ($args) {
        $contents[] = $line;
      }
    }

    if ($args) {
      $data[] = array_merge($args, array('contents' => trim(implode("\n", $contents))));
    }
    $this->data = $data;
  }

  public function get_sql($name) {
    if (is_null($this->data)) $this->init_sqlstore();

    $contents = null;
    $search = array('name' => $name, 'db' => $this->db_type);
    foreach ($this->data as $entry) {
      foreach ($search as $key => $value) {
        if (! array_key_exists($key, $entry)) continue;
        if ($entry[$key] != $value) continue 2;
      }

      $contents = $entry['contents'];
      break;
    }

    if (! $contents) {
      throw new Exception("Unable to find sql: $name");
    }

    if (preg_match_all('/{{(\w+)}}/', $contents, $matches)) {
      $matches = array_unique($matches[1]);
      foreach ($matches as $match) {
        $contents = str_replace('{{' . $match . '}}', $this->get_sql($match), $contents);
      }
    }

    return $contents;
  }

  protected function fail($msg = '') {
    echo "FAILURE! $msg\n";
    debug_print_backtrace();
    die;
  }
}

function microtime_float()
{
    list($usec, $sec) = explode(" ", microtime());
    return ((float)$usec + (float)$sec);
}

ini_set('date.timezone', 'America/Los_Angeles');

if (PHP_VERSION_ID >= 80400) {
  error_reporting(E_ALL);
} else {
  error_reporting(E_ALL | E_STRICT);
}

// require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../db.class.php';
require_once __DIR__ . '/../orm.class.php';

$contexts = array();
require_once __DIR__ . '/test_setup.php'; //test config values go here

$classes_to_test = array(
  'BasicTest',
  'ParseTest',
  'UpsertTest',
  'BadStringsTest',
  'MultiDbTest',
  'WalkTest',
  'CallTest',
  'WhereClauseTest',
  'ObjectTest',
  'HookTest',
  'TransactionTest',
  'BasicOrmTest',
  'PgsqlBoolTest',
  'PgsqlSchemaTest',
);

foreach ($contexts as $name => $fn) {
  echo "Starting context: $name ..\n";
  DB::disconnect();
  $data = $fn();
  if (!$data) $data = array();
  DB::get(); // connect

  $time_start = microtime_float();
  foreach ($classes_to_test as $class) {
    require_once sprintf('%s/%s.php', __DIR__, $class);

    $object = new $class();
    $object->db_type = DB::dbType();
    foreach ($data as $key => $value) {
      $object->$key = $value;
    }
    if (($reason = $object->skip()) && is_string($reason)) {
      echo "Skipping $class: $reason..\n";
      continue;
    }
    
    foreach (get_class_methods($object) as $method) {
      if (substr($method, 0, 4) != 'test') continue;
      echo "Running $class::$method..\n";
      $object->$method();
    }
  }
  $time_end = microtime_float();
  $time = round($time_end - $time_start, 2);

  echo "Completed in $time seconds\n\n";
}
