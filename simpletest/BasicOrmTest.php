<?php
use Carbon\Carbon;

class Person extends MeekroORM {
  static $_columns = [
    'is_alive' => ['type' => 'bool'],
    'is_male' => ['type' => 'bool'],
    'data' => ['type' => 'json'],
  ];
  static $_associations = [
    'House' => ['type' => 'has_many', 'foreign_key' => 'owner_id'],
    'Soul' => ['type' => 'has_one', 'foreign_key' => 'person_id'],
    'Employer' => ['type' => 'belongs_to', 'foreign_key' => 'employer_id', 'class_name' => 'Company'],
  ];

  static function _scopes() {
    return [
      'living' => function() { return self::where('is_alive=1'); },
      'male' => function() { return self::where('is_male=1'); },
      'female' => function() { return self::where('is_male=0'); },
      'teenager' => function() { return self::where('age>@i AND age<@i', 12, 20); },
      'age' => function($age) { return self::where('age=@i', $age); },
      'first_teenager' => function() { return self::scope('teenager')->order_by('id')->limit(1); }
    ];
  }

  static function tsdiff($t1, $t2) {
    $interval = $t1->diff($t2);
    return ($interval->days * 24 * 60 * 60) +
      ($interval->h * 60 * 60) +
      ($interval->i * 60) +
      $interval->s +
      ($interval->f);
  }

  function _pre_save() {
    if ($this->name == 'Kevin') {
      if ($this->age == 53) {
        return false;
      }

      $this->age = 28;
    }
  }
}

class Person2 extends MeekroORM {
  static $_tablename = 'persons';

  function _post_save() {
    return false;
  }
}

class House extends MeekroORM {
  static function _scopes() {
    return [
      'over' => function($over) { return self::where('price >= @i', $over); },
    ];
  }
}

class Soul extends MeekroORM {
  public $tmp;

  static $_columns = [
    'heaven_bound' => ['type' => 'bool'],
  ];
}

class Company extends MeekroORM {
  static $_tablename = 'companies';

}

class Assignment extends MeekroORM {

}

// primary key is (id, region): id is auto-increment, region has a default value
class Ticket extends MeekroORM {

}

// Author, Book and Bio all have differently-named primary keys (author_id, book_id, bio_id)
// and Book's foreign key (writer_id) doesn't match Author's primary key name
class Author extends MeekroORM {
  static $_associations = [
    'Book' => ['type' => 'has_many', 'foreign_key' => 'writer_id'],
    'Bio' => ['type' => 'has_one', 'foreign_key' => 'author_id'],
  ];
}

class Book extends MeekroORM {
  static $_associations = [
    'Writer' => ['type' => 'belongs_to', 'foreign_key' => 'writer_id', 'class_name' => 'Author'],
  ];
}

class Bio extends MeekroORM {

}

class BasicOrmTest extends SimpleTest {
  function __construct() {
    // make sure we run the struct discovery code for every database type we test on
    Person::_orm_struct_reset();
    DB::$param_char = '@';
    DB::$nested_transactions = true;

    foreach (DB::tableList() as $table) {
      DB::query("DROP TABLE @b", $table);
    }
  }

