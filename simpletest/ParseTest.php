<?php
// placeholders and args: mostly checks what DB::parse() produces, without running the query
class ParseTest extends SimpleTest {
  function __construct() {
    $this->resetDatabase();
  }

  // a table/column name, quoted the way the current database does it
  function q($name) {
    $char = ($this->db_type == 'pgsql') ? '"' : '`';
    return $char . $name . $char;
  }

  // DB::parse(...$args) must produce exactly $query and $params
  function assertParse(array $args, $query, array $params = []) {
    $Parsed = DB::parse(...$args);
    if ($Parsed->query !== $query || $Parsed->params !== $params) {
      $this->fail(sprintf("parse(%s) gave %s %s, expected %s %s", json_encode($args),
        json_encode($Parsed->query), json_encode($Parsed->params), json_encode($query), json_encode($params)));
    }
  }

  // * %s %i %d %l %t convert to their type
  // * %? picks the type from the value: null, bool, DateTime, or an already-parsed query
  // * an array where a single value is expected becomes an empty string
  function test_01_scalars() {
    $this->assertParse(['%s %i %d %l', 'x', '7', '1.5', 'NOW()'], '? ? ? NOW()', ['x', 7, 1.5]);
    $this->assertParse(['%s', null], '?', ['']);
    $this->assertParse(['%i', '12abc'], '?', [12]);
    $this->assertParse(['%t', '2020-01-02 03:04:05'], '?', ['2020-01-02 03:04:05']);
    $this->assertParse(['%t', new DateTime('2020-01-02 03:04:05')], '?', ['2020-01-02 03:04:05']);

    $this->assertParse(['%?', null], 'NULL');
    $this->assertParse(['%?', true], '?', [1]);
    $this->assertParse(['%?', false], '?', [0]);
    $this->assertParse(['%?', 'x'], '?', ['x']);
    $this->assertParse(['%?', 5], '?', [5]);
    $this->assertParse(['%?', new DateTime('2020-01-02 03:04:05')], '?', ['2020-01-02 03:04:05']);
    $this->assertParse(['%?', DB::parse('NOW() + %i', 1)], 'NOW() + ?', [1]);

    $this->assertParse(['%s', [1, 2]], '?', ['']);
  }

  // * %b and %c quote a name (doubling any quote character inside), and %b joins [db, table] with a dot
  // * %lb and %lc quote a list of names
  function test_02_names() {
    $char = ($this->db_type == 'pgsql') ? '"' : '`';

    $this->assertParse(['%b', 'users'], $this->q('users'));
    $this->assertParse(['%b', ['mydb', 'users']], $this->q('mydb') . '.' . $this->q('users'));
    $this->assertParse(['%c', 'my.col'], $this->q('my.col'));
    $this->assertParse(['%c', "a{$char}b"], $this->q("a{$char}{$char}b"));
    $this->assertParse(['%lc', ['a', 'b']], '(' . $this->q('a') . ',' . $this->q('b') . ')');
    $this->assertParse(['%lb', ['a', 'b']], '(' . $this->q('a') . ',' . $this->q('b') . ')');

    $this->assertThrows(function() { DB::parse('%c', ['a', 'b']); }, 'Invalid column/table name');
  }

