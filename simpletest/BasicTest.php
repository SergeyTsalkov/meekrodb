<?php
class BasicTest extends SimpleTest {
  function __construct() {
    $this->resetDatabase();
    DB::addHook('pre_run', function($hash) {
      $this->last_func = $hash['func_name'];
    });
  }

  // * query(), queryFirstRow(), queryFirstField(), queryFirstList(), queryFirstColumn(), queryAllLists()
  // * queryRaw() gives the PDOStatement
  // * lastQuery(), and the func_name that hooks get
  function test_01_query_helpers() {
    $this->assert(DB::queryFirstField("SELECT COUNT(*) FROM users") === '4');
    $this->assert($this->last_func === 'queryFirstField');
    $this->assert(DB::lastQuery() === 'SELECT COUNT(*) FROM users');

    $row = DB::queryFirstRow("SELECT * FROM users WHERE name=%s", 'Bart');
    $this->assert($this->last_func === 'queryFirstRow');
    $this->assert($row['user_id'] === '2');
    $this->assert($row['nickname'] === 'Bartman');

    list($name, $age) = DB::queryFirstList("SELECT name, age FROM users WHERE user_id=%i", 3);
    $this->assert($this->last_func === 'queryFirstList');
    $this->assert($name === 'Cleo');
    $this->assert($age === '41');

    $names = DB::queryFirstColumn("SELECT name FROM users WHERE age=%i ORDER BY user_id", 15);
    $this->assert($this->last_func === 'queryFirstColumn');
    $this->assert($names === ['Bart', "Dana O'Neil"]);

    $rows = DB::query("SELECT name, age FROM users WHERE age>%i ORDER BY user_id", 20);
    $this->assert($this->last_func === 'query');
    $this->assert($rows === [['name' => 'Abe', 'age' => '30'], ['name' => 'Cleo', 'age' => '41']]);

    $lists = DB::queryAllLists("SELECT name, age FROM users WHERE age>%i ORDER BY user_id", 20);
    $this->assert($this->last_func === 'queryAllLists');
    $this->assert($lists === [['Abe', '30'], ['Cleo', '41']]);

    $statement = DB::queryRaw("SELECT name FROM users WHERE user_id=%i", 1);
    $this->assert($this->last_func === 'queryRaw');
    $this->assert($statement instanceof PDOStatement);
    $this->assert($statement->fetch(PDO::FETCH_ASSOC) === ['name' => 'Abe']);
    $this->assert($statement->fetch(PDO::FETCH_ASSOC) === false);
  }

  // * with no matching rows, queryFirstRow/Field/List() give null, and query()/queryFirstColumn() give []
  // * a statement with no result set at all (like an UPDATE) gives the same from the queryFirst*() helpers
  function test_02_no_results() {
    foreach (["SELECT * FROM users WHERE name='Nobody'", "UPDATE users SET age=age WHERE name='Nobody'"] as $query) {
      $this->assert(DB::queryFirstRow($query) === null);
      $this->assert(DB::queryFirstField($query) === null);
      $this->assert(DB::queryFirstList($query) === null);
      $this->assert(DB::queryFirstColumn($query) === []);
    }
    $this->assert(DB::query("SELECT * FROM users WHERE name='Nobody'") === []);
  }

  // * insert() returns the affected rows, and insertId() gives the new id
  // * columns left out get their defaults, and an empty insert is all defaults
  // * null is stored as NULL, and so is an array where a single value is expected
  function test_03_insert() {
    $affected = DB::insert('users', ['name' => 'Eve', 'age' => 22, 'height' => 1.7, 'nickname' => []]);
    $this->assert($this->last_func === 'insert');
    $this->assert($affected === 1);
    $this->assert(DB::affectedRows() === 1);
    $this->assert(DB::insertId() == 5);

    $row = DB::queryFirstRow("SELECT * FROM users WHERE user_id=%i", 5);
    $this->assert($row['name'] === 'Eve');
    $this->assert($row['age'] === '22');
    $this->assert($row['height'] === '1.7');
    $this->assert($row['nickname'] === null);
    $this->assert($row['employer_id'] === null);
    $this->assert($row['settings'] === '');
    $this->assert($row['signed_up_at'] === '1970-01-03 00:00:00');

    DB::insert('users', ['name' => 'Fay', 'employer_id' => null]);
    $row = DB::queryFirstRow("SELECT * FROM users WHERE name=%s", 'Fay');
    $this->assert($row['nickname'] === 'buddy');
    $this->assert($row['employer_id'] === null);

    DB::insert('users', []);
    $this->assert(DB::queryFirstField("SELECT COUNT(*) FROM users WHERE name=''") === '1');

    DB::query("DELETE FROM users WHERE user_id>%i", 4);
  }