  // * can create basic Person objects and save them
  // * can use Load() to look up an object with a simple primary key
  // * can use Search() and SearchMany() with either hash or query match
  // * can Load() with multiple primary keys
  function test_01_basic() {
    DB::query($this->get_sql('create_persons'));
    DB::query($this->get_sql('create_houses'));
    DB::query($this->get_sql('create_souls'));
    DB::query($this->get_sql('create_companies'));
    DB::query($this->get_sql('create_assignments'));

    $Person = new Person();
    $Person->name = 'Nick';
    $Person->age = 23;
    $Person->height = 1.7;
    $Person->favorite_color = 'blue';
    $Person->favorite_animaniacs = 'Yakko';
    $Person->is_alive = true;
    $Person->is_male = true;
    $Person->Save();

    $House = new House();
    $House->owner_id = $Person->id;
    $House->address = '3344 Cedar Road';
    $House->sqft = 1340;
    $House->price = 500;
    $House->Save();

    $House = new House();
    $House->owner_id = $Person->id;
    $House->address = '233 South Wacker Dr';
    $House->sqft = 2250;
    $House->price = 1200;
    $House->Save();

    $Soul = new Soul();
    $Soul->person_id = $Person->id;
    $Soul->heaven_bound = true;
    $Soul->Save();

    $Company = new Company();
    $Company->name = 'Acme Shoe Co';
    $Company->Save();
    $Person->employer_id = $Company->id;
    $Person->Save();

    $Assignment = new Assignment();
    $Assignment->person_id = $Person->id;
    $Assignment->company_id = $Company->id;
    $Assignment->name = 'Big Boss';
    $Assignment->Save();

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

    $Company = new Company();
    $Company->name = 'Dead Company Walking';
    $Company->Save();
    $Person->employer_id = $Company->id;
    $Person->Save();

    $Assignment = new Assignment();
    $Assignment->person_id = $Person->id;
    $Assignment->company_id = $Company->id;
    $Assignment->name = 'Trash Guy';
    $Assignment->Save();

    $Person = Person::Load(1);
    $this->assert($Person->age === 23);

    $Person = Person::Search(['name' => 'Gavin']);
    $this->assert($Person->age === 15);

    $Person = Person::Search("SELECT * FROM @b WHERE name=@s", 'persons', 'Gavin');
    $this->assert($Person->age === 15);

    $Persons = Person::SearchMany(['name' => 'Gavin']);
    $this->assert(count($Persons) === 1);
    $this->assert($Persons[0]->age === 15);

    $Persons = Person::SearchMany("SELECT * FROM @b WHERE age>12 AND age<20 ORDER BY id", 'persons');
    $this->assert(count($Persons) === 2);
    $this->assert($Persons[0]->name === 'Ellie');

    $Assignment = Assignment::Load(1, 1);
    $this->assert($Assignment->name === 'Big Boss');

    $Person = Person::Search(['name' => 'Abigail']);
    $Assignment = Assignment::Load($Person->id, $Person->employer_id);
    $this->assert($Assignment->name === 'Trash Guy');

    // ORDER is not guaranteed here
    $Person = Person::Search();
    $this->assert($Person instanceof Person);

    $Persons = Person::SearchMany();
    $this->assert(count($Persons) === 4);
  }

  // * can SELECT with extra, virtual columns
  function test_02_extra() {
    $Person = Person::Search("SELECT *, age+1 as nextage FROM persons WHERE id=1");
    $this->assert($Person->name === 'Nick');
    $this->assert($Person->nextage === '24');
  }

  // * update() works, both with a hash and with a single key/value combo
  function test_03_update() {
    $Person = Person::Load(1);
    $this->assert($Person->age === 23);

    $Person->age = 24;
    $Person->update('height', 2);
    $this->assert($Person->dirtyfields() == ['age']);
    $Person->update(['height' => 3, 'is_male' => false]);
    $this->assert($Person->dirtyfields() == ['age']);

    $Person->reload();
    $this->assert($Person->age === 23);
    $this->assert($Person->is_male === false);
    $this->assert($Person->height === 3.0);

    $Person->update(['height' => 1.7, 'is_male' => true]);
  }

  // * can save an empty record
  // * can reload() an object to undo pending changes
  // * can destroy() an object
  function test_04_empty() {
    $Person = new Person();
    $Person->Save();

    $Person = Person::Search(['name' => '']);
    $this->assert($Person->age === 0);
    $Person->age = 1;
    $this->assert($Person->age === 1);
    $Person->reload();
    $this->assert($Person->age === 0);

    $Person->Destroy();
    $Person = Person::Search(['name' => '']);
    $this->assert($Person === null);
  }

