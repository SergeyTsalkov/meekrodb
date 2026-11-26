<?php
// the models use the standard tables (see resetDatabase() in test.php), whose primary keys all have
// different names (user_id, company_id, profile_id): an association that looks up the wrong key fails
class User extends MeekroORM {
  static $_columns = [
    'is_active' => ['type' => 'bool'],
    'settings' => ['type' => 'json'],
  ];
  static $_associations = [
    'Employer' => ['type' => 'belongs_to', 'foreign_key' => 'employer_id', 'class_name' => 'Company'],
    'Profile' => ['type' => 'has_one', 'foreign_key' => 'user_id'],
    'Membership' => ['type' => 'has_many', 'foreign_key' => 'user_id'],
  ];

  static function _scopes() {
    return [
      'active' => function() { return self::where(['is_active' => true]); },
      'teenager' => function() { return self::where('age>@i AND age<@i', 12, 20); },
      'age' => function($age) { return self::where('age=@i', $age); },
      'first_teenager' => function() { return self::scope('teenager')->order_by('user_id')->limit(1); },
    ];
  }
}

class Company extends MeekroORM {
  static $_tablename = 'companies';
  static $_associations = [
    'Employees' => ['type' => 'has_many', 'foreign_key' => 'employer_id', 'class_name' => 'User'],
  ];
}

class Profile extends MeekroORM {
  static $_columns = [
    'tags' => ['type' => 'json'],
  ];

  // a blank bio is stored as NULL, and NULL is read back as a blank bio: these per-field methods
  // take precedence over the ones for bio's type (string)
  function _marshal_field_bio($key, $value) {
    return ($value === '' || is_null($value)) ? null : $value;
  }
  function _unmarshal_field_bio($key, $value) {
    return is_null($value) ? '' : $value;
  }
}

class Membership extends MeekroORM {}

// primary key is (id, region): id is auto-increment, region has a default value
class Ticket extends MeekroORM {}

// the users table again, with no column types declared: they all come from the table itself
class UserRow extends MeekroORM {
  static $_tablename = 'users';
}

// the users table again, with every callback: each one is recorded in $log, and the one named
// in $fail_in returns false
class LoggedUser extends MeekroORM {
  static $_tablename = 'users';
  static $log = [];
  static $fail_in = null;

  // each entry is the callback name, the fields it was given (if any), and the transaction depth,
  // e.g. "_pre_save(name,age) d1"
  private function _log($callback, $fields = null) {
    if (is_array($fields)) $callback .= '(' . implode(',', $fields) . ')';
    self::$log[] = $callback . ' d' . DB::transactionDepth();
  }
  private function _result($callback) {
    return ($callback === self::$fail_in) ? false : null;
  }

  function _validate_name($name) { $this->_log("_validate_name=$name"); return $this->_result('_validate_name'); }
  function _validate_age($age) { $this->_log("_validate_age=$age"); return $this->_result('_validate_age'); }
  function _pre_save($fields) { $this->_log(__FUNCTION__, $fields); return $this->_result(__FUNCTION__); }
  function _post_save($fields) { $this->_log(__FUNCTION__, $fields); return $this->_result(__FUNCTION__); }
  function _pre_update($fields) { $this->_log(__FUNCTION__, $fields); return $this->_result(__FUNCTION__); }
  function _post_update($fields) { $this->_log(__FUNCTION__, $fields); return $this->_result(__FUNCTION__); }
  function _post_create($fields) { $this->_log(__FUNCTION__, $fields); return $this->_result(__FUNCTION__); }
  function _post_commit($fields) { $this->_log(__FUNCTION__, $fields); return $this->_result(__FUNCTION__); }
  function _pre_destroy() { $this->_log(__FUNCTION__); return $this->_result(__FUNCTION__); }
  function _post_destroy() { $this->_log(__FUNCTION__); return $this->_result(__FUNCTION__); }

  // new users start out active: a field that _pre_create makes dirty gets saved too
  function _pre_create($fields) {
    $this->_log(__FUNCTION__, $fields);
    $this->is_active = true;
    return $this->_result(__FUNCTION__);
  }
}

class BasicOrmTest extends SimpleTest {
  function __construct() {
    $this->resetDatabase();

    // the ORM's own queries must work whatever param_char is
    DB::$param_char = '@';
    DB::$nested_transactions = true;
  }

