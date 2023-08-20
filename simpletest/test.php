#!/usr/bin/php
<?php
class SimpleTest {
  public function assert($boolean) {
    if (! $boolean) $this->fail();
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

function runtest($type, $test) {
  require_once __DIR__ . "/{$type}/{$test}.php";
  $object = new $test();
  
  foreach (get_class_methods($object) as $method) {
    if (substr($method, 0, 4) != 'test') continue;
    echo "Running $test::$method..\n";
    $object->$method();
  }
}

ini_set('date.timezone', 'America/Los_Angeles');

error_reporting(E_ALL | E_STRICT);
$skip_db_tests = false;
$skip_orm_tests = false;
require_once __DIR__ . '/../db.class.php';
require_once __DIR__ . '/../orm.class.php';
require_once __DIR__ . '/test_setup.php'; //test config values go here
// WARNING: ALL tables in the database will be dropped before the tests, including non-test related tables. 
DB::$user = $set_db_user;
DB::$password = $set_password;
DB::$dbName = $set_db;
DB::$host = $set_host;
DB::get(); //connect to mysql

$db_tests = array(
  'BasicTest',
  'WalkTest',
  'CallTest',
  'WhereClauseTest',
  'ObjectTest',
  'HookTest',
  'TransactionTest',
  'TransactionTest_55',
);

$orm_tests = array(
  'BasicOrmTest',
);

$time_start = microtime_float();
if (!$skip_db_tests) {
  foreach ($db_tests as $test) {
    runtest('db', $test);
  }
}
if (!$skip_orm_tests) {
  foreach ($orm_tests as $test) {
    runtest('orm', $test);
  }
}
$time = round(microtime_float() - $time_start, 2);

echo "Completed in $time seconds\n";


?>