  // * _pre_save returning false throws exception
  // * _pre_save can add a dirty field
  function test_05_presave() {
    $error = '';
    try {
      $Person = new Person();
      $Person->name = 'Kevin';
      $Person->age = 53;
      $Person->Save();
    } catch (MeekroORMException $e) {
      $error = $e->getMessage();
    }
    $this->assert($error == '_pre_save returned false');

    $Person = new Person();
    $Person->name = 'Kevin';
    $Person->Save();
    $Person->reload();
    $this->assert($Person->age === 28);
  }

  // * can search for Person by int value
  // * bool value is marshalled and unmarshalled correctly
  // * toHash() works
  function test_06_bool() {
    $Person = Person::Search(['age' => 17]);
    $this->assert($Person->name === 'Ellie');
    $this->assert($Person->is_alive === true);
    $this->assert($Person->is_male === false);
    $this->assert($Person->toHash()['is_male'] === false);
    $Person->is_alive = false;
    $Person->Save();

    $Person = Person::Search(['age' => 17]);
    $this->assert($Person->name === 'Ellie');
    $this->assert($Person->is_alive === false);
    $this->assert($Person->is_male === false);
    $Person->is_alive = true;
    $Person->Save();
  }

  // * can use Search() to load entry with extra fields
  // * can use dirtyhash() to see modified properties
  function test_07_extravars() {
    $Person = Person::Search("SELECT *, age+1 AS nextyear FROM persons WHERE age = 17");
    $this->assert($Person->name === 'Ellie');
    $this->assert($Person->is_alive === true);
    $this->assert($Person->nextyear === '18');
    $Person->is_alive = false;

    $dirty = $Person->dirtyhash();
    $this->assert(count($dirty) === 1);
    $this->assert(array_keys($dirty)[0] === 'is_alive');
    $this->assert(array_values($dirty)[0] === 0);
    
    $Person->Save();
    $Person->is_alive = true;
    $Person->Save();
  }

  // * can load and save a timestamp
  function test_08_timestamp() {
    $zerodate = DateTime::createFromFormat('Y-m-d H:i:s', '1970-01-03 00:00:00');

    $Person = Person::Load(1);
    $this->assert($Person->last_happy_moment == $zerodate);
    $Person->last_happy_moment = new DateTime();
    $Person->Save();

    $Person = Person::Load(1);
    $this->assert($Person->last_happy_moment instanceof DateTime);

    $diff = Person::tsdiff(new DateTime(), $Person->last_happy_moment);
    $this->assert($diff <= 1);

    $Person->last_happy_moment = null;
    $Person->Save();
    $Person = Person::Load(1);
    $this->assert($Person->last_happy_moment == $zerodate);
    $Person->last_happy_moment = '1970-01-03 00:00:00';
    $Person->Save();
    $Person = Person::Load(1);
    $this->assert($Person->last_happy_moment == $zerodate);
  }

  // * json type can be used/loaded/saved
  // * json type can be appended to with "var[] = val"
  function test_09_json() {
    $Person = Person::Load(1);
    $Person->data = [1,3,5];
    $Person->Save();

    $Person = Person::Load(1);
    $this->assert($Person->data === [1,3,5]);
    $Person->data[] = 7;
    $Person->Save();

    $Person = Person::Load(1);
    $this->assert($Person->data === [1,3,5,7]);
  }

  // * NULL values can be set to either NULL, or empty string, and those are different
  // * NOT NULL values will be set to empty string (or equivalent) when we try to null them
  function test_10_null() {
    $Person = Person::Search(['name' => 'Nick']);
    $this->assert($Person->favorite_color === 'blue');
    $this->assert($Person->favorite_animaniacs === 'Yakko');
    $this->assert($Person->is_alive === true);
    $this->assert($Person->age === 23);
    $this->assert($Person->height === 1.7);
    $Person->favorite_color = '';
    $Person->favorite_animaniacs = '';
    $Person->is_alive = '';
    $Person->age = '';
    $Person->height = '';
    $Person->Save();
    $Person = Person::Search(['name' => 'Nick']);
    $this->assert($Person->favorite_color === '');
    $this->assert($Person->favorite_animaniacs === '');
    $this->assert($Person->is_alive === false);
    $this->assert($Person->age === 0);
    $this->assert($Person->height === 0.0);
    $Person->favorite_color = null;
    $Person->favorite_animaniacs = null;
    $Person->is_alive = null;
    $Person->age = null;
    $Person->height = null;
    $Person->Save();
    $Person = Person::Search(['name' => 'Nick']);
    $this->assert($Person->favorite_color === null);
    $this->assert($Person->favorite_animaniacs === '');
    $this->assert($Person->is_alive === null);
    $this->assert($Person->age === 0);
    $this->assert($Person->height === 0.0);
    $Person->is_alive = true;
    $Person->favorite_color = 'blue';
    $Person->favorite_animaniacs = 'Yakko';
    $Person->age = 23;
    $Person->height = 1.7;
    $Person->Save();
  }

