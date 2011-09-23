<?php
class BasicTest extends SimpleTest {
  function __construct() {
    foreach (DB::tableList() as $table) {
      DB::query("DROP TABLE $table");
    }
  }
  
  
  function test_1_create_table() {
    DB::query("CREATE TABLE `accounts` (
    `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY ,
    `username` VARCHAR( 255 ) NOT NULL ,
    `password` VARCHAR( 255 ) NOT NULL ,
    `age` INT NOT NULL DEFAULT '10',
    `height` DOUBLE NOT NULL DEFAULT '10.0',
    `favorite_word` VARCHAR( 255 ) NULL DEFAULT 'hi'
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
    $dbname = DB::$dbName;
    DB::insert("`$dbname`.`accounts`", array(
      'username' => 'Charlie\'s Friend',
      'password' => 'goodbye',
      'age' => 30,
      'height' => 155.23,
      'favorite_word' => null,
    ));
    
    $this->assert(DB::insertId() === 3);
    
    $counter = DB::queryFirstField("SELECT COUNT(*) FROM accounts");
    $this->assert($counter === strval(3));
    
    $password = DB::queryFirstField("SELECT password FROM accounts WHERE favorite_word IS NULL");
    $this->assert($password === 'goodbye');
    
    DB::$param_char = '###';
    $bart = DB::queryFirstRow("SELECT * FROM accounts WHERE age IN ###li AND height IN ###ld AND username IN ###ls", 
      array(15, 25), array(10.371, 150.123), array('Bart', 'Barts'));
    $this->assert($bart['username'] === 'Bart');
    DB::$param_char = '%';
    
    $charlie_password = DB::queryFirstField("SELECT password FROM accounts WHERE username IN %ls AND username = %s", 
      array('Charlie', 'Charlie\'s Friend'), 'Charlie\'s Friend');
    $this->assert($charlie_password === 'goodbye');
    
    $charlie_password = DB::queryOneField('password', "SELECT * FROM accounts WHERE username IN %ls AND username = %s", 
      array('Charlie', 'Charlie\'s Friend'), 'Charlie\'s Friend');
    $this->assert($charlie_password === 'goodbye');
    
    $passwords = DB::queryFirstColumn("SELECT password FROM accounts WHERE username=%s", 'Bart');
    $this->assert(count($passwords) === 1);
    $this->assert($passwords[0] === 'hello');
    
    $username = $password = $age = null;
    list($age, $username, $password) = DB::queryOneList("SELECT age,username,password FROM accounts WHERE username=%s", 'Bart');
    $this->assert($username === 'Bart');
    $this->assert($password === 'hello');
    $this->assert($age == 15);
    
    $mysqli_result = DB::queryRaw("SELECT * FROM accounts WHERE favorite_word IS NULL");
    $this->assert($mysqli_result instanceof MySQLi_Result);
    $row = $mysqli_result->fetch_assoc();
    $this->assert($row['password'] === 'goodbye');
    $this->assert($mysqli_result->fetch_assoc() === null);
  }
  
  function test_4_query() {
    $results = DB::query("SELECT * FROM accounts WHERE username=%s", 'Charlie\'s Friend');
    $this->assert(count($results) === 1);
    $this->assert($results[0]['age'] == 30 && $results[0]['password'] == 'goodbye');
    
    $results = DB::query("SELECT * FROM accounts WHERE username!=%s", "Charlie's Friend");
    $this->assert(count($results) === 2);
    
    $columnlist = DB::columnList('accounts');
    $this->assert(count($columnlist) === 6);
    $this->assert($columnlist[0] === 'id');
    $this->assert($columnlist[4] === 'height');
    
    $tablelist = DB::tableList();
    $this->assert(count($tablelist) === 1);
    $this->assert($tablelist[0] === 'accounts');
    
    $tablelist = null;
    $tablelist = DB::tableList(DB::$dbName);
    $this->assert(count($tablelist) === 1);
    $this->assert($tablelist[0] === 'accounts');
  }
  
  function test_4_1_query() {
    DB::insert('accounts', array(
      'username' => 'newguy',
      'password' => DB::sqleval("REPEAT('blah', %i)", '3'),
      'age' => DB::sqleval('171+1'),
      'height' => 111.15
    ));
    
    $row = DB::queryOneRow("SELECT * FROM accounts WHERE password=%s", 'blahblahblah');
    $this->assert($row['username'] === 'newguy');
    $this->assert($row['age'] === '172');
    
    DB::update('accounts', array(
      'password' => DB::sqleval("REPEAT('blah', %i)", 4),
      'favorite_word' => null,
      ), 'username=%s', 'newguy');
    
    $row = null;
    $row = DB::queryOneRow("SELECT * FROM accounts WHERE username=%s", 'newguy');
    $this->assert($row['password'] === 'blahblahblahblah');
    $this->assert($row['favorite_word'] === null);
    
    DB::query("DELETE FROM accounts WHERE password=%s", 'blahblahblahblah');
    $this->assert(DB::affectedRows() === 1);
  }
  
  function test_4_2_delete() {
    DB::insert('accounts', array(
      'username' => 'gonesoon',
      'password' => 'something',
      'age' => 61,
      'height' => 199.194
    ));
    
    $ct = DB::queryFirstField("SELECT COUNT(*) FROM accounts WHERE username=%s AND height=%d", 'gonesoon', 199.194);
    $this->assert(intval($ct) === 1);
    
    DB::delete('accounts', 'username=%s AND age=%i AND height=%d', 'gonesoon', '61', '199.194');
    $this->assert(DB::affectedRows() === 1);
    
    $ct = DB::queryFirstField("SELECT COUNT(*) FROM accounts WHERE username=%s AND height=%d", 'gonesoon', '199.194');
    $this->assert(intval($ct) === 0);
  }
  
  function test_4_3_insertmany() {
    $ins[] = array(
      'username' => '1ofmany',
      'password' => 'something',
      'age' => 23,
      'height' => 190.194
    );
    $ins[] = array(
      'password' => 'somethingelse',
      'username' => '2ofmany',
      'age' => 25,
      'height' => 190.194
    );
    
    DB::insert('accounts', $ins);
    $this->assert(DB::affectedRows() === 2);
    
    $rows = DB::query("SELECT * FROM accounts WHERE height=%d ORDER BY age ASC", 190.194);
    $this->assert(count($rows) === 2);
    $this->assert($rows[0]['username'] === '1ofmany');
    $this->assert($rows[0]['age'] === '23');
    $this->assert($rows[1]['age'] === '25');
    $this->assert($rows[1]['password'] === 'somethingelse');
    $this->assert($rows[1]['username'] === '2ofmany');
    
  }
  
  
  
  function test_5_insert_blobs() {
    DB::query("CREATE TABLE `storedata` (
      `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY ,
      `picture` BLOB
    ) ENGINE = InnoDB");
    
    
    $smile = file_get_contents('smile1.jpg');
    DB::insert('storedata', array(
      'picture' => $smile,
    ));
    DB::query("INSERT INTO storedata (picture) VALUES (%s)", $smile);
    DB::query("INSERT INTO storedata (picture) VALUES (?)", 's', $smile);
    
    $getsmile = DB::queryFirstField("SELECT picture FROM storedata WHERE id=1");
    $getsmile2 = DB::queryFirstField("SELECT picture FROM storedata WHERE id=2");
    $getsmile3 = DB::queryFirstField("SELECT picture FROM storedata WHERE id=3");
    $this->assert($smile === $getsmile);
    $this->assert($smile === $getsmile2);
    $this->assert($smile === $getsmile3);
  }

}


?>