  function names($Users) {
    $names = [];
    foreach ($Users as $User) $names[] = $User->name;
    return $names;
  }

  // * Load() looks up by primary key, including a multi-column one
  // * Search() and SearchMany() take a hash to match, or a query
  // * a query can SELECT extra columns, which become plain properties
  function test_01_load_and_search() {
    $this->assert(User::Load(1)->name === 'Abe');
    $this->assert(User::Load(99) === null);
    $this->assert(Membership::Load(3, 2)->role === 'manager');
    $this->assertThrows(function() { Membership::Load(1); }, 'Load on Membership must be called with 2 parameters!');

    $this->assert(User::Search(['name' => 'Cleo'])->age === 41);
    $this->assert(User::Search("SELECT * FROM @b WHERE name=@s", 'users', 'Cleo')->age === 41);
    $this->assert(User::Search(['name' => 'nobody']) === null);
    $this->assert(User::Search() instanceof User);

    $this->assert($this->names(User::SearchMany(['age' => 15])) === ['Bart', "Dana O'Neil"]);
    $this->assert($this->names(User::SearchMany("SELECT * FROM users WHERE age>20 ORDER BY user_id")) === ['Abe', 'Cleo']);
    $this->assert(User::SearchMany(['name' => 'nobody']) === []);
    $this->assert(count(User::SearchMany()) === 4);

    $User = User::Search("SELECT *, age+1 AS next_age FROM users WHERE user_id=1");
    $this->assert($User->name === 'Abe');
    $this->assert($User->next_age === '31');
    $this->assert($User->dirtyfields() === []);
  }

  // * Save() on a new object inserts it, and fills in its auto-increment primary key
  // * destroy() deletes it
  function test_02_create_and_destroy() {
    $Company = new Company();
    $Company->name = 'Initech';
    $Company->Save();
    $this->assert($Company->company_id === 3);

    $User = new User();
    $User->name = 'Eve';
    $User->age = 22;
    $User->height = 1.7;
    $User->is_active = true;
    $User->employer_id = $Company->company_id;
    $User->Save();
    $this->assert($User->user_id === 5);

    $Membership = new Membership();
    $Membership->user_id = $User->user_id;
    $Membership->company_id = $Company->company_id;
    $Membership->role = 'founder';
    $Membership->Save();

    $User = User::Load(5);
    $this->assert($User->name === 'Eve');
    $this->assert($User->age === 22);
    $this->assert($User->height === 1.7);
    $this->assert($User->is_active === true);
    $this->assert($User->Employer->name === 'Initech');
    $this->assert(Membership::Load(5, 3)->role === 'founder');

    $User->Destroy();
    Membership::Load(5, 3)->Destroy();
    $Company->Destroy();
    $this->assert(User::Load(5) === null);
    $this->assert(Membership::Load(5, 3) === null);
    $this->assert(Company::Load(3) === null);
  }

  // * dirtyhash() has the changed fields, as they'll be saved
  // * update() saves only the fields it's given, leaving other changes unsaved
  // * reload() discards unsaved changes
  // * primary keys can't be changed, and fresh objects can't be updated or reloaded
  function test_03_dirty_and_update() {
    $User = User::Load(1);
    $this->assert($User->dirtyhash() === []);
    $User->is_active = false;
    $this->assert($User->dirtyhash() === ['is_active' => 0]);
    $User->is_active = true;
    $this->assert($User->dirtyhash() === []);

    $User->age = 31;
    $User->update('height', 2);
    $this->assert($User->dirtyfields() === ['age']);
    $User->update(['height' => 3, 'is_active' => false]);
    $this->assert($User->dirtyfields() === ['age']);

    $User->reload();
    $this->assert($User->age === 30);
    $this->assert($User->height === 3.0);
    $this->assert($User->is_active === false);
    $User->update(['height' => 1.8, 'is_active' => true]);

    $this->assertThrows(function() use ($User) { $User->user_id = 2; }, "Can't update primary key!");
    $this->assertThrows(function() { (new User())->update('age', 1); }, 'Unable to update(): record is fresh');
    $this->assertThrows(function() { (new User())->reload(); }, "Can't reload unsaved record!");
  }

