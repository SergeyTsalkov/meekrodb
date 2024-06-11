<?php
class BadStringsTest extends SimpleTest {
  // * create a table with a space in the table name for storing binary blobs
  // * columnList() info about that table
  // * insert() a binary blob (not valid utf8)
  // * query() it back
  // * string match it with a query()
  function test_1_blobs() {
    DB::query($this->get_sql('create_store'));

    $columns = DB::columnList('store data');
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
    DB::insert('store data', array(
      'picture' => $smile,
    ));
    DB::query("INSERT INTO %b (picture) VALUES (%s)", 'store data', $smile);
    
    $getsmile = DB::queryFirstField("SELECT picture FROM %b WHERE id=1", 'store data');
    $getsmile2 = DB::queryFirstField("SELECT picture FROM %b WHERE id=2", 'store data');
    $this->assert($smile === $getsmile);
    $this->assert($smile === $getsmile2);

    $count = DB::queryFirstField("SELECT COUNT(*) FROM %b WHERE picture=%s", 'store data', $smile);
    $this->assert($count === '2');
  }


  // * create table with meekrodb meaningful placeholders in the name, and a . in a column name
  // * insert a row with placeholders in it, query it back
  function test_2_mdb_placeholders() {
    DB::query($this->get_sql('create_faketable'));
    $table = 'fake%s:s_`"table';

    $data = 'www.mysite.com/product?s=t-%s:s-%%3d:d%%3d%i&RCAID=24322';
    DB::insert($table, ['my.data' => $data]);

    $count = DB::queryFirstField("SELECT COUNT(*) FROM %b WHERE %c=%s", $table, 'my.data', $data);
    $this->assert($count === '1');
  }

  // * insert() lines one by one from badstrings.json, then queryFirstField() them back
  function test_3_mean_strings() {
    $table = 'fake%s:s_`"table';

    $strings = json_decode(file_get_contents(__DIR__ . '/badstrings.json'), true);
    foreach ($strings as $string) {
      DB::query("DELETE FROM %b", $table);
      DB::insert($table, ['my.data' => $string]);
      $count = DB::queryFirstField("SELECT COUNT(*) FROM %b WHERE %c=%s", $table, 'my.data', $string);
      $this->assert($count === '1');
    }
  }

}