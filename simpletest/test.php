#!/usr/bin/php
<?php
// WARNING: ALL tables in the database will be dropped
// do not give the test script access to your valuable databases

class SimpleTest {
  public $db_type = 'mysql';
  public $data = null;
  public $db = null;
  public $db2 = null;
  public $fast = false;

  public function skip() { return false; }

  protected function assert($boolean) {
    if (! $boolean) $this->fail();
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

  protected function get_sql($name) {
    if (is_null($this->data)) $this->init_sqlstore();

    $search = array('name' => $name, 'db' => $this->db_type);
    foreach ($this->data as $entry) {
      foreach ($search as $key => $value) {
        if (! array_key_exists($key, $entry)) continue 2;
        if ($entry[$key] != $value) continue 2;
      }

      return $entry['contents'];
    }

    throw new Exception("Unable to find sql");
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

error_reporting(E_ALL | E_STRICT);
require_once __DIR__ . '/../db.class.php';

$contexts = array();
require_once __DIR__ . '/test_setup.php'; //test config values go here

$classes_to_test = array(
  'BasicTest',
  'UpsertTest',
  'BadStringsTest',
  'MultiDbTest',
  'WalkTest',
  'CallTest',
  'WhereClauseTest',
  'ObjectTest',
  'HookTest',
  'TransactionTest',
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
    $object->db_type = DB::db_type();
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