  function test_11_scope() {
    $All = Person::all();
    $this->assert(count($All) === 5);

    $Living = Person::scope('living');
    $this->assert(count($Living) === 3);

    $EmptyScope = Person::where('name=@s', 'abcde');
    $this->assert(count($EmptyScope) === 0);
    $this->assert($EmptyScope->first() === null);
    $this->assert($EmptyScope->last() === null);

    $Person = Person::scope('age', 23);
    $this->assert(count($Person) === 1);
    $this->assert($Person->first()->name === 'Nick');

    $LivingTeens = Person::scope('living')->scope('teenager')->order_by('id');
    $this->assert(count($LivingTeens) === 2);
    $this->assert($LivingTeens[0]->name === 'Ellie');
    $this->assert($LivingTeens->first()->name === 'Ellie');
    $this->assert($LivingTeens->last()->name === 'Gavin');

    $LivingTeens = Person::scope('living')->scope('teenager')->order_by('id')->limit(1);
    $this->assert(count($LivingTeens) === 1);
    $this->assert($LivingTeens[0]->name === 'Ellie');

    $LivingTeens = Person::scope('living')->scope('teenager')->order_by(['id' => 'desc'])->limit(1);
    $this->assert(count($LivingTeens) === 1);
    $this->assert($LivingTeens[0]->name === 'Gavin');

    // order_by() and limit() after the scope has already run discard the cached results
    $LivingTeens = Person::scope('living')->scope('teenager')->order_by('id');
    $this->assert($LivingTeens[0]->name === 'Ellie');
    $LivingTeens->order_by(['id' => 'desc']);
    $this->assert($LivingTeens[0]->name === 'Gavin');
    $LivingTeens->limit(1);
    $this->assert(count($LivingTeens) === 1);

    $FirstTeenager = Person::scope('first_teenager');
    $this->assert(count($FirstTeenager) === 1);
    $this->assert($FirstTeenager[0]->name === 'Ellie');

    $array = $FirstTeenager->toArray();
    $this->assert(!is_array($FirstTeenager));
    $this->assert(is_array($array));
    $this->assert($array[0]->name === 'Ellie');
  }

  // * has_many: works with scoping
  // * has_many: no results means empty scope/array
  // * has_one: assocs properly loaded only once
  // * belongs_to: class_name works
  function test_12_assoc() {
    $Person = Person::Search(['name' => 'Nick']);
    $Houses = $Person->House->order_by('price');

    $this->assert(count($Houses) === 2);
    $this->assert($Houses[0]->sqft === 1340);

    $Houses = $Person->House->scope('over', 1000);
    $this->assert(count($Houses) === 1);
    $this->assert($Houses[0]->sqft === 2250);

    $Person2 = Person::Search(['name' => 'Ellie']);
    $this->assert(count($Person2->House) === 0);

    $this->assert($Person->Soul->heaven_bound === true);
    
    $Person->Soul->tmp = true;
    $this->assert($Person->Soul->tmp === true);

    $this->assert($Person->Employer->name === 'Acme Shoe Co');
  }

