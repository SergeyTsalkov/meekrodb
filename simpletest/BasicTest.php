<?php
class BasicTest extends SimpleTest {
  public $last_func;

  function __construct() {
    foreach (DB::tableList() as $table) {
      DB::query("DROP TABLE %b", $table);
    }

    DB::removeHooks('pre_run');
    DB::addHook('pre_run', function($hash) {
      $this->last_func = $hash['func_name'];
    });
  }
  
  function test_01_create_table() {
    DB::query($this->get_sql('create_accounts'));
    DB::query($this->get_sql('create_profile'));
  }
  
  function test_02_empty_table() {
    $counter = DB::queryFirstField("SELECT COUNT(*) FROM accounts");
    $this->assert($this->last_func === 'queryFirstField');
    $this->assert($counter === strval(0));
    $this->assert(DB::lastQuery() === 'SELECT COUNT(*) FROM accounts');
    
    $row = DB::queryFirstRow("SELECT * FROM accounts");
    $this->assert($this->last_func === 'queryFirstRow');
    $this->assert($row === null);
    
    $field = DB::queryFirstField("SELECT * FROM accounts");
    $this->assert($field === null);
    
    $column = DB::queryFirstColumn("SELECT * FROM accounts");
    $this->assert($this->last_func === 'queryFirstColumn');
    $this->assert(is_array($column) && count($column) === 0);
  }
  
  function test_03_insert_row() {
    $affected_rows = DB::insert('accounts', array(
      'username' => 'Abe',
      'password' => 'hello'
    ));
    
    $this->assert($this->last_func === 'insert');
    $this->assert($affected_rows === 1);
    $this->assert(DB::affectedRows() === 1);
    
    $counter = DB::queryFirstField("SELECT COUNT(*) FROM accounts");
    $this->assert($counter === '1');
  }
  
  function test_04_more_inserts() {
    DB::insert('accounts', array(
      'username' => 'Bart',
      'password' => 'hello',
      'age' => 15,
      'height' => 10.371
    ));

    DB::insert('accounts', array(
      'username' => 'Charlie\'s Friend',
      'password' => 'goodbye',
      'age' => 30,
      'height' => 155.23,
      'favorite_word' => null,
    ));

    $this->assert(DB::insertId() == 3);
    $counter = DB::queryFirstField("SELECT COUNT(*) FROM accounts");
    $this->assert($counter === strval(3));
    
    DB::insert('accounts', array(
      'username' => 'Deer',
      'password' => '',
      'age' => 15,
      'height' => 10.371
    ));

    $username = DB::queryFirstField("SELECT username FROM accounts WHERE password=%s0", null);
    $this->assert($username === 'Deer');

    $password = DB::queryFirstField("SELECT password FROM accounts WHERE favorite_word IS NULL");
    $this->assert($password === 'goodbye');
  }

  // * basic test of:
  //   queryFirstField(), queryFirstColumn(), queryFirstList(), queryRaw()
  function test_05_query() {
    $charlie_password = DB::queryFirstField("SELECT password FROM accounts WHERE username IN %ls AND username = %s", 
      array('Charlie', 'Charlie\'s Friend'), 'Charlie\'s Friend');
    $this->assert($charlie_password === 'goodbye');
    
    $passwords = DB::queryFirstColumn("SELECT password FROM accounts WHERE username=%s", 'Bart');
    $this->assert(count($passwords) === 1);
    $this->assert($passwords[0] === 'hello');

    list($username, $password) = DB::queryFirstList("SELECT username, password FROM accounts WHERE id=%i", 1);
    $this->assert($this->last_func === 'queryFirstList');
    $this->assert($username === 'Abe');
    $this->assert($password === 'hello');
    
    $statement = DB::queryRaw("SELECT * FROM accounts WHERE favorite_word IS NULL");
    $this->assert($this->last_func === 'queryRaw');
    $this->assert($statement instanceof PDOStatement);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    $row2 = $statement->fetch(PDO::FETCH_ASSOC);
    $this->assert($row['password'] === 'goodbye');
    $this->assert($row2 === false);
  }

