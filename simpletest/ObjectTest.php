<?php
class ObjectTest extends SimpleTest {
  public $mdb;
  
  function __construct() {
    $this->mdb = new MeekroDB();
    
    foreach ($this->mdb->tableList() as $table) {
      $this->mdb->query("DROP TABLE %b", $table);
    }
  }
  
  
  function test_1_create_table() {
    $this->mdb->query($this->get_sql('create_accounts'));
    $this->mdb->query($this->get_sql('create_profile'));
    $this->mdb->query($this->get_sql('create_faketable'));
  }
  
  function test_1_5_empty_table() {
    $counter = $this->mdb->queryFirstField("SELECT COUNT(*) FROM accounts");
    $this->assert($counter === strval(0));
    $this->assert($this->mdb->lastQuery() === 'SELECT COUNT(*) FROM accounts');
    
    $row = $this->mdb->queryFirstRow("SELECT * FROM accounts");
    $this->assert($row === null);
    
    $field = $this->mdb->queryFirstField("SELECT * FROM accounts");
    $this->assert($field === null);
    
    $field = $this->mdb->queryOneField('nothere', "SELECT * FROM accounts");
    $this->assert($field === null);
    
    $column = $this->mdb->queryFirstColumn("SELECT * FROM accounts");
    $this->assert(is_array($column) && count($column) === 0);
    
    $column = $this->mdb->queryOneColumn('nothere', "SELECT * FROM accounts");
    $this->assert(is_array($column) && count($column) === 0);
  }
  
  function test_2_insert_row() {
    $affected_rows = $this->mdb->insert('accounts', array(
      'username' => 'Abe',
      'password' => 'hello'
    ));
    
    $this->assert($affected_rows === 1);
    $this->assert($this->mdb->affectedRows() === 1);
    
    $counter = $this->mdb->queryFirstField("SELECT COUNT(*) FROM accounts");
    $this->assert($counter === strval(1));
  }
  
  function test_3_more_inserts() {
    $this->mdb->insert('accounts', array(
      'username' => 'Bart',
      'password' => 'hello',
      'user.age' => 15,
      'height' => 10.371
    ));

    $this->mdb->insert('accounts', array(
      'username' => 'Charlie\'s Friend',
      'password' => 'goodbye',
      'user.age' => 30,
      'height' => 155.23,
      'favorite_word' => null,
    ));

    $this->assert($this->mdb->insertId() == 3);
    $counter = $this->mdb->queryFirstField("SELECT COUNT(*) FROM accounts");
    $this->assert($counter === strval(3));
    
    $this->mdb->insert('accounts', array(
      'username' => 'Deer',
      'password' => '',
      'user.age' => 15,
      'height' => 10.371
    ));

    $username = $this->mdb->queryFirstField("SELECT username FROM accounts WHERE password=%s0", null);
    $this->assert($username === 'Deer');

    $password = $this->mdb->queryFirstField("SELECT password FROM accounts WHERE favorite_word IS NULL");
    $this->assert($password === 'goodbye');
    
    try {
      $this->mdb->insertUpdate('accounts', array(
        'id' => 3,
        'favorite_word' => null,
      ));
    } catch (MeekroDBException $e) {
      if (substr_count($e->getMessage(), 'does not support')) {
        echo "Safe error, skipping test: " . $e->getMessage() . "\n";
      } else {
        throw $e;
      }
    }
    
    $this->mdb->param_char = '###';
    $bart = $this->mdb->queryFirstRow("SELECT * FROM accounts WHERE ###c IN ###li AND height IN ###ld AND username IN ###ls", 
      'user.age', array(15, 25), array(10.371, 150.123), array('Bart', 'Barts'));
    $this->assert($bart['username'] === 'Bart');
    $this->mdb->insert('accounts', array('username' => 'f_u'));
    $this->mdb->query("DELETE FROM accounts WHERE username=###s", 'f_u');
    $this->mdb->param_char = '%';
    
    $charlie_password = $this->mdb->queryFirstField("SELECT password FROM accounts WHERE username IN %ls AND username = %s", 
      array('Charlie', 'Charlie\'s Friend'), 'Charlie\'s Friend');
    $this->assert($charlie_password === 'goodbye');
    
    $charlie_password = $this->mdb->queryOneField('password', "SELECT * FROM accounts WHERE username IN %ls AND username = %s", 
      array('Charlie', 'Charlie\'s Friend'), 'Charlie\'s Friend');
    $this->assert($charlie_password === 'goodbye');
    
    $passwords = $this->mdb->queryFirstColumn("SELECT password FROM accounts WHERE username=%s", 'Bart');
    $this->assert(count($passwords) === 1);
    $this->assert($passwords[0] === 'hello');
    
    $username = $password = $age = null;
    list($age, $username, $password) = $this->mdb->queryOneList("SELECT %c,username,password FROM accounts WHERE username=%s", 'user.age', 'Bart');
    $this->assert($username === 'Bart');
    $this->assert($password === 'hello');
    $this->assert($age == 15);
    
    $statement = $this->mdb->queryRaw("SELECT * FROM accounts WHERE favorite_word IS NULL");
    $this->assert($statement instanceof PDOStatement);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    $row2 = $statement->fetch(PDO::FETCH_ASSOC);
    $this->assert($row['password'] === 'goodbye');
    $this->assert($row2 === false);
  }
  