  function test_13_transactions() {
    DB::$nested_transactions = true;
    
    try {
      $Person = new Person2();
      $Person->name = 'FrankieJoe';
      $Person->Save();
    } catch (Exception $e) {}

    $Search = Person::Search(['name' => 'FrankieJoe']);
    $this->assert($Search === null);

    DB::$nested_transactions = false;
    try {
      $Person = new Person2();
      $Person->name = 'FrankieJoe';
      $Person->Save();
    } catch (Exception $e) {}
    $Search = Person::Search(['name' => 'FrankieJoe']);
    $this->assert($Search instanceof Person);
    
    $Search->Destroy();
    $Search = Person::Search(['name' => 'FrankieJoe']);
    $this->assert($Search === null);

    DB::$nested_transactions = true;
  }

  // * isset() returns true for set ORM fields, false for null ones
  // * unset() clears a field so getter returns null
  function test_14_isset_unset() {
    $Person = Person::Search(['name' => 'Nick']);
    $this->assert(isset($Person->name));
    $this->assert(isset($Person->favorite_color));

    $Person->favorite_color = null;
    $this->assert(!isset($Person->favorite_color));

    unset($Person->age);
    $this->assert($Person->age === null);
  }

  // * clone produces a fresh object with primary key unset, which saves as a new row
  // * clone doesn't share DateTime objects with the original
  // * clone works with multi-column primary keys
  function test_15_clone() {
    $Person = Person::Search(['name' => 'Nick']);
    $original_id = $Person->id;
    $original_moment = $Person->last_happy_moment;

    $Clone = clone $Person;
    $this->assert($Clone->isFresh());
    $this->assert(!$Person->isFresh());
    $this->assert($Clone->id === null);
    $this->assert($Person->id === $original_id);
    $this->assert($Clone->name === 'Nick');
    $this->assert($Clone->age === 23);
    $this->assert($Clone->data === $Person->data);
    $this->assert($Clone->last_happy_moment == $original_moment);
    $this->assert($Clone->last_happy_moment !== $Person->last_happy_moment);

    $Clone->name = 'Nick Clone';
    $Clone->Save();
    $this->assert(!$Clone->isFresh());
    $this->assert($Clone->id && $Clone->id != $original_id);

    $Loaded = Person::Load($Clone->id);
    $this->assert($Loaded->name === 'Nick Clone');
    $this->assert($Loaded->age === 23);
    $this->assert($Loaded->favorite_color === 'blue');
    $this->assert(Person::Load($original_id)->name === 'Nick');

    // the clone's House association refers to the clone, not the original
    $this->assert(count($Person->House) === 2);
    $this->assert(count($Clone->House) === 0);
    $Loaded->Destroy();

    $Assignment = Assignment::Load(1, 1);
    $Clone = clone $Assignment;
    $this->assert($Clone->person_id === null);
    $this->assert($Clone->company_id === null);
    $this->assert($Clone->name === 'Big Boss');
    $Clone->person_id = 2;
    $Clone->company_id = 1;
    $Clone->Save();

    $Loaded = Assignment::Load(2, 1);
    $this->assert($Loaded->name === 'Big Boss');
    $this->assert(Assignment::Load(1, 1)->name === 'Big Boss');
    $Loaded->Destroy();
  }

  // * saving a new object fills in database defaults
  // * where RETURNING is supported, saving a new object doesn't need a reload() SELECT
  function test_16_save_without_reload() {
    $queries = [];
    $hook_id = DB::addHook('pre_run', function($hash) use (&$queries) {
      $queries[] = trim($hash['query']);
    });

    try {
      $Person = new Person();
      $Person->name = 'Nora';
      $Person->Save();
    } finally {
      DB::removeHook('pre_run', $hook_id);
    }

    $selects = array_filter($queries, function($query) {
      return stripos($query, 'SELECT') === 0;
    });
    if (DB::supports('returning')) $this->assert(count($selects) === 0);
    else $this->assert(count($selects) > 0);

    $this->assert(!$Person->isFresh());
    $this->assert($Person->id > 0);
    $this->assert($Person->age === 0);
    $this->assert($Person->favorite_animaniacs === '');
    $this->assert($Person->favorite_color === null);
    $this->assert($Person->last_happy_moment instanceof DateTime);
    $this->assert($Person->dirtyfields() === []);

    $Loaded = Person::Load($Person->id);
    $this->assert($Loaded->name === 'Nora');
    $this->assert($Loaded->toHash() == $Person->toHash());
    $Loaded->Destroy();
  }

