<?
class BasicTest extends SimpleTest {
  function __construct() {
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
  }
  
  function test_4_query() {
    $results = DB::query("SELECT * FROM accounts WHERE username=%s", 'Charlie\'s Friend');
    $this->assert(count($results) === 1);
    $this->assert($results[0]['age'] == 30 && $results[0]['password'] == 'goodbye');
    
    $results = DB::query("SELECT * FROM accounts WHERE username!=%s", "Charlie's Friend");
    $this->assert(count($results) === 2);
  }

}


?>