  // * insert() several rows at once, which must all have the same keys (in any order)
  // * ORDER BY with %c
  function test_04_insert_many() {
    $affected = DB::insert('users', [
      ['name' => 'Gus', 'age' => 50],
      ['age' => 51, 'name' => 'Hal'],
    ]);
    $this->assert($affected === 2);
    $this->assert(DB::affectedRows() === 2);

    $rows = DB::queryAllLists("SELECT name, age FROM users WHERE age>=%i ORDER BY %c", 50, 'age');
    $this->assert($rows === [['Gus', '50'], ['Hal', '51']]);

    $this->assertThrows(function() {
      DB::insert('users', [['name' => 'Ivy'], ['name' => 'Jo', 'age' => 1]]);
    }, 'must have the same keys');

    DB::query("DELETE FROM users WHERE user_id>%i", 4);
  }

  // * update() and delete() take a where string with args, or a hash; both return the affected rows
  // * values can be DateTime objects, or sqleval() expressions
  // * query() of a statement with no result set returns the affected rows
  function test_05_update_delete() {
    $affected = DB::update('users', ['age' => 31, 'signed_up_at' => new DateTime('2021-05-06 07:08:09')],
      'name=%s', 'Abe');
    $this->assert($this->last_func === 'update');
    $this->assert($affected === 1);
    $this->assert(DB::affectedRows() === 1);
    $row = DB::queryFirstRow("SELECT * FROM users WHERE user_id=%i", 1);
    $this->assert($row['age'] === '31');
    $this->assert($row['signed_up_at'] === '2021-05-06 07:08:09');

    $affected = DB::update('users', ['age' => DB::sqleval('age + %i', 10)], ['employer_id' => 1]);
    $this->assert($affected === 2);
    $this->assert(DB::queryFirstColumn("SELECT age FROM users WHERE employer_id=1 ORDER BY user_id") === ['41', '25']);

    DB::insert('users', ['name' => 'Temp', 'nickname' => DB::sqleval("SUBSTR('abcdefgh', %i)", 3)]);
    $this->assert(DB::queryFirstField("SELECT nickname FROM users WHERE name='Temp'") === 'cdefgh');

    $affected = DB::delete('users', 'name=%s AND age=%i', 'Temp', 0);
    $this->assert($this->last_func === 'delete');
    $this->assert($affected === 1);
    $this->assert(DB::affectedRows() === 1);
    $this->assert(DB::delete('users', ['name' => 'Nobody']) === 0);

    $affected = DB::query("UPDATE users SET age=%i, signed_up_at=%s WHERE user_id IN %li",
      30, '1970-01-03 00:00:00', [1]);
    $this->assert($affected === 1);
    DB::update('users', ['age' => 15], ['user_id' => 2]);
  }

  // * %t and %lt take DateTime objects or strings
  function test_06_timestamps() {
    $names = DB::queryFirstColumn("SELECT name FROM users WHERE signed_up_at=%t", new DateTime('2020-01-02 03:04:05'));
    $this->assert($names === ['Cleo']);

    $names = DB::queryFirstColumn("SELECT name FROM users WHERE signed_up_at IN %lt", ['January 2 2020 03:04:05']);
    $this->assert($names === ['Cleo']);
  }

  // * %% is a literal % in a query with args, and a query without any args is sent as-is
  function test_07_percent() {
    $this->assert(DB::queryFirstField("SELECT COUNT(*) FROM users WHERE name LIKE 'B%%' AND age=%i", 15) === '1');
    $this->assert(DB::queryFirstField("SELECT COUNT(*) FROM users WHERE name LIKE 'B%'") === '1');
  }

