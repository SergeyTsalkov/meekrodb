<?php
use Carbon\Carbon;

class Person extends MeekroORM {
  static $_orm_tablename = 'persons';
  static $_orm_columns = [
    'is_alive' => ['type' => 'bool'],
    'is_male' => ['type' => 'bool'],
  ];

  static function _orm_scopes() {
    return [
      'living' => function() { return self::where('is_alive=1'); },
      'male' => function() { return self::where('is_male=1'); },
      'female' => function() { return self::where('is_male=0'); },
      'teenager' => function() { return self::where('age<20'); },
    ];
  }
}

// TODO: setting properties that don't correspond to a database field still works in php8+
// TODO: do we need ArrayAccess? can it be made to coexist in php7 and php8?
// TODO: test saving an empty object
// TODO: do auto-increment without primary key (and vice-versa) columns still work?
// TODO: _pre callback adds a dirty field, make sure it saves and that _post callbacks include it in dirty list
// TODO: still works when Carbon is not available
// TODO: test reload()
// TODO: computed vars?
// TODO: test toHash()

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
        `height` double unsigned NOT NULL,
        `favorite_color` varchar(255) NULL,
        `favorite_animaniacs` varchar(255) NOT NULL DEFAULT '',
        `last_happy_moment` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
        `is_male` tinyint(1) NOT NULL DEFAULT 0,
        `is_alive` tinyint(1) NULL
      ) ENGINE = InnoDB
    ");

    $Person = new Person();
    $Person->name = 'Nick';
    $Person->age = 23;
    $Person->height = 1.7;
    $Person->favorite_color = 'blue';
    $Person->favorite_animaniacs = 'Yakko';
    $Person->is_alive = true;
    $Person->is_male = true;
    $Person->Save();

    $Person = new Person();
    $Person->name = 'Ellie';
    $Person->age = 17;
    $Person->height = 1.2;
    $Person->is_alive = true;
    $Person->is_male = false;
    $Person->Save();

    $Person = new Person();
    $Person->name = 'Gavin';
    $Person->age = 15;
    $Person->height = 1.85;
    $Person->is_alive = true;
    $Person->is_male = false;
    $Person->Save();

    $Person = new Person();
    $Person->name = 'Abigail';
    $Person->age = 29;
    $Person->height = 1.2;
    $Person->is_alive = false;
    $Person->is_male = false;
    $Person->Save();

    $Person = Person::Load(1);
    $this->assert($Person->age === 23);

    $Person = Person::Search(['name' => 'Gavin']);
    $this->assert($Person->age === 15);
  }

  // * can search for Person by int value
  // * bool value is marshalled and unmarshalled correctly
  function test_2_bool() {
    $Person = Person::Search(['age' => 17]);
    $this->assert($Person->name === 'Ellie');
    $this->assert($Person->is_alive === true);
    $this->assert($Person->is_male === false);
    $Person->is_alive = false;
    $Person->Save();

    $Person = Person::Search(['age' => 17]);
    $this->assert($Person->name === 'Ellie');
    $this->assert($Person->is_alive === false);
    $this->assert($Person->is_male === false);
    $Person->is_alive = true;
    $Person->Save();
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

  // * NULL values can be set to either NULL, or empty string, and those are different
  // * NOT NULL values will be set to empty string (or equivalent) when we try to null them
  // TODO: test this with int, double
  function test_4_null() {
    $Person = Person::Search(['name' => 'Nick']);
    $this->assert($Person->favorite_color === 'blue');
    $this->assert($Person->favorite_animaniacs === 'Yakko');
    $this->assert($Person->is_alive === true);
    $Person->favorite_color = '';
    $Person->favorite_animaniacs = '';
    $Person->is_alive = '';
    $Person->Save();
    $Person = Person::Search(['name' => 'Nick']);
    $this->assert($Person->favorite_color === '');
    $this->assert($Person->favorite_animaniacs === '');
    $this->assert($Person->is_alive === false);
    $Person->favorite_color = null;
    $Person->favorite_animaniacs = null;
    $Person->is_alive = null;
    $Person->Save();
    $Person = Person::Search(['name' => 'Nick']);
    $this->assert($Person->favorite_color === null);
    $this->assert($Person->favorite_animaniacs === '');
    $this->assert($Person->is_alive === null);
    $Person->favorite_color = 'blue';
    $Person->favorite_animaniacs = 'Yakko';
    $Person->Save();
  }

  function test_5_scope() {
    $Living = Person::scope('living');
    foreach($Living as $Person) {
      echo $Person->name . "\n";
    }
  }

}