  function test_4_query() {
    $affected_rows = $this->mdb->update('accounts', array(
      'birthday' => new DateTime('10 September 2000 13:13:13')
    ), 'username=%s', 'Charlie\'s Friend');
    
    $results = $this->mdb->query("SELECT * FROM accounts WHERE username=%s AND birthday IN %lt", 'Charlie\'s Friend', array('September 10 2000 13:13:13'));
    $this->assert($affected_rows === 1);
    $this->assert(count($results) === 1);
    $this->assert($results[0]['user.age'] === '30' && $results[0]['password'] === 'goodbye');
    $this->assert($results[0]['birthday'] == '2000-09-10 13:13:13');
    
    $results = $this->mdb->query("SELECT * FROM accounts WHERE username!=%s", "Charlie's Friend");
    $this->assert(count($results) === 3);
    
    $columnList = $this->mdb->columnList('accounts');
    $columnKeys = array_keys($columnList);
    $this->assert(count($columnList) === 8);

    if ($this->db_type == 'mysql') {
      $this->assert($columnList['id']['type'] == 'int(11)');
      $this->assert($columnList['height']['type'] == 'double');
    }
    else if ($this->db_type == 'sqlite') {
      $this->assert($columnList['id']['type'] == 'INTEGER');
      $this->assert($columnList['height']['type'] == 'DOUBLE');
    }

    $this->assert($columnKeys[5] == 'height');
    
    $tablelist = $this->mdb->tableList();
    $this->assert(count($tablelist) === 3);
    $this->assert($tablelist[0] === 'accounts');
    
    $tablelist = null;
    $tablelist = $this->mdb->tableList($this->mdb->dbName);
    $this->assert(count($tablelist) === 3);
    $this->assert($tablelist[0] === 'accounts');

    if ($this->db_type == 'sqlite') {
      $date = $this->mdb->queryFirstField("SELECT strftime('%%m/%%d/%%Y', birthday) FROM accounts WHERE username=%s", "Charlie's Friend");
      $date2 = $this->mdb->queryFirstField("SELECT strftime('%m/%d/%Y', '2009-10-04 22:23:00')");
    }
    else if ($this->db_type == 'pgsql') {
      $date = $this->mdb->queryFirstField("SELECT TO_CHAR(TO_TIMESTAMP(birthday, 'YYYY-MM-DD HH24:MI:SS'), 'MM/DD/YYYY') FROM accounts WHERE username=%s", "Charlie's Friend");
      $date2 = $this->mdb->queryFirstField("SELECT TO_CHAR(TIMESTAMP '2009-10-04 22:23:00', 'MM/DD/YYYY')");
    }
    else {
      $date = $this->mdb->queryFirstField("SELECT DATE_FORMAT(birthday, '%%m/%%d/%%Y') FROM accounts WHERE username=%s", "Charlie's Friend");
      $date2 = $this->mdb->queryFirstField("SELECT DATE_FORMAT('2009-10-04 22:23:00', '%m/%d/%Y')");;
    }
    $this->assert($date === '09/10/2000');
    $this->assert($date2 === '10/04/2009');
  }
  