  // * %ss, %ssb and %sse match anywhere / at the start / at the end of a string
  // * LIKE wildcards in the value (% and _) are matched literally
  function test_08_like() {
    DB::insert('users', [['name' => 'snake_case'], ['name' => 'snakeXcase'], ['name' => '100% sure'], ['name' => '100 percent']]);
    $like = function($placeholder, $value) {
      return DB::queryFirstColumn("SELECT name FROM users WHERE name LIKE {$placeholder} ORDER BY user_id", $value);
    };

    $this->assert($like('%ss', 'ar') === ['Bart']);
    $this->assert($like('%ssb', 'Cl') === ['Cleo']);
    $this->assert($like('%ssb', 'le') === []);
    $this->assert($like('%sse', 'Neil') === ["Dana O'Neil"]);
    $this->assert($like('%ss', '_') === ['snake_case']);
    $this->assert($like('%ssb', 'snake_') === ['snake_case']);
    $this->assert($like('%ss', '0%') === ['100% sure']);

    DB::query("DELETE FROM users WHERE user_id>%i", 4);
  }

  // * %b and %c quote table and column names; %lc and %l? build an INSERT by hand
  function test_09_names() {
    $data = ['name' => 'Kim', 'age' => 33];
    $affected = DB::query("INSERT INTO %b %lc VALUES %l?", 'users', array_keys($data), array_values($data));
    $this->assert($affected === 1);
    $this->assert(DB::queryFirstField("SELECT %c FROM %b WHERE %c=%s", 'age', 'users', 'name', 'Kim') === '33');
    DB::delete('users', ['name' => 'Kim']);
  }