  // * alternative param_char and named_param_seperator separate will work
  // * can access both named and numbered args
  // * numbered args can be accessed both with and without named_param_seperator
  // * param_char of strlen>1 will work
  function test_06_alt_param_chars() {
    DB::$param_char = ':';
    DB::$named_param_seperator = ':';
    $bart = DB::queryFirstRow(
      "SELECT * FROM accounts WHERE age IN :li AND height IN :ld AND username IN :ls",
      array(15, 25), array(10.371, 150.123), array('Bart', 'Barts')
    );
    $this->assert($bart['username'] === 'Bart');

    $bart = DB::queryFirstRow(
      "SELECT * FROM accounts WHERE age IN :li:ages AND height IN :ld:heights AND username IN :ls:names", array(
          'ages' => array(15, 25), 
          'heights' => array(10.371, 150.123),
          'names' => array('Bart', 'Barts'),
        )
    );
    $this->assert($bart['username'] === 'Bart');

    $row = DB::queryFirstRow("SELECT * FROM accounts WHERE :c0=:i1 AND height=:i1", 'age', 10);
    $this->assert($row['id'] === '1');
    $this->assert($row['username'] === 'Abe');

    $row = DB::queryFirstRow("SELECT * FROM accounts WHERE :c:0=:i:1 AND height=:i:1", array('age', 10));
    $this->assert($row['id'] === '1');
    $this->assert($row['username'] === 'Abe');

    DB::$param_char = '###';
    DB::$named_param_seperator = '_';
    $bart = DB::queryFirstRow("SELECT * FROM accounts WHERE age IN ###li AND height IN ###ld AND username IN ###ls", 
      array(15, 25), array(10.371, 150.123), array('Bart', 'Barts'));
    $this->assert($bart['username'] === 'Bart');
    DB::insert('accounts', array('username' => 'f_u'));
    DB::query("DELETE FROM accounts WHERE username=###s", 'f_u');
    $this->assert($this->last_func === 'query');
    DB::$param_char = '%';
  }
  
  function test_07_query() {
    $affected_rows = DB::update('accounts', array(
      'birthday' => new DateTime('10 September 2000 13:13:13')
    ), 'username=%s', 'Charlie\'s Friend');
    $this->assert($affected_rows === 1);
    $this->assert($this->last_func == 'update');

    $results = DB::query("SELECT * FROM accounts WHERE username=%s AND birthday IN %lt", 'Charlie\'s Friend', array('September 10 2000 13:13:13'));
    $this->assert(count($results) === 1);
    $this->assert($results[0]['age'] === '30' && $results[0]['password'] === 'goodbye');
    $this->assert($results[0]['birthday'] == '2000-09-10 13:13:13');
    
    $results = DB::query("SELECT * FROM accounts WHERE username!=%s", "Charlie's Friend");
    $this->assert(count($results) === 3);
    
    $columnList = DB::columnList('accounts');
    $columnKeys = array_keys($columnList);
    $this->assert($this->last_func === 'columnList');
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
    
    $tablelist = DB::tableList();
    $this->assert($this->last_func === 'tableList');
    $this->assert(count($tablelist) === 2);
    $this->assert($tablelist[0] === 'accounts');
    
    $tablelist = null;
    $tablelist = DB::tableList(DB::$dbName);
    $this->assert(count($tablelist) === 2);
    $this->assert($tablelist[0] === 'accounts');

    if ($this->db_type == 'sqlite') {
      $date = DB::queryFirstField("SELECT strftime('%%m/%%d/%%Y', birthday) FROM accounts WHERE username=%s", "Charlie's Friend");
      $date2 = DB::queryFirstField("SELECT strftime('%m/%d/%Y', '2009-10-04 22:23:00')");
    }
    else if ($this->db_type == 'pgsql') {
      $date = DB::queryFirstField("SELECT TO_CHAR(TO_TIMESTAMP(birthday, 'YYYY-MM-DD HH24:MI:SS'), 'MM/DD/YYYY') FROM accounts WHERE username=%s", "Charlie's Friend");
      $date2 = DB::queryFirstField("SELECT TO_CHAR(TIMESTAMP '2009-10-04 22:23:00', 'MM/DD/YYYY')");
    }
    else {
      $date = DB::queryFirstField("SELECT DATE_FORMAT(birthday, '%%m/%%d/%%Y') FROM accounts WHERE username=%s", "Charlie's Friend");
      $date2 = DB::queryFirstField("SELECT DATE_FORMAT('2009-10-04 22:23:00', '%m/%d/%Y')");;
    }
    $this->assert($date === '09/10/2000');
    $this->assert($date2 === '10/04/2009');
  }
  