  function test_4_1_query() {
    $this->mdb->insert('accounts', array(
      'username' => 'newguy',
      'password' => $this->mdb->sqleval("SUBSTR('abcdefgh', %i)", '3'),
      'user.age' => $this->mdb->sqleval('171+1'),
      'height' => 111.15
    ));
    
    $row = $this->mdb->queryOneRow("SELECT * FROM accounts WHERE password=%s", 'cdefgh');
    $this->assert($row['username'] === 'newguy');
    $this->assert($row['user.age'] === '172');
    
    $affected_rows = $this->mdb->update('accounts', array(
      'password' => $this->mdb->sqleval("SUBSTR('abcdefgh', %i)", 4),
      'favorite_word' => null,
      ), 'username=%s_name', array('name' => 'newguy'));
    
    $row = null;
    $row = $this->mdb->queryOneRow("SELECT * FROM accounts WHERE username=%s", 'newguy');
    $this->assert($affected_rows === 1);
    $this->assert($row['password'] === 'defgh');
    $this->assert($row['favorite_word'] === null);
    
    $row = $this->mdb->query("SELECT * FROM accounts WHERE password=%s_mypass AND (password=%s_mypass) AND username=%s_myuser", 
      array('myuser' => 'newguy', 'mypass' => 'defgh')
    );
    $this->assert(count($row) === 1);
    $this->assert($row[0]['username'] === 'newguy');
    $this->assert($row[0]['user.age'] === '172');

    $row = $this->mdb->query("SELECT * FROM accounts WHERE password IN %ls AND password IN %ls0 AND username=%s", array('defgh'), 'newguy');
    $this->assert(count($row) === 1);
    $this->assert($row[0]['username'] === 'newguy');
    $this->assert($row[0]['user.age'] === '172');
    
    $affected_rows = $this->mdb->query("DELETE FROM accounts WHERE password=%s", 'defgh');
    $this->assert($affected_rows === 1);
    $this->assert($this->mdb->affectedRows() === 1);
  }
  
  function test_4_2_delete() {
    $this->mdb->insert('accounts', array(
      'username' => 'gonesoon',
      'password' => 'something',
      'user.age' => 61,
      'height' => 199.194
    ));
    
    $ct = $this->mdb->queryFirstField("SELECT COUNT(*) FROM accounts WHERE %ha", array('username' => 'gonesoon', 'height' => 199.194));
    $this->assert(intval($ct) === 1);
    
    $ct = $this->mdb->queryFirstField("SELECT COUNT(*) FROM accounts WHERE username=%s1 AND height=%d0 AND height=%d", 199.194, 'gonesoon');
    $this->assert(intval($ct) === 1);
    
    $affected_rows = $this->mdb->delete('accounts', 'username=%s AND %c=%i AND height=%d', 
      'gonesoon', 'user.age', '61', '199.194');
    $this->assert($affected_rows === 1);
    $this->assert($this->mdb->affectedRows() === 1);
    
    $ct = $this->mdb->queryFirstField("SELECT COUNT(*) FROM accounts WHERE username=%s AND height=%d", 'gonesoon', '199.194');
    $this->assert(intval($ct) === 0);
  }
  
  function test_4_3_insertmany() {
    $ins[] = array(
      'username' => '1ofmany',
      'password' => 'something',
      'user.age' => 23,
      'height' => 190.194
    );
    $ins[] = array(
      'password' => 'somethingelse',
      'username' => '2ofmany',
      'user.age' => 25,
      'height' => 190.194
    );
    $ins[] = array(
      'password' => NULL,
      'username' => '3ofmany',
      'user.age' => 15,
      'height' => 111.951
    ); 
    
    $this->mdb->insert('accounts', $ins);
    $this->assert($this->mdb->affectedRows() === 3);
    
    $rows = $this->mdb->query("SELECT * FROM accounts WHERE height=%d ORDER BY %c ASC", 190.194, 'user.age');
    $this->assert(count($rows) === 2);
    $this->assert($rows[0]['username'] === '1ofmany');
    $this->assert($rows[0]['user.age'] === '23');
    $this->assert($rows[1]['user.age'] === '25');
    $this->assert($rows[1]['password'] === 'somethingelse');
    $this->assert($rows[1]['username'] === '2ofmany');
    
    $nullrow = $this->mdb->queryOneRow("SELECT * FROM accounts WHERE username LIKE %ss", '3ofman');
    $this->assert($nullrow['password'] === NULL);
    $this->assert($nullrow['user.age'] === '15');
  }
  
  
  