  // * an empty object can be saved, getting the columns' defaults
  // * lock() reloads the object, like reload()
  function test_04_empty_and_lock() {
    $User = new User();
    $User->Save();
    $this->assert($User->name === '');
    $this->assert($User->age === 0);

    $User->age = 1;
    DB::startTransaction();
    $User->lock();
    $this->assert($User->age === 0);
    DB::commit();

    $User->Destroy();
    $this->assert(User::Search(['name' => '']) === null);
  }

  // * declared bool columns are loaded as true/false/null, and saved as 1/0/NULL
  // * undeclared column types come from the table: on pgsql, a BOOLEAN column is also a bool
  function test_05_types() {
    $this->assert(User::Load(2)->is_active === true);
    $this->assert(User::Load(3)->is_active === false);
    $this->assert(User::Load(3)->toHash()['is_active'] === false);
    $this->assert(User::Load(4)->is_active === null);

    $User = User::Load(3);
    $User->is_active = true;
    $User->Save();
    $this->assert(User::Load(3)->is_active === true);
    $User->is_active = false;
    $User->Save();
    $this->assert(User::Load(3)->is_active === false);

    $Row = UserRow::Load(1);
    $this->assert($Row->user_id === 1);
    $this->assert($Row->employer_id === 1);
    $this->assert($Row->name === 'Abe');
    $this->assert($Row->age === 30);
    $this->assert($Row->height === 1.8);
    $this->assert($Row->settings === '{"theme":"dark"}');
    $this->assert($Row->signed_up_at instanceof DateTime);
    $this->assert(UserRow::Load(4)->employer_id === null);

    $true = ($this->db_type == 'pgsql') ? true : 1;
    $false = ($this->db_type == 'pgsql') ? false : 0;
    $this->assert($Row->is_active === $true);
    $this->assert(UserRow::Load(3)->is_active === $false);
    $this->assert(count(UserRow::SearchMany(['is_active' => true])) === 2);

    $Row->is_active = false;
    $Row->Save();
    $this->assert(UserRow::Load(1)->is_active === $false);
    $Row->is_active = true;
    $Row->Save();
    $this->assert(UserRow::Load(1)->is_active === $true);
  }

  // * a timestamp column is loaded as a DateTime, and can be saved as a DateTime or a string
  // * saving null to a NOT NULL timestamp column saves 1970-01-03 00:00:00 instead
  function test_06_timestamp() {
    $zerodate = DateTime::createFromFormat('Y-m-d H:i:s', '1970-01-03 00:00:00');

    $User = User::Load(2);
    $this->assert($User->signed_up_at == $zerodate);
    $this->assert(User::Load(3)->signed_up_at == new DateTime('2020-01-02 03:04:05'));

    $User->signed_up_at = new DateTime();
    $User->Save();
    $User = User::Load(2);
    $this->assert($User->signed_up_at instanceof DateTime);
    $this->assert(abs($User->signed_up_at->getTimestamp() - time()) <= 1);

    $User->signed_up_at = null;
    $User->Save();
    $this->assert(User::Load(2)->signed_up_at == $zerodate);

    $User->signed_up_at = '2021-05-06 07:08:09';
    $User->Save();
    $this->assert(User::Load(2)->signed_up_at == new DateTime('2021-05-06 07:08:09'));
  }

  // * a json column is decoded when loaded and encoded when saved
  // * it can be changed in place, e.g. $User->settings['key'] = 'value'
  function test_07_json() {
    $User = User::Load(1);
    $this->assert($User->settings === ['theme' => 'dark']);
    $User->settings['font'] = 'serif';
    $User->Save();

    $User = User::Load(1);
    $this->assert($User->settings === ['theme' => 'dark', 'font' => 'serif']);
    $this->assert(DB::queryFirstField("SELECT settings FROM users WHERE user_id=1") === '{"theme":"dark","font":"serif"}');

    $User->settings = [1, 3, 5];
    $User->settings[] = 7;
    $User->Save();
    $this->assert(User::Load(1)->settings === [1, 3, 5, 7]);
  }