  // * lists: %ls %li %ld %lt %l? (for IN), and %ll? (for multi-row VALUES)
  // * hashes: %hc (for SET), %ha and %ho (for WHERE)
  function test_03_lists_and_hashes() {
    $this->assertParse(['%ls', ['a', 1]], '(?,?)', ['a', '1']);
    $this->assertParse(['%li', ['1', 2.7]], '(?,?)', [1, 2]);
    $this->assertParse(['%ld', ['1.5', 2]], '(?,?)', [1.5, 2.0]);
    $this->assertParse(['%lt', ['2020-01-02 03:04:05']], '(?)', ['2020-01-02 03:04:05']);
    $this->assertParse(['%l?', [1, 'a', null, true]], '(?,?,NULL,?)', [1, 'a', 1]);
    $this->assertParse(['%ll?', [[1, 'a'], [2, null]]], '(?,?),(?,NULL)', [1, 'a', 2]);

    $a = $this->q('a');
    $b = $this->q('b');
    $this->assertParse(['%hc', ['a' => 1, 'b' => null]], "{$a}=?,{$b}=NULL", [1]);
    $this->assertParse(['%ha', ['a' => 1, 'b' => 'x']], "{$a}=? AND {$b}=?", [1, 'x']);
    $this->assertParse(['%ho', ['a' => 1, 'b' => 'x']], "{$a}=? OR {$b}=?", [1, 'x']);

    $this->assertThrows(function() { DB::parse('%li', 5); }, "Expected an array for arg 0 but didn't get one!");
    $this->assertThrows(function() { DB::parse('%ll?', [1, 2]); }, 'll? must be used with a list of assoc arrays');
    $this->assertThrows(function() { DB::parse('%ll?', [[1], [1, 2]]); }, 'all entries must be the same length');
  }

  // * args are numbered automatically, or explicitly (%i0) so they can be reused, or named (%i_name)
  // * automatic numbering ignores any explicit numbers
  // * the wrong number of args, a missing named arg, or mixing named and numbered args all throw
  function test_04_args() {
    $this->assertParse(['%s %i', 'a', 1], '? ?', ['a', 1]);
    $this->assertParse(['%i1 %s0 %i1', 'a', 2], '? ? ?', [2, 'a', 2]);
    $this->assertParse(['%ls %ls0 %s', ['a'], 'b'], '(?) (?) ?', ['a', 'a', 'b']);
    $this->assertParse(['%s1 %d0 %d', 1.5, 'x'], '? ? ?', ['x', 1.5, 1.5]);
    $this->assertParse(['%s_name %i_age %s_name', ['name' => 'n', 'age' => 3]], '? ? ?', ['n', 3, 'n']);

    $this->assertThrows(function() { DB::parse('%i %s', 1); }, 'Expected 2 args, but got 1!');
    $this->assertThrows(function() { DB::parse('%i %s', 1, 2, 3); }, 'Expected 2 args, but got 3!');
    $this->assertThrows(function() { DB::parse('%i_id %s_name', ['name' => 'x']); }, "Couldn't find named arg id!");
    $this->assertThrows(function() { DB::parse('%i_id', 1); },
      'If you use named args, you must pass an assoc array of args!');
    $this->assertThrows(function() { DB::parse('%i_id %s', ['id' => 1]); }, "You can't mix named and numbered args!");
  }

  // * %% is a literal %, but only when the query has args: a query without any is left as-is
  // * param_char can be changed, including to more than one character, and so can named_param_seperator
  // * with the separator, a "name" made of digits refers to a key in the args array
  function test_05_param_char() {
    $this->assertParse(['100%% %s', 'x'], '100% ?', ['x']);
    $this->assertParse(["LIKE 'a%'"], "LIKE 'a%'");
    $this->assertParse(["LIKE 'a%%'"], "LIKE 'a%%'");

    DB::$param_char = ':';
    DB::$named_param_seperator = ':';
    try {
      $this->assertParse([':s :i1 ::', 'a', 1], '? ? :', ['a', 1]);
      $this->assertParse([':s:name :i:age', ['name' => 'n', 'age' => 3]], '? ?', ['n', 3]);
      $this->assertParse([':c:0=:i:1', ['age', 10]], $this->q('age') . '=?', [10]);

      DB::$param_char = '###';
      DB::$named_param_seperator = '_';
      $this->assertParse(['###s ###li ######', 'a', [1, 2]], '? (?,?) ###', ['a', 1, 2]);
      $this->assert(DB::queryFirstField("SELECT name FROM users WHERE user_id=###i", 2) === 'Bart');
    } finally {
      DB::$param_char = '%';
      DB::$named_param_seperator = '_';
    }
  }
}