  function test_5_insert_blobs() {
    $this->mdb->query($this->get_sql('create_store'));

    $columns = $this->mdb->columnList('store data');
    $this->assert(count($columns) === 2);

    if ($this->db_type == 'sqlite') {
      $this->assert($columns['picture']['type'] === 'BLOB');
      $this->assert($columns['picture']['notnull'] === '0');
      $this->assert($columns['picture']['dflt_value'] === NULL);
    }
    else if ($this->db_type == 'pgsql') {
      $this->assert($columns['picture']['data_type'] === 'bytea');
      $this->assert($columns['picture']['is_nullable'] === 'YES');
      $this->assert($columns['picture']['column_default'] === NULL);
    }
    else {
      $this->assert($columns['picture']['type'] === 'blob');
      $this->assert($columns['picture']['null'] === 'YES');
      $this->assert($columns['picture']['key'] === '');
      $this->assert($columns['picture']['default'] === NULL);
      $this->assert($columns['picture']['extra'] === '');
    }
    
    $smile = file_get_contents(__DIR__ . '/smile1.jpg');
    $this->mdb->insert('store data', array(
      'picture' => $smile,
    ));
    $this->mdb->query("INSERT INTO %b (picture) VALUES (%s)", 'store data', $smile);
    
    $getsmile = $this->mdb->queryFirstField("SELECT picture FROM %b WHERE id=1", 'store data');
    $getsmile2 = $this->mdb->queryFirstField("SELECT picture FROM %b WHERE id=2", 'store data');
    $this->assert($smile === $getsmile);
    $this->assert($smile === $getsmile2);
  }
  
  function test_6_insert_ignore() {
    if ($this->db_type == 'pgsql') return;

    $affected_rows = $this->mdb->insertIgnore('accounts', array(
      'id' => 1, //duplicate primary key
      'username' => 'gonesoon',
      'password' => 'something',
      'user.age' => 61,
      'height' => 199.194
    ));
    $this->assert($affected_rows === 0);
  }
  
  function test_7_insert_update() {
    if ($this->db_type == 'pgsql') return;

    $this->mdb->insertUpdate('accounts', array(
      'id' => 2, //duplicate primary key
      'username' => 'gonesoon',
      'password' => 'something',
      'user.age' => 61,
      'height' => 199.194
    ), '`user.age` = `user.age` + %i', 1);
    
    $result = $this->mdb->query("SELECT * FROM accounts WHERE `user.age` = %i", 16);
    $this->assert(count($result) === 1);
    $this->assert($result[0]['height'] === '10.371');
    
    $this->mdb->insertUpdate('accounts', array(
      'id' => 2, //duplicate primary key
      'username' => 'blahblahdude',
      'user.age' => 233,
      'height' => 199.194
    ));
    
    $result = $this->mdb->query("SELECT * FROM accounts WHERE `user.age` = %i", 233);
    $this->assert(count($result) === 1);
    $this->assert($result[0]['height'] === '199.194');
    $this->assert($result[0]['username'] === 'blahblahdude');
    
    $this->mdb->insertUpdate('accounts', array(
      'id' => 2, //duplicate primary key
      'username' => 'gonesoon',
      'password' => 'something',
      'user.age' => 61,
      'height' => 199.194
    ), array(
      'user.age' => 74,
    ));
    
    $result = $this->mdb->query("SELECT * FROM accounts WHERE `user.age` = %i", 74);
    $this->assert(count($result) === 1);
    $this->assert($result[0]['height'] === '199.194');
    $this->assert($result[0]['username'] === 'blahblahdude');
    
    $multiples[] = array(
      'id' => 3, //duplicate primary key
      'username' => 'gonesoon',
      'password' => 'something',
      'user.age' => 61,
      'height' => 199.194
    );
    $multiples[] = array(
      'id' => 1, //duplicate primary key
      'username' => 'gonesoon',
      'password' => 'something',
      'user.age' => 61,
      'height' => 199.194
    );
    
    $this->mdb->insertUpdate('accounts', $multiples, array('user.age' => 914));
    
    $result = $this->mdb->query("SELECT * FROM accounts WHERE `user.age`=914 ORDER BY id ASC");
    $this->assert(count($result) === 2);
    $this->assert($result[0]['username'] === 'Abe');
    $this->assert($result[1]['username'] === 'Charlie\'s Friend');
    
    $affected_rows = $this->mdb->query("UPDATE accounts SET `user.age`=15, username='Bart' WHERE `user.age`=%i", 74);
    $this->assert($affected_rows === 1);
    $this->assert($this->mdb->affectedRows() === 1);
  }
  
