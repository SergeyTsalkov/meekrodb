<?php
class Person extends MeekroORM {
  static $_orm_tablename = 'persons';
}


class BasicOrmTest extends SimpleTest {
  function __construct() {
    foreach (DB::tableList() as $table) {
      DB::query("DROP TABLE %b", $table);
    }
  }

  function test_1_basic() {
    DB::query("
      CREATE TABLE persons (
        `id` int unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `name` varchar(255) NOT NULL,
        `age` int unsigned NOT NULL,
        `is_alive` tinyint(1) NOT NULL
      ) ENGINE = InnoDB
    ");

    $Person = new Person();
    $Person->name = 'Nick';
    $Person->age = 23;
    $Person->Save();

    $Person = Person::Load(1);
    $this->assert($Person->age === 23);
  }

}