  // * where RETURNING is supported, a split primary key (auto-increment + default value) works
  // * where it isn't, saving throws a clear error and the insert is rolled back
  function test_17_split_primary_key() {
    // sqlite only allows AUTOINCREMENT on a single-column primary key
    if ($this->db_type == 'sqlite') return;
    DB::query($this->get_sql('create_tickets'));

    $Ticket = new Ticket();
    $Ticket->name = 'First';

    if (! DB::supports('returning')) {
      $error = '';
      try {
        $Ticket->Save();
      } catch (MeekroORMException $e) {
        $error = $e->getMessage();
      }
      $this->assert(substr_count($error, 'primary key(s) region were not set') === 1);
      $this->assert(DB::queryFirstField("SELECT COUNT(*) FROM tickets") === '0');
      return;
    }

    $Ticket->Save();
    $this->assert(!$Ticket->isFresh());
    $this->assert($Ticket->id > 0);
    $this->assert($Ticket->region === 1);

    $Ticket2 = new Ticket();
    $Ticket2->name = 'Second';
    $Ticket2->region = 2;
    $Ticket2->Save();
    $this->assert($Ticket2->region === 2);
    $this->assert($Ticket2->id !== $Ticket->id);

    $this->assert(Ticket::Load($Ticket->id, 1)->name === 'First');
    $this->assert(Ticket::Load($Ticket2->id, 2)->name === 'Second');

    // now that the full primary key is known, updates work too
    $Ticket->name = 'First Updated';
    $Ticket->Save();
    $this->assert(Ticket::Load($Ticket->id, 1)->name === 'First Updated');
  }

  // * associations work when the primary keys of the two classes have different names
  // * belongs_to works when our foreign key name doesn't match their primary key name
  function test_18_assoc_primary_key_names() {
    DB::query($this->get_sql('create_authors'));
    DB::query($this->get_sql('create_books'));
    DB::query($this->get_sql('create_bios'));

    $Author = new Author();
    $Author->name = 'Ursula';
    $Author->Save();

    $Author2 = new Author();
    $Author2->name = 'Terry';
    $Author2->Save();
    $this->assert($Author->author_id !== $Author2->author_id);

    // book_id values won't line up with author_id values, so a lookup on the wrong key would be caught
    foreach (['Filler 1', 'Filler 2', 'Filler 3'] as $title) {
      $Book = new Book();
      $Book->writer_id = $Author2->author_id;
      $Book->title = $title;
      $Book->Save();
    }
    foreach (['Earthsea', 'The Dispossessed'] as $title) {
      $Book = new Book();
      $Book->writer_id = $Author->author_id;
      $Book->title = $title;
      $Book->Save();
    }

    $Bio = new Bio();
    $Bio->author_id = $Author->author_id;
    $Bio->summary = 'Wrote about wizards';
    $Bio->Save();

    $Author = Author::Load($Author->author_id);

    // has_many
    $Books = $Author->Book->order_by('book_id');
    $this->assert(count($Books) === 2);
    $this->assert($Books[0]->title === 'Earthsea');
    $this->assert($Books[1]->title === 'The Dispossessed');
    $this->assert(count($Author2->Book) === 3);

    // has_one
    $this->assert($Author->Bio->summary === 'Wrote about wizards');
    $this->assert($Author2->Bio === null);

    // has_one with no match: the null result is cached, so the lookup query runs only once
    $Author2 = Author::Load($Author2->author_id);
    $queries = 0;
    $hook_id = DB::addHook('pre_run', function() use (&$queries) { $queries++; });
    try {
      $this->assert($Author2->Bio === null);
      $this->assert($Author2->Bio === null);
    } finally {
      DB::removeHook('pre_run', $hook_id);
    }
    $this->assert($queries === 1);

    // belongs_to
    $this->assert($Books[0]->Writer->name === 'Ursula');
  }

}