  function test_8_lb() {
    $data = array(
      'username' => 'vookoo',
      'password' => 'dookoo',
    );
    
    $affected_rows = $this->mdb->query("INSERT into accounts %lc VALUES %ls", array_keys($data), array_values($data));
    $result = $this->mdb->query("SELECT * FROM accounts WHERE username=%s", 'vookoo');
    $this->assert($affected_rows === 1);
    $this->assert(count($result) === 1);
    $this->assert($result[0]['password'] === 'dookoo');
  }

  function test_9_fullcolumns() {
    $affected_rows = $this->mdb->insert('profile', array(
      'id' => 1,
      'signature' => 'u_suck'
    ));
    $this->mdb->query("UPDATE accounts SET profile_id=1 WHERE id=2");


    $as_str = '';
    if ($this->db_type == 'pgsql') $as_str = 'AS "1+1"';

    $r = $this->mdb->queryFullColumns("SELECT accounts.*, profile.*, 1+1 {$as_str} FROM accounts
      INNER JOIN profile ON accounts.profile_id=profile.id");

    $this->assert($affected_rows === 1);
    $this->assert(count($r) === 1);
    $this->assert($r[0]['accounts.id'] === '2');
    $this->assert($r[0]['profile.id'] === '1');
    $this->assert($r[0]['profile.signature'] === 'u_suck');
    $this->assert($r[0]['1+1'] === '2');
  }

  function test_901_updatewithspecialchar() {
    $data = 'www.mysite.com/product?s=t-%s-%%3d%%3d%i&RCAID=24322';
    $this->mdb->update('profile', array('signature' => $data), 'id=%i', 1);
    $signature = $this->mdb->queryFirstField("SELECT signature FROM profile WHERE id=%i", 1);
    $this->assert($signature === $data);

    $this->mdb->update('profile', array('signature'=> "%li "), array('id' => 1));
    $signature = $this->mdb->queryFirstField("SELECT signature FROM profile WHERE id=%i", 1);
    $this->assert($signature === "%li ");
  }

  function test_902_faketable() {
    $this->mdb->insert('fake%s_table', array('name' => 'karen'));
    $count = $this->mdb->queryFirstField("SELECT COUNT(*) FROM %b", 'fake%s_table');
    $this->assert($count === '1');
    $this->mdb->update('fake%s_table', array('name' => 'haren%s'), 'name=%s_name', array('name' => 'karen'));
    $affected_rows = $this->mdb->delete('fake%s_table', array('name' => 'haren%s'));
    $count = $this->mdb->queryFirstField("SELECT COUNT(*) FROM %b", 'fake%s_table');
    $this->assert($affected_rows === 1);
    $this->assert($count === '0');
  }

  function test_11_timeout() {
    if ($this->db_type != 'mysql') return;
    if ($this->fast) return;
    
    $default = $this->mdb->reconnect_after;
    $this->mdb->reconnect_after = 1;
    $this->mdb->query("SET SESSION wait_timeout=1");
    sleep(2);
    $this->mdb->query("SELECT * FROM accounts");
    $this->mdb->reconnect_after = $default;
  }

}
