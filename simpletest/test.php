#!/usr/bin/php
<?php
class SimpleTest {
  public $db_type = 'mysql';

  public $sql_types = array(
    'mysql' => array(
      'int_primary_auto' => 'INT NOT NULL AUTO_INCREMENT PRIMARY KEY',
      'int_not_null' => 'INT NOT NULL',
    ),
    'sqlite' => array(
      'int_primary_auto' => 'INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT',
      'int_not_null' => 'INTEGER NOT NULL',
    ),
  );

  public function sql_types() {
    return $this->sql_types[$this->db_type];
  }

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

ini_set('date.timezone', 'America/Los_Angeles');

error_reporting(E_ALL | E_STRICT);
require_once __DIR__ . '/../db.class.php';
require_once __DIR__ . '/test_setup.php'; //test config values go here
// WARNING: ALL tables in the database will be dropped before the tests, including non-test related tables. 
// $db_type = 'mysql';
// DB::$user = $set_db_user;
// DB::$password = $set_password;
// DB::$dbName = $set_db;
// DB::$host = $set_host;

$db_type = 'sqlite';
DB::$dsn = 'sqlite:';

DB::get(); //connect to mysql

require_once __DIR__ . '/BasicTest.php';
require_once __DIR__ . '/WalkTest.php';
require_once __DIR__ . '/CallTest.php';
require_once __DIR__ . '/ObjectTest.php';
require_once __DIR__ . '/WhereClauseTest.php';
require_once __DIR__ . '/HookTest.php';
require_once __DIR__ . '/TransactionTest.php';
require_once __DIR__ . '/TransactionTest_55.php';

$classes_to_test = array(
  'BasicTest',
  'WalkTest',
  'CallTest',
  'WhereClauseTest',
  'ObjectTest',
  'HookTest',
  'TransactionTest',
  'TransactionTest_55',
);

$time_start = microtime_float();
foreach ($classes_to_test as $class) {
  $object = new $class();
  $object->db_type = $db_type;
  
  foreach (get_class_methods($object) as $method) {
    if (substr($method, 0, 4) != 'test') continue;
    echo "Running $class::$method..\n";
    $object->$method();
  }
}
$time_end = microtime_float();
$time = round($time_end - $time_start, 2);

echo "Completed in $time seconds\n";


?>