  // * nullable columns can be set to either NULL or empty string, and those are different
  // * NOT NULL columns get the type's empty value (0, '', etc.) when set to NULL
  function test_08_null() {
    $User = User::Load(4);
    $this->assert($User->nickname === 'Dee');

    foreach (['nickname', 'name', 'is_active', 'age', 'height'] as $field) $User->$field = '';
    $User->Save();
    $User = User::Load(4);
    $this->assert($User->nickname === '');
    $this->assert($User->name === '');
    $this->assert($User->is_active === false);
    $this->assert($User->age === 0);
    $this->assert($User->height === 0.0);

    foreach (['nickname', 'name', 'is_active', 'age', 'height'] as $field) $User->$field = null;
    $User->Save();
    $User = User::Load(4);
    $this->assert($User->nickname === null);
    $this->assert($User->name === '');
    $this->assert($User->is_active === null);
    $this->assert($User->age === 0);
    $this->assert($User->height === 0.0);

    // later tests need Dana back the way she was
    $User->update(['nickname' => 'Dee', 'name' => "Dana O'Neil", 'age' => 15, 'height' => 1.5]);
  }

  // * scopes combine; with order_by() and limit() (with an optional offset)
  // * order_by() and limit() after a scope has already run discard the cached results
  // * a scope is countable, iterable and indexable, but read-only
  function test_09_scopes() {
    $this->assert(count(User::all()) === 4);
    $this->assert($this->names(User::scope('active')->order_by('user_id')) === ['Abe', 'Bart']);
    $this->assert(User::scope('age', 30)->first()->name === 'Abe');

    $Empty = User::where('name=@s', 'nobody');
    $this->assert(count($Empty) === 0);
    $this->assert($Empty->first() === null);
    $this->assert($Empty->last() === null);

    $Teens = User::scope('teenager')->order_by('user_id');
    $this->assert($this->names($Teens) === ['Bart', "Dana O'Neil"]);
    $this->assert($Teens[0]->name === 'Bart');
    $this->assert($Teens->first()->name === 'Bart');
    $this->assert($Teens->last()->name === "Dana O'Neil");
    $this->assert($this->names(User::scope('active')->scope('teenager')) === ['Bart']);
    $this->assert($this->names(User::scope('teenager')->order_by(['user_id' => 'desc'])->limit(1)) === ["Dana O'Neil"]);
    $this->assert($this->names(User::all()->order_by('user_id')->limit(1, 2)) === ['Bart', 'Cleo']);
    $this->assert($this->names(User::scope('first_teenager')) === ['Bart']);

    $Teens->order_by(['user_id' => 'desc']);
    $this->assert($Teens[0]->name === "Dana O'Neil");
    $Teens->limit(1);
    $this->assert(count($Teens) === 1);

    $array = $Teens->toArray();
    $this->assert(is_array($array) && $array[0]->name === "Dana O'Neil");

    $this->assertThrows(function() { User::scope('nope'); }, 'Scope not available: nope');
    $this->assertThrows(function() use ($Teens) { $Teens[0] = null; }, 'Unable to edit scoped result set');
  }

  // * belongs_to, with a class_name and a foreign key named differently from their primary key
  // * has_many, with a class_name, scopes, and no results; also to a class with a multi-column primary key
  // * has_one, including with no result; either way, it's only looked up once
  function test_10_associations() {
    $Abe = User::Load(1);
    $this->assert($Abe->Employer->name === 'Acme');
    $this->assert(User::Load(3)->Employer->name === 'Globex');
    $this->assert(User::Load(4)->Employer === null);

    $Acme = Company::Load(1);
    $this->assert($this->names($Acme->Employees->order_by('user_id')) === ['Abe', 'Bart']);
    $this->assert($this->names($Acme->Employees->scope('teenager')) === ['Bart']);
    $this->assert($this->names(Company::Load(2)->Employees) === ['Cleo']);

    $this->assert(count($Abe->Membership) === 1);
    $this->assert($Abe->Membership[0]->role === 'owner');
    $this->assert(count(User::Load(4)->Membership) === 0);

    $this->assert($Abe->Profile->bio === 'Founder of Acme');
    $this->assert($Abe->Profile === $Abe->Profile);
    $this->assert(User::Load(3)->Profile->profile_id === 2);

    $Bart = User::Load(2);
    $queries = 0;
    DB::addHook('pre_run', function() use (&$queries) { $queries++; });
    try {
      $this->assert($Bart->Profile === null);
      $this->assert($Bart->Profile === null);
    } finally {
      DB::removeHooks('pre_run');
    }
    $this->assert($queries === 1);
  }

