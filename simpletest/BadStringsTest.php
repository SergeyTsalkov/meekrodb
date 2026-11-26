<?php
class BadStringsTest extends SimpleTest {
  // standard table whose name and columns need careful quoting
  public $table = 'weird %s:s_`"table';

  function __construct() {
    $this->resetDatabase();
  }

  // * columnList() of a table whose name needs quoting
  // * a binary blob (not valid utf8) can be stored with insert() or a query, read back, and matched with %s
  function test_1_blobs() {
    $columns = DB::columnList($this->table);
    $this->assert(array_keys($columns) === ['id', 'my.data', 'picture']);

    if ($this->db_type == 'sqlite') {
      $this->assert($columns['picture']['type'] === 'BLOB');
      $this->assert($columns['picture']['notnull'] === '0');
      $this->assert($columns['picture']['dflt_value'] === null);
    }
    else if ($this->db_type == 'pgsql') {
      $this->assert($columns['picture']['data_type'] === 'bytea');
      $this->assert($columns['picture']['is_nullable'] === 'YES');
      $this->assert($columns['picture']['column_default'] === null);
    }
    else {
      $this->assert($columns['picture']['type'] === 'blob');
      $this->assert($columns['picture']['null'] === 'YES');
      $this->assert($columns['picture']['key'] === '');
      $this->assert($columns['picture']['default'] === null);
      $this->assert($columns['picture']['extra'] === '');
    }

    $smile = file_get_contents(__DIR__ . '/smile1.jpg');
    DB::insert($this->table, ['picture' => $smile]);
    DB::query("INSERT INTO %b (%c) VALUES (%s)", $this->table, 'picture', $smile);

    $pictures = DB::queryFirstColumn("SELECT %c FROM %b ORDER BY %c", 'picture', $this->table, 'id');
    $this->assert($pictures === [$smile, $smile]);
    $this->assert(DB::queryFirstField("SELECT COUNT(*) FROM %b WHERE %c=%s", $this->table, 'picture', $smile) === '2');
  }

  // * a value full of things that look like placeholders is stored and matched as-is
  function test_2_placeholders_in_data() {
    $data = 'www.mysite.com/product?s=t-%s:s-%%3d:d%%3d%i&RCAID=24322';
    DB::insert($this->table, ['my.data' => $data]);
    $this->assert(DB::queryFirstField("SELECT COUNT(*) FROM %b WHERE %c=%s", $this->table, 'my.data', $data) === '1');
  }

  // * each line of badstrings.json can be stored and matched
  function test_3_bad_strings() {
    $strings = json_decode(file_get_contents(__DIR__ . '/badstrings.json'), true);
    foreach ($strings as $string) {
      DB::query("DELETE FROM %b", $this->table);
      DB::insert($this->table, ['my.data' => $string]);
      $this->assert(DB::queryFirstField("SELECT COUNT(*) FROM %b WHERE %c=%s", $this->table, 'my.data', $string) === '1');
    }
  }
}