  // * queryFullColumns() keys each value by table.column, so columns with the same name don't collide
  function test_10_full_columns() {
    $as = ($this->db_type == 'pgsql') ? 'AS "1+1"' : '';
    $rows = DB::queryFullColumns("SELECT users.*, companies.*, 1+1 {$as} FROM users
      JOIN companies ON users.employer_id=companies.company_id WHERE users.user_id=%i", 2);

    $this->assert($this->last_func === 'queryFullColumns');
    $this->assert(count($rows) === 1);
    $this->assert($rows[0]['users.name'] === 'Bart');
    $this->assert($rows[0]['companies.name'] === 'Acme');
    $this->assert($rows[0]['companies.company_id'] === '1');
    $this->assert($rows[0]['1+1'] === '2');
  }

  // * columnList() gives the columns in order, with the database's own info about each
  // * tableList() lists the tables, optionally from a named database
  function test_11_column_and_table_lists() {
    $columns = DB::columnList('users');
    $this->assert($this->last_func === 'columnList');
    $this->assert(array_keys($columns) === ['user_id', 'employer_id', 'name', 'nickname', 'age', 'height',
      'is_active', 'settings', 'signed_up_at']);

    if ($this->db_type == 'mysql') {
      $this->assert(substr($columns['user_id']['type'], 0, 3) === 'int');
      $this->assert($columns['height']['type'] === 'double');
    }
    else if ($this->db_type == 'sqlite') {
      $this->assert($columns['user_id']['type'] === 'INTEGER');
      $this->assert($columns['height']['type'] === 'DOUBLE');
    }
    else if ($this->db_type == 'pgsql') {
      $this->assert($columns['user_id']['data_type'] === 'integer');
      $this->assert($columns['height']['data_type'] === 'double precision');
    }

    $expected = ['companies', 'memberships', 'profiles', 'users', 'weird %s:s_`"table'];
    $tables = DB::tableList();
    $this->assert($this->last_func === 'tableList');
    sort($tables);
    $this->assert($tables === $expected);

    $tables = DB::tableList(DB::$dbName);
    sort($tables);
    $this->assert($tables === $expected);
  }

  // * bools are stored as 1/0, and come back as '1'/'0' (or NULL), even for pgsql's native BOOLEAN
  // * bools can be compared with %?
  function test_12_bools() {
    $this->assert(DB::queryFirstColumn("SELECT is_active FROM users ORDER BY user_id") === ['1', '1', '0', null]);

    DB::update('users', ['is_active' => false], ['user_id' => 1]);
    $this->assert(DB::queryFirstField("SELECT is_active FROM users WHERE user_id=1") === '0');
    $this->assert(DB::queryFirstColumn("SELECT name FROM users WHERE is_active=%? ORDER BY user_id", true) === ['Bart']);
    DB::update('users', ['is_active' => true], ['user_id' => 1]);
  }

  // * supports('returning') matches the database type, unknown features throw
  // * unknown insert() options throw
  // * insert() with returning gives back the full row, including database defaults
  // * multi-row insert() with returning gives back a list of rows
  // * returning can be limited to a list of columns
  // * insertIgnore() with returning gives back null when the row was ignored
  // * where RETURNING isn't supported, asking for it throws
  function test_13_insert_returning() {
    $is_mariadb = stripos(DB::serverVersion(), 'mariadb') !== false;
    $supported = DB::supports('returning');
    if ($this->db_type == 'pgsql') $this->assert($supported === true);
    if ($this->db_type == 'sqlite') $this->assert($supported === version_compare(DB::serverVersion(), '3.35', '>='));
    if ($this->db_type == 'mysql' && !$is_mariadb) $this->assert($supported === false);

    $this->assertThrows(function() { DB::supports('time_travel'); }, 'unknown feature');
    $this->assertThrows(function() {
      DB::insert('users', ['name' => 'Typo'], ['returnin' => true]);
    }, 'unknown option');

    if (! $supported) {
      $this->assertThrows(function() {
        DB::insert('users', ['name' => 'Ret1'], ['returning' => true]);
      }, 'does not support RETURNING');
      $this->assert(DB::queryFirstField("SELECT COUNT(*) FROM users WHERE name='Ret1'") === '0');
      return;
    }

    $row = DB::insert('users', ['name' => 'Ret1'], ['returning' => true]);
    $this->assert($this->last_func === 'insert');
    $this->assert(DB::affectedRows() === 1);
    $this->assert(is_array($row));
    $this->assert($row['name'] === 'Ret1');
    $this->assert($row['age'] === '0');
    $this->assert($row['nickname'] === 'buddy');
    $this->assert($row['employer_id'] === null);
    $this->assert($row['user_id'] === DB::queryFirstField("SELECT user_id FROM users WHERE name='Ret1'"));

    $rows = DB::insert('users', [
      ['name' => 'Ret2', 'age' => 20],
      ['name' => 'Ret3', 'age' => 30],
    ], ['returning' => ['user_id', 'name', 'age']]);
    $this->assert(is_array($rows) && count($rows) === 2);
    $this->assert(DB::affectedRows() === 2);
    $this->assert(array_keys($rows[0]) === ['user_id', 'name', 'age']);
    $by_name = array_column($rows, 'age', 'name');
    ksort($by_name);
    $this->assert($by_name === ['Ret2' => '20', 'Ret3' => '30']);

    if (DB::supports('insert_ignore')) {
      $ignored = DB::insertIgnore('users', ['user_id' => $row['user_id'], 'name' => 'RetDup'], ['returning' => true]);
      $this->assert($ignored === null);
      $this->assert(DB::affectedRows() === 0);
      $this->assert(DB::queryFirstField("SELECT name FROM users WHERE user_id=%i", $row['user_id']) === 'Ret1');
    }

    DB::query("DELETE FROM users WHERE user_id>%i", 4);
  }

  // * replace() inserts a new row, or replaces an existing one with the same primary key
  // * where REPLACE isn't supported (pgsql), replace() throws a clear error
  function test_14_replace() {
    if (! DB::supports('replace')) {
      $this->assertThrows(function() {
        DB::replace('users', ['user_id' => 500, 'name' => 'Replaced']);
      }, 'does not support replace()');
      return;
    }

    DB::replace('users', ['user_id' => 500, 'name' => 'Replaced', 'age' => 50]);
    $this->assert($this->last_func === 'replace');
    DB::replace('users', ['user_id' => 500, 'name' => 'Replaced Again']);

    $rows = DB::query("SELECT * FROM users WHERE user_id=%i", 500);
    $this->assert(count($rows) === 1);
    $this->assert($rows[0]['name'] === 'Replaced Again');
    $this->assert($rows[0]['age'] === '0'); // replaced, not updated: back to the column default
    DB::delete('users', ['user_id' => 500]);
  }

  // * (mysql) after the server drops an idle connection, reconnect_after reconnects
  function test_15_timeout() {
    if ($this->db_type != 'mysql') return;
    if ($this->fast) return;

    $default = DB::$reconnect_after;
    DB::$reconnect_after = 1;
    DB::query("SET SESSION wait_timeout=1");
    sleep(2);
    DB::query("SELECT * FROM users");
    DB::$reconnect_after = $default;
  }
}