  // * the callbacks run in order, with the fields being saved, inside the transaction
  //   (except _post_commit, which runs after it)
  // * fields that a _pre callback changes get saved too
  // * update() runs the callbacks for the fields it saves; save(false) runs none
  function test_11_callbacks() {
    LoggedUser::$log = [];
    $User = new LoggedUser();
    $User->name = 'Zed';
    $User->Save();
    $this->assert(LoggedUser::$log === ['_validate_name=Zed d1', '_pre_save(name) d1', '_pre_create(name) d1',
      '_post_create(name,is_active) d1', '_post_save(name,is_active) d1', '_post_commit(name,is_active) d0']);
    $this->assert(DB::queryFirstField("SELECT name FROM users WHERE user_id=@i AND is_active=@?", $User->user_id, true) === 'Zed');

    LoggedUser::$log = [];
    $User->age = 50;
    $User->Save();
    $this->assert(LoggedUser::$log === ['_validate_age=50 d1', '_pre_save(age) d1', '_pre_update(age) d1',
      '_post_update(age) d1', '_post_save(age) d1', '_post_commit(age) d0']);

    LoggedUser::$log = [];
    $User->name = 'Zedd';
    $User->update('age', 51);
    $this->assert(LoggedUser::$log === ['_validate_age=51 d1', '_pre_save(age) d1', '_pre_update(age) d1',
      '_post_update(age) d1', '_post_save(age) d1', '_post_commit(age) d0']);
    $this->assert($User->dirtyfields() === ['name']);

    LoggedUser::$log = [];
    $User->Save(false);
    $this->assert(LoggedUser::$log === []);
    $this->assert(LoggedUser::Load($User->user_id)->name === 'Zedd');

    $User->Destroy();
    $this->assert(LoggedUser::$log === ['_pre_destroy d1', '_post_destroy d1']);
    $this->assert(LoggedUser::Load($User->user_id) === null);
  }

  // * a callback that returns false throws, and stops the save or destroy
  function test_12_callback_returns_false() {
    $count = function() { return DB::queryFirstField("SELECT COUNT(*) FROM users"); };

    foreach (['_validate_name', '_pre_save', '_pre_create'] as $callback) {
      LoggedUser::$fail_in = $callback;
      $User = new LoggedUser();
      $User->name = 'Zed';
      $this->assertThrows(function() use ($User) { $User->Save(); }, "$callback returned false");
      $this->assert($User->isFresh());
      $this->assert($count() == 4);
    }

    LoggedUser::$fail_in = '_pre_destroy';
    $this->assertThrows(function() { LoggedUser::Load(1)->Destroy(); }, '_pre_destroy returned false');
    $this->assert($count() == 4);
    LoggedUser::$fail_in = null;
  }

  // * save() runs inside a transaction if nested_transactions is on, so it's undone if a
  //   callback throws; $_use_transactions overrides that, either way
  function test_13_transactions() {
    LoggedUser::$fail_in = '_post_save';
    $cases = [
      // [nested_transactions, _use_transactions, is the row still there?]
      [true, null, false],
      [false, null, true],
      [false, true, false],
      [true, false, true],
    ];

    try {
      foreach ($cases as list($nested, $use_transactions, $is_saved)) {
        DB::$nested_transactions = $nested;
        $User = new LoggedUser();
        $User->_use_transactions = $use_transactions;
        $User->name = 'FrankieJoe';
        $this->assertThrows(function() use ($User) { $User->Save(); }, '_post_save returned false');
        $this->assert(DB::transactionDepth() === 0);

        $Saved = User::Search(['name' => 'FrankieJoe']);
        $this->assert(($Saved !== null) === $is_saved);
        if ($Saved) $Saved->Destroy();
      }
    } finally {
      LoggedUser::$fail_in = null;
      DB::$nested_transactions = true;
    }
  }

  // * isset() is true for fields that are set, and false for null ones
  // * unset() clears a field
  function test_14_isset_unset() {
    $User = User::Load(1);
    $this->assert(isset($User->name));
    $this->assert(isset($User->nickname));
    $this->assert(isset($User->Employer));
    $this->assert(!isset($User->nonsense));

    $User->nickname = null;
    $this->assert(!isset($User->nickname));
    unset($User->age);
    $this->assert($User->age === null);
  }