  function test_08_query() {
    DB::insert('accounts', array(
      'username' => 'newguy',
      'password' => DB::sqleval("SUBSTR('abcdefgh', %i)", '3'),
      'age' => DB::sqleval('171+1'),
      'height' => 111.15
    ));
    
    $affected_rows = DB::update('accounts', array(
      'password' => DB::sqleval("SUBSTR('abcdefgh', %i)", 4),
      'favorite_word' => null,
      ), 'username=%s_name', array('name' => 'newguy'));
    
    $row = DB::query("SELECT * FROM accounts WHERE password=%s_mypass AND (password=%s_mypass) AND username=%s_myuser", 
      array('myuser' => 'newguy', 'mypass' => 'defgh')
    );
    $this->assert(count($row) === 1);
    $this->assert($row[0]['username'] === 'newguy');
    $this->assert($row[0]['age'] === '172');

    $row = DB::query("SELECT * FROM accounts WHERE password IN %ls AND password IN %ls0 AND username=%s", array('defgh'), 'newguy');
    $this->assert(count($row) === 1);
    $this->assert($row[0]['username'] === 'newguy');
    $this->assert($row[0]['age'] === '172');
    
    $affected_rows = DB::query("DELETE FROM accounts WHERE password=%s", 'defgh');
    $this->assert($affected_rows === 1);
    $this->assert(DB::affectedRows() === 1);
  }
  
  function test_09_delete() {
    DB::insert('accounts', array(
      'username' => 'gonesoon',
      'password' => 'something',
      'age' => 61,
      'height' => 199.194
    ));
    
    $ct = DB::queryFirstField("SELECT COUNT(*) FROM accounts WHERE %ha", array('username' => 'gonesoon', 'height' => 199.194));
    $this->assert(intval($ct) === 1);
    
    $ct = DB::queryFirstField("SELECT COUNT(*) FROM accounts WHERE username=%s1 AND height=%d0 AND height=%d", 199.194, 'gonesoon');
    $this->assert(intval($ct) === 1);
    
    $affected_rows = DB::delete('accounts', 'username=%s AND age=%i AND height=%d', 
      'gonesoon', '61', '199.194');
    $this->assert($this->last_func === 'delete');
    $this->assert($affected_rows === 1);
    $this->assert(DB::affectedRows() === 1);
    
    $ct = DB::queryFirstField("SELECT COUNT(*) FROM accounts WHERE username=%s AND height=%d", 'gonesoon', '199.194');
    $this->assert(intval($ct) === 0);
  }
  
  // * insert() multiple rows at once, read them back
  function test_10_insertmany() {
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
    $ins[] = array(
      'password' => NULL,
      'username' => '3ofmany',
      'age' => 15,
      'height' => 111.951
    ); 
    
    $affected_rows = DB::insert('accounts', $ins);
    $this->assert($affected_rows === 3);
    $this->assert(DB::affectedRows() === 3);
    
    $rows = DB::query("SELECT * FROM accounts WHERE height=%d ORDER BY %c ASC", 190.194, 'age');
    $this->assert(count($rows) === 2);
    $this->assert($rows[0]['username'] === '1ofmany');
    $this->assert($rows[0]['age'] === '23');
    $this->assert($rows[1]['age'] === '25');
    $this->assert($rows[1]['password'] === 'somethingelse');
    $this->assert($rows[1]['username'] === '2ofmany');
  }
  
  function test_13_lb() {
    $data = array(
      'username' => 'vookoo',
      'password' => 'dookoo',
    );
    
    $affected_rows = DB::query("INSERT into accounts %lc VALUES %ls", array_keys($data), array_values($data));
    $result = DB::query("SELECT * FROM accounts WHERE username=%s", 'vookoo');
    $this->assert($affected_rows === 1);
    $this->assert(count($result) === 1);
    $this->assert($result[0]['password'] === 'dookoo');
  }

  function test_14_fullcolumns() {
    // old pgsql pdo driver doesn't support getColumnMeta()['table']
    if ($this->db_type == 'pgsql' && phpversion() < '7.0') return;

    $affected_rows = DB::insert('profile', array(
      'id' => 1,
      'signature' => 'u_suck'
    ));
    $this->assert($affected_rows === 1);

    DB::query("UPDATE accounts SET profile_id=1 WHERE id=2");

    $as_str = '';
    if ($this->db_type == 'pgsql') $as_str = 'AS "1+1"';

    $r = DB::queryFullColumns("SELECT accounts.*, profile.*, 1+1 {$as_str} FROM accounts
      INNER JOIN profile ON accounts.profile_id=profile.id");

    $this->assert($this->last_func === 'queryFullColumns');
    $this->assert(count($r) === 1);
    $this->assert($r[0]['accounts.id'] === '2');
    $this->assert($r[0]['profile.id'] === '1');
    $this->assert($r[0]['profile.signature'] === 'u_suck');
    $this->assert($r[0]['1+1'] === '2');
  }

  function test_15_timeout() {
    if ($this->db_type != 'mysql') return;
    if ($this->fast) return;
    
    $default = DB::$reconnect_after;
    DB::$reconnect_after = 1;
    DB::query("SET SESSION wait_timeout=1");
    sleep(2);
    DB::query("SELECT * FROM accounts");
    DB::$reconnect_after = $default;
  }
  
}
