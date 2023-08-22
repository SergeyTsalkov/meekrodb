<?php
use Carbon\Carbon;

class Person extends MeekroORM {
  static $_orm_tablename = 'persons';

  static $_orm_columns = array(
    'is_alive' => array('type' => 'bool'),
  );
}

// TODO: test saving an empty object
// TODO: do auto-increment without primary key (and vice-versa) columns still work?
// TODO: _pre callback adds a dirty field, make sure it saves and that _post callbacks include it in dirty list
// TODO: still works when Carbon is not available

class BasicOrmTest extends SimpleTest {
  function __construct() {
    foreach (DB::tableList() as $table) {
      DB::query("DROP TABLE %b", $table);
    }
  }

  // * can create basic Person objects and save them
  // * can use ::Load() to look up an object with a simple primary key
  // * can use ::Search() to look up an object by string match
  function test_1_basic() {
    DB::query("
      CREATE TABLE persons (
        `id` int unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `name` varchar(255) NOT NULL,
        `age` int unsigned NOT NULL,
        `last_happy_moment` timestamp NOT NULL,
        `is_alive` tinyint(1) NOT NULL
      ) ENGINE = InnoDB
    ");

    $Person = new Person();
    $Person->name = 'Nick';
    $Person->age = 23;
    $Person->nickname = null;
    $Person->Save();

    $Person = new Person();
    $Person->name = 'Frank';
    $Person->age = 47;
    $Person->Save();

    $Person = Person::Load(1);
    $this->assert($Person->age === 23);

    $Person = Person::Search(['name' => 'Frank']);
    $this->assert($Person->age === 47);
  }

  // * can search for Person by int value
  // * bool value is marshalled and unmarshalled correctly
  function test_2_bool() {
    $Person = Person::Search(['age' => 47]);
    $this->assert($Person->name === 'Frank');
    $this->assert($Person->is_alive === false);
    $Person->is_alive = true;
    $Person->Save();

    $Person = Person::Search(['name' => 'Frank']);
    $this->assert($Person->is_alive === true);
  }

  // * can load and save a Carbon timestamp
  function test_3_timestamp() {
    $Person = Person::Load(1);
    $Person->last_happy_moment = Carbon::now();
    $Person->Save();

    $Person = Person::Load(1);
    $this->assert($Person->last_happy_moment instanceof Carbon);
    $this->assert($Person->last_happy_moment->diffInSeconds() <= 1);

    $Person->last_happy_moment = null;
    $Person->Save();
  }

}