  // * a clone is fresh, with its primary key unset, so it saves as a new row
  // * it doesn't share DateTime objects or associations with the original
  // * it works with multi-column primary keys
  function test_15_clone() {
    $User = User::Load(1);
    $Clone = clone $User;
    $this->assert($Clone->isFresh());
    $this->assert(!$User->isFresh());
    $this->assert($Clone->user_id === null);
    $this->assert($User->user_id === 1);
    $this->assert($Clone->name === 'Abe');
    $this->assert($Clone->settings === $User->settings);
    $this->assert($Clone->signed_up_at == $User->signed_up_at);
    $this->assert($Clone->signed_up_at !== $User->signed_up_at);

    $Clone->name = 'Abe Clone';
    $Clone->Save();
    $this->assert(!$Clone->isFresh());
    $this->assert($Clone->user_id > 4);

    $Loaded = User::Load($Clone->user_id);
    $this->assert($Loaded->name === 'Abe Clone');
    $this->assert($Loaded->age === 30);
    $this->assert(User::Load(1)->name === 'Abe');
    $this->assert(count($User->Membership) === 1);
    $this->assert(count($Clone->Membership) === 0);
    $Loaded->Destroy();

    $Clone = clone Membership::Load(1, 1);
    $this->assert($Clone->user_id === null);
    $this->assert($Clone->company_id === null);
    $this->assert($Clone->role === 'owner');
    $Clone->user_id = 4;
    $Clone->company_id = 2;
    $Clone->Save();
    $this->assert(Membership::Load(4, 2)->role === 'owner');
    $this->assert(Membership::Load(1, 1)->role === 'owner');
    $Clone->Destroy();
  }

  // * saving a new object fills in the database's defaults
  // * where RETURNING is supported, that doesn't need a separate SELECT
  function test_16_save_without_reload() {
    $queries = [];
    DB::addHook('pre_run', function($hash) use (&$queries) {
      $queries[] = trim($hash['query']);
    });

    try {
      $User = new User();
      $User->name = 'Nora';
      $User->Save();
    } finally {
      DB::removeHooks('pre_run');
    }

    $selects = array_filter($queries, function($query) {
      return stripos($query, 'SELECT') === 0;
    });
    if (DB::supports('returning')) $this->assert(count($selects) === 0);
    else $this->assert(count($selects) > 0);

    $this->assert(!$User->isFresh());
    $this->assert($User->user_id > 4);
    $this->assert($User->age === 0);
    $this->assert($User->nickname === 'buddy');
    $this->assert($User->employer_id === null);
    $this->assert($User->is_active === null);
    $this->assert($User->signed_up_at instanceof DateTime);
    $this->assert($User->dirtyfields() === []);

    $Loaded = User::Load($User->user_id);
    $this->assert($Loaded->toHash() == $User->toHash());
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
      $this->assertThrows(function() use ($Ticket) { $Ticket->Save(); }, 'primary key(s) region were not set');
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

  // * a nullable json column stores null as SQL NULL, not as the string 'null'
  function test_18_json_null() {
    $Profile = Profile::Load(2);
    $this->assert($Profile->tags === null);

    $Profile->tags = ['fantasy', 'scifi'];
    $Profile->Save();
    $this->assert(Profile::Load(2)->tags === ['fantasy', 'scifi']);

    $Profile->tags = null;
    $Profile->Save();
    $this->assert(Profile::Load(2)->tags === null);
    $this->assert(DB::queryFirstField("SELECT tags FROM profiles WHERE profile_id=2") === null);
  }

  // * _marshal_field_* and _unmarshal_field_* methods override the column type's
  // * dirty tracking compares fields as they'd be saved: '' and null are the same for bio
  function test_19_field_marshal() {
    $Profile = Profile::Load(2);
    $this->assert($Profile->bio === '');
    $Profile->bio = null;
    $this->assert($Profile->dirtyfields() === []);

    $Profile->bio = 'Manager at Globex';
    $Profile->Save();
    $this->assert(DB::queryFirstField("SELECT bio FROM profiles WHERE profile_id=2") === 'Manager at Globex');

    $Profile->bio = '';
    $this->assert($Profile->dirtyhash() === ['bio' => null]);
    $Profile->Save();
    $this->assert(DB::queryFirstField("SELECT bio FROM profiles WHERE profile_id=2") === null);
    $this->assert(Profile::Load(2)->bio === '');
  }
}
