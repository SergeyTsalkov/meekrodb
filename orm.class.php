<?php
#[\AllowDynamicProperties]
abstract class MeekroORM {
  // INTERNAL -- DO NOT TOUCH
  private $_orm_row = []; // processed hash
  private $_orm_row_orig = []; // original raw hash from database
  private $_orm_assoc_load = [];
  private $_orm_is_fresh = true;
  private static $_orm_struct = [];

  // (OPTIONAL) SET IN INHERITING CLASS
  protected static $_tablename = null;
  protected static $_associations = [];
  protected static $_columns = [];
  public $_use_transactions = null;

  // -------------- TABLE AND DATABASE HELPERS
  private static function _orm_struct() {
    $table_name = static::_tablename();
    if (! array_key_exists($table_name, self::$_orm_struct)) {
      self::$_orm_struct[$table_name] = new MeekroORMTable(static::class);
    }
    return self::$_orm_struct[$table_name];
  }

  public static function _orm_struct_reset() {
    self::$_orm_struct = [];
  }

  public static function _tablename() {
    if (static::$_tablename) return static::$_tablename;
    
    $table = strtolower(static::class);
    if (substr($table, -1) != 's') $table .= 's';
    return $table;
  }

  public static function _meekrodb() { return DB::getMDB(); }

  // use for internal queries, since we don't know what the user's param_char is
  public static function _orm_query($func_name, ...$args) {
    $mdb = static::_meekrodb();
    $old_char = $mdb->param_char;
    $mdb->param_char = ':';
    try {
      return $mdb->$func_name(...$args);
    } finally {
      $mdb->param_char = $old_char;
    }
  }

  // -------------- COLUMN INFO
  private static function _orm_colinfo($column, $type) {
    if (! is_array(static::$_columns)) return;
    if (! array_key_exists($column, static::$_columns)) return;

    $info = static::$_columns[$column];
    return $info[$type] ?? null;
  }

  private static function _orm_coltype($column) {
    if ($type = static::_orm_colinfo($column, 'type')) {
      return $type;
    }
    return static::_orm_struct()->column_type($column);
  }

  // -------------- TRANSACTIONS
  // run $fn inside a transaction (if enabled): commit if it returns, roll back if it throws
  // $_use_transactions overrides the default, which is to use transactions only if nested_transactions is on
  private function _orm_in_transaction(callable $fn) {
    $mdb = static::_meekrodb();
    $use_transactions = is_bool($this->_use_transactions)
      ? $this->_use_transactions
      : $mdb->nested_transactions;

    if (! $use_transactions) return $fn();

    $mdb->startTransaction();
    $have_committed = false;

    try {
      $result = $fn();
      $mdb->commit();
      $have_committed = true;
      return $result;
    } finally {
      if (! $have_committed) $mdb->rollback();
    }
  }

  // -------------- STATE AND DIRTY TRACKING
  public function isFresh() {
    return $this->_orm_is_fresh;
  }

  public function dirtyhash() {
    $hash = [];
    foreach ($this->toRawHash() as $key => $value) {
      if (!array_key_exists($key, $this->_orm_row_orig) || $value !== $this->_orm_row_orig[$key]) {
        $hash[$key] = $value;
      }
    }

    return $hash;
  }

  public function dirtyfields() {
    return array_keys($this->dirtyhash());
  }

  private function _dirtyhash($fields) {
    if (! $fields) return $this->dirtyhash();
    return array_intersect_key($this->dirtyhash(), array_flip($fields));
  }
  private function _dirtyfields($fields) {
    return array_keys($this->_dirtyhash($fields));
  }

  // -------------- MAGIC PROPERTY ACCESS
  public function __set($key, $value) {
    if (!$this->isFresh() && static::_orm_struct()->is_primary_key($key)) {
      throw new MeekroORMException("Can't update primary key!");
    }
    else if ($this->has($key)) {
      $this->set($key, $value);
    }
    else {
      $this->$key = $value;
    }
  }

  // return by ref on __get() lets $Obj->var[] = 'array_element' work properly
  public function &__get($key) {
    // return by reference requires temp var
    if (static::is_assoc($key)) {
      $result = $this->assoc($key);
      return $result;
    }
    if ($this->has($key)) {
      return $this->get($key);
    }

    return $this->$key;
  }

  public function __isset($key) {
    if (static::is_assoc($key)) {
      return true;
    }
    if ($this->has($key)) {
      return isset($this->_orm_row[$key]);
    }
    return false;
  }

  public function __unset($key) {
    if ($this->has($key)) {
      unset($this->_orm_row[$key]);
    }
  }

  // -------------- GET / SET
  public function has($key) {
    return !! static::_orm_coltype($key);
  }

  private function _orm_require_key($key) {
    if (! $this->has($key)) {
      throw new MeekroORMException("$this does not have key $key");
    }
  }

  // return by ref on __get() lets $Obj->var[] = 'array_element' work properly
  public function &get($key) {
    $this->_orm_require_key($key);
    if (! array_key_exists($key, $this->_orm_row)) {
      // only variables can be returned by reference
      $null = null;
      return $null;
    }

    return $this->_orm_row[$key];
  }

  public function getraw($key) {
    $this->_orm_require_key($key);
    $value = $this->_orm_row[$key] ?? null;
    return $this->_marshal($key, $value);
  }

  public function set($key, $value) {
    $this->_orm_require_key($key);
    $this->_orm_row[$key] = $value;
  }

  public function setraw($key, $value) {
    $this->_orm_require_key($key);
    $this->_orm_row[$key] = $this->_unmarshal($key, $value);
  }

  // -------------- MARSHAL / UNMARSHAL
  // marshal: PHP value -> database value
  public function _marshal($key, $value) {
    return $this->_orm_marshal_dispatch('_marshal', $key, $value);
  }

  // unmarshal: database value -> PHP value
  public function _unmarshal($key, $value) {
    return $this->_orm_marshal_dispatch('_unmarshal', $key, $value);
  }

  // $prefix is '_marshal' or '_unmarshal'
  // a per-field method (e.g. _marshal_field_{$key}) wins over a per-type method (e.g. _marshal_type_{$type})
  // if neither exists, the value is passed through unchanged
  private function _orm_marshal_dispatch($prefix, $key, $value) {
    $type = static::_orm_coltype($key);
    $is_nullable = static::_orm_struct()->column_nullable($key);

    $fieldmethod = "{$prefix}_field_{$key}";
    $typemethod = "{$prefix}_type_{$type}";
    if (method_exists($this, $fieldmethod)) {
      return $this->$fieldmethod($key, $value, $is_nullable);
    }
    if (method_exists($this, $typemethod)) {
      return $this->$typemethod($key, $value, $is_nullable);
    }
    return $value;
  }

  public function _marshal_type_bool($key, $value, $is_nullable) {
    if ($is_nullable && is_null($value)) return null;
    return $value ? 1 : 0;
  }
  public function _unmarshal_type_bool($key, $value) {
    if (is_null($value)) return null;
    return !!$value;
  }

  public function _marshal_type_int($key, $value, $is_nullable) {
    if ($is_nullable && is_null($value)) return null;
    return intval($value);
  }
  public function _unmarshal_type_int($key, $value) {
    if (is_null($value)) return null;
    return intval($value);
  }

  public function _marshal_type_double($key, $value, $is_nullable) {
    if ($is_nullable && is_null($value)) return null;
    return floatval($value);
  }
  public function _unmarshal_type_double($key, $value) {
    if (is_null($value)) return null;
    return floatval($value);
  }

  public function _marshal_type_datetime($key, $value, $is_nullable) {
    // 0000-00-00 00:00:00 is technically not a valid date, and pgsql rejects it
    // we can't use 1970-01-01 00:00:00 because, depending on the local TIMESTAMP, it might
    // become a negative unixtime
    if (!$is_nullable && is_null($value)) $value = '1970-01-03 00:00:00';
    if ($value instanceof \DateTime) return $value->format('Y-m-d H:i:s');
    return $value;
  }
  public function _unmarshal_type_datetime($key, $value) {
    if (is_null($value)) return null;
    if ($value) return DateTime::createFromFormat('Y-m-d H:i:s', $value);
    return $value;
  }

  public function _marshal_type_string($key, $value, $is_nullable) {
    if ($is_nullable && is_null($value)) return null;
    return strval($value);
  }
  public function _unmarshal_type_string($key, $value) {
    if (is_null($value)) return null;
    return strval($value);
  }

  public function _marshal_type_json($key, $value, $is_nullable) {
    return json_encode($value);
  }
  public function _unmarshal_type_json($key, $value) {
    if (is_null($value)) return null;
    return json_decode($value, true);
  }

  // -------------- ASSOCIATIONS
  public static function is_assoc($name) { return !! static::_orm_assoc($name); }
  private static function _orm_assoc($name) {
    if (! array_key_exists($name, static::$_associations)) return null;
    $assoc = static::$_associations[$name];

    if (! isset($assoc['foreign_key'])) {
      throw new MeekroORMException("assocation must have foreign_key");
    }

    $assoc['class_name'] = $assoc['class_name'] ?? $name;
    return $assoc;
  }

  public function assoc($name) {
    if (! static::is_assoc($name)) return null;
    if (! isset($this->_orm_assoc_load[$name])) {
      $this->_orm_assoc_load[$name] = $this->_load_assoc($name);
    }
    
    return $this->_orm_assoc_load[$name];
  }

  // only called from assoc(), which has already checked that $name is a valid association
  private function _load_assoc($name) {
    $assoc = static::_orm_assoc($name);
    $class_name = $assoc['class_name'];
    $foreign_key = $assoc['foreign_key'];

    if (! is_subclass_of($class_name, __CLASS__)) {
      throw new MeekroORMException(sprintf('%s is not a class that inherits from %s', $class_name, __CLASS__));
    }

    $type = $assoc['type'];

    // belongs_to: our $foreign_key column points at their primary key
    if ($type == 'belongs_to') {
      return $class_name::Load($this->$foreign_key);
    }
    if ($type != 'has_one' && $type != 'has_many') {
      throw new MeekroORMException("Invalid type for $name association");
    }

    // has_one, has_many: their $foreign_key column points at our primary key
    $primary_key = static::_orm_struct()->primary_key();
    if (! $primary_key) {
      throw new MeekroORMException("$this must have a single-column primary key to use $type association $name");
    }
    $primary_value = $this->getraw($primary_key);

    if ($type == 'has_one') {
      return $class_name::Search([$foreign_key => $primary_value]);
    }
    return $class_name::Where([$foreign_key => $primary_value]);
  }

  // -------------- LOADING AND SEARCHING
  private function _load_hash(array $row) {
    $this->_orm_is_fresh = false;
    $this->_orm_row_orig = [];
    $this->_orm_row = [];
    $this->_orm_assoc_load = [];
    foreach ($row as $key => $value) {
      if ($this->has($key)) {
        $this->_orm_row[$key] = $this->_unmarshal($key, $value);
        $this->_orm_row_orig[$key] = $this->_marshal($key, $this->_orm_row[$key]);
      } else {
        $this->$key = $value;
      }
    }
  }

  public static function LoadFromHash(array $row = []) {
    $Obj = new static();
    $Obj->_load_hash($row);
    return $Obj;
  }

  public static function Load(...$values) {
    $keys = static::_orm_struct()->primary_keys();
    if (count($values) != count($keys)) {
      throw new MeekroORMException(sprintf("Load on %s must be called with %d parameters!", 
        static::class, count($keys)));
    }

    return static::Search(array_combine($keys, $values));
  }

  private static function _Search($many, $query, ...$args) {
    // infer the table structure first in case we run FOUND_ROWS()
    static::_orm_struct();

    if (is_array($query)) {
      $table = static::_tablename();
      $limiter = $many ? '' : 'LIMIT 1';

      if ($query) {
        $rows = static::_orm_query('query', 'SELECT * FROM :b WHERE :ha :l', $table, $query, $limiter);
      } else {
        $rows = static::_orm_query('query', 'SELECT * FROM :b :l', $table, $limiter);
      }
    }
    else {
      $rows = static::_meekrodb()->query($query, ...$args);
    }

    if (! $rows) {
      return $many ? [] : null;
    }

    $rows = array_map(function ($row) {
      return static::LoadFromHash($row);
    }, $rows);

    return $many ? $rows : $rows[0];
  }

  public static function Search($query=[], ...$args) {
    return static::_Search(false, $query, ...$args);
  }

  public static function SearchMany($query=[], ...$args) {
    return static::_Search(true, $query, ...$args);
  }

  // -------------- CLONING
  // clone produces a fresh (unsaved) copy with primary keys unset
  // it can be saved as a new record, either with new primary keys set manually
  // or with auto-increment
  public function __clone() {
    $this->_orm_is_fresh = true;
    $this->_orm_row_orig = [];
    $this->_orm_assoc_load = [];

    foreach (static::_orm_struct()->primary_keys() as $key) {
      unset($this->_orm_row[$key]);
    }

    // don't share mutable objects (e.g. DateTime) with the original
    foreach ($this->_orm_row as $key => $value) {
      if (is_object($value)) {
        $this->_orm_row[$key] = clone $value;
      }
    }
  }

  // -------------- SCOPES
  public static function _scopes() {
    return [];
  }

  public static function _orm_runscope($scope, ...$args) {
    $scopes = static::_scopes();
    if (! is_array($scopes)) {
      throw new MeekroORMException("No scopes available");
    }
    if (! array_key_exists($scope, $scopes)) {
      throw new MeekroORMException("Scope not available: $scope");
    }

    $scope = $scopes[$scope];
    if (! is_callable($scope)) {
      throw new MeekroORMException("Invalid scope: must be anonymous function");
    }

    $Scope = $scope(...$args);
    if (! ($Scope instanceof MeekroORMScope)) {
      throw new MeekroORMException("Invalid scope: must use ClassName::Where()");
    }
    return $Scope;
  }

  public static function all() {
    return new MeekroORMScope(static::class);
  }

  public static function where(...$args) {
    $Scope = new MeekroORMScope(static::class);
    $Scope->where(...$args);
    return $Scope;
  }

  public static function scope(...$scopes) {
    $Scope = new MeekroORMScope(static::class);
    $Scope->scope(...$scopes);
    return $Scope;
  }

  // -------------- SAVE / UPDATE / DESTROY
  public function save($run_callbacks=null) {
    return $this->_save(null, $run_callbacks);
  }

  // if $savefields is set, only those fields will be saved
  private function _save($savefields, $run_callbacks=null) {
    if (! is_bool($run_callbacks)) $run_callbacks = true;

    $is_fresh = $this->isFresh();
    $table = static::_tablename();
    $mdb = static::_meekrodb();

    $fields = $this->_orm_in_transaction(function() use ($savefields, $run_callbacks, $is_fresh, $table, $mdb) {
      if ($run_callbacks) {
        $fields = $this->_dirtyfields($savefields);
        foreach ($fields as $field) {
          $this->_orm_run_callback("_validate_{$field}", $this->get($field));
        }
        
        $this->_orm_run_callback('_pre_save', $fields);
        if ($is_fresh) $this->_orm_run_callback('_pre_create', $fields);
        else $this->_orm_run_callback('_pre_update', $fields);
      }
      
      // dirty fields list might change while running the _pre callbacks
      $replace = $this->_dirtyhash($savefields);
      $fields = array_keys($replace);

      $have_loaded = false;
      $unknown_keys = [];
      if ($is_fresh) {
        if ($mdb->supports('returning')) {
          // get back the full row (auto-increment and defaulted columns included)
          // so we don't need to reload(), which might not even be possible
          // if part of the primary key was filled in by the database
          $row = $mdb->insert($table, $replace, ['returning' => true]);
          if (! $row) {
            throw new MeekroORMException("Unable to save(): INSERT RETURNING returned no row");
          }
          $this->_load_hash($row);
          $have_loaded = true;
        }
        else {
          $mdb->insert($table, $replace);
          $this->_orm_is_fresh = false;

          // for reload() to work below, we need to know what our auto-increment value is
          if ($aifield = static::_orm_struct()->ai_field()) {
            $this->set($aifield, $mdb->insertId());
          }

          // primary keys filled in by the database (other than auto-increment) can't be known
          // without RETURNING, so reload() below will probably fail
          $unknown_keys = array_diff(static::_orm_struct()->primary_keys(), array_keys($this->_orm_row));
        }
      }
      else if (count($replace) > 0) {
        $mdb->update($table, $replace, $this->_whereHash());
      }
      
      // don't reload if we did a partial save only
      if ($savefields) {
        $this->_orm_row_orig = array_merge($this->_orm_row_orig, $replace);
      } else if (! $have_loaded) {
        try {
          $this->reload();
        } catch (MeekroORMException $e) {
          if (! $unknown_keys) throw $e;
          throw new MeekroORMException(sprintf("Unable to save(): primary key(s) %s were not set, " .
            "and this database doesn't support INSERT ... RETURNING to find out their values",
            implode(', ', $unknown_keys)));
        }
      }
      
      if ($run_callbacks) {
        if ($is_fresh) $this->_orm_run_callback('_post_create', $fields);
        else $this->_orm_run_callback('_post_update', $fields);
        $this->_orm_run_callback('_post_save', $fields);
      }

      return $fields;
    });

    if ($run_callbacks) {
      $this->_orm_run_callback('_post_commit', $fields);
    }
  }

  public function reload($lock=false) {
    if ($this->isFresh()) {
      throw new MeekroORMException("Can't reload unsaved record!");
    }

    $table = static::_tablename();
    $row = static::_orm_query('queryFirstRow', 'SELECT * FROM :b WHERE :ha LIMIT 1 :l', 
      $table, $this->_whereHash(), $lock ? 'FOR UPDATE' : '');

    if (! $row) {
      throw new MeekroORMException("Unable to reload(): missing row");
    }
    $this->_load_hash($row);
  }

  public function lock() { $this->reload(true); }

  public function update($key_or_hash, $value=null) {
    if ($this->isFresh()) {
      throw new MeekroORMException("Unable to update(): record is fresh");
    }
    if (is_array($key_or_hash)) $hash = $key_or_hash;
    else $hash = [$key_or_hash => $value];

    foreach ($hash as $key => $val) {
      $this->set($key, $val);
    }

    return $this->_save(array_keys($hash));
  }

  public function destroy() {
    $this->_orm_in_transaction(function() {
      $this->_orm_run_callback('_pre_destroy');
      static::_orm_query('query', 'DELETE FROM :b WHERE :ha', static::_tablename(), $this->_whereHash());
      $this->_orm_run_callback('_post_destroy');
    });
  }

  protected function _whereHash() {
    $hash = [];
    $primary_keys = static::_orm_struct()->primary_keys();
    if (! $primary_keys) {
      throw new MeekroORMException("$this has no primary keys");
    }
    foreach ($primary_keys as $key) {
      $hash[$key] = $this->getraw($key);
    }
    return $hash;
  }

  private function _orm_run_callback($func_name, ...$args) {
    if (method_exists($this, $func_name)) {
      $result = $this->$func_name(...$args);
      if ($result === false) {
        throw new MeekroORMException("{$func_name} returned false");
      }
      return $result;
    }
  }

  // -------------- SERIALIZATION
  public function toHash() {
    return $this->_orm_row;
  }

  public function toRawHash() {
    $hash = [];
    foreach ($this->_orm_row as $key => $value) {
      $hash[$key] = $this->_marshal($key, $value);
    }
    return $hash;
  }

  public function __toString() {
    return static::class;
  }

}

class MeekroORMTable {
  protected $struct = [];
  protected $table_name;
  protected $class_name;
  
  function __construct($class_name) {
    $this->class_name = $class_name;
    $this->table_name = $class_name::_tablename();
    $this->struct = $this->table_struct();
  }

  function primary_keys() {
    return array_keys(array_filter(
      $this->struct, 
      function($x) { return $x->is_primary; }
    ));
  }

  function primary_key() {
    return count($this->primary_keys()) == 1 ? $this->primary_keys()[0] : null;
  }

  function is_primary_key($key) {
    return in_array($key, $this->primary_keys());
  }

  function ai_field() {
    $names = array_keys(array_filter($this->struct, function($x) { return $x->is_autoincrement; }));
    return $names ? $names[0] : null;
  }

  function column_type($column) {
    if (! $this->has($column)) return;
    return $this->struct[$column]->simpletype;
  }

  function column_nullable($column) {
    if (! $this->has($column)) return;
    return $this->struct[$column]->is_nullable;
  }

  function has($column) {
    return array_key_exists($column, $this->struct);
  }

  function mdb() {
    return $this->class_name::_meekrodb();
  }

  function query(...$args) {
    return $this->class_name::_orm_query(...$args);
  }

  protected function table_struct() {
    $db_type = $this->mdb()->dbType();
    $data = $this->mdb()->columnList($this->table_name);

    if ($db_type == 'mysql') return $this->table_struct_mysql($data);
    else if ($db_type == 'sqlite') return $this->table_struct_sqlite($data);
    else if ($db_type == 'pgsql') return $this->table_struct_pgsql($data);
    else throw new MeekroORMException("Unsupported database type: {$db_type}");
  }

  protected function table_struct_mysql($data) {
    $struct = [];
    foreach ($data as $name => $hash) {
      $Column = new MeekroORMColumn();
      $Column->name = $name;
      $Column->is_nullable = ($hash['null'] == 'YES');
      $Column->is_primary = ($hash['key'] == 'PRI');
      $Column->is_autoincrement = (($hash['extra'] ?? '') == 'auto_increment');
      $Column->type = $hash['type'];
      $Column->simpletype = $this->table_struct_simpletype($hash['type']);
      $struct[$name] = $Column;
    }

    return $struct;
  }

  protected function table_struct_sqlite($data) {
    $struct = [];

    $has_autoincrement = $this->query('queryFirstField', 'SELECT COUNT(*) FROM sqlite_master 
      WHERE tbl_name=:s AND sql LIKE "%AUTOINCREMENT%"', $this->table_name);

    foreach ($data as $name => $hash) {
      $Column = new MeekroORMColumn();
      $Column->name = $name;
      $Column->is_nullable = ($hash['notnull'] == 0);
      $Column->is_primary = ($hash['pk'] > 0);
      $Column->type = $hash['type'];
      $Column->simpletype = $this->table_struct_simpletype($hash['type']);
      $Column->is_autoincrement = ($Column->is_primary && $has_autoincrement);
      $struct[$name] = $Column;
    }
    return $struct;
  }

  protected function table_struct_pgsql($data) {
    $struct = [];
    foreach ($data as $name => $hash) {
      $Column = new MeekroORMColumn();
      $Column->name = $name;
      $Column->is_nullable = ($hash['is_nullable'] == 'YES');
      $Column->type = $hash['data_type'];
      $Column->simpletype = $this->table_struct_simpletype($Column->type);
      $Column->is_autoincrement = ($hash['column_default'] && substr($hash['column_default'], 0, 8) == 'nextval(');
      $Column->is_primary = false;
      $struct[$name] = $Column;
    }

    $primary_keys = $this->query('queryFirstColumn', "
      SELECT kcu.column_name
      FROM information_schema.table_constraints tc
      JOIN 
        information_schema.key_column_usage kcu
        ON tc.constraint_name = kcu.constraint_name
        AND tc.table_schema = kcu.table_schema
      WHERE
        tc.constraint_type = 'PRIMARY KEY'
        AND tc.table_name = :s
        AND tc.table_schema = 'public';
    ", $this->table_name);

    foreach ($primary_keys as $primary_key) {
      $struct[$primary_key]->is_primary = true;
    }

    return $struct;
  }

  protected function table_struct_simpletype($type) {
    static $typemap = [
      // mysql
      'tinyint' => 'int',
      'smallint' => 'int',
      'mediumint' => 'int',
      'int' => 'int',
      'bigint' => 'int',
      'float' => 'double',
      'double' => 'double',
      'decimal' => 'double',
      'datetime' => 'datetime',
      'timestamp' => 'datetime',

      // pgsql
      'boolean' => 'bool',

      // sqlite, pgsql
      'integer' => 'int',
    ];

    $type = strtolower($type);
    $parts = preg_split('/\W+/', $type, -1, PREG_SPLIT_NO_EMPTY);
    return $typemap[$parts[0]] ?? 'string';
  }

}

class MeekroORMColumn {
  public $name;
  public $type;
  public $simpletype;
  public $is_primary;
  public $is_nullable;
  public $is_autoincrement;
}

class MeekroORMScope implements ArrayAccess, Iterator, Countable {
  protected $class_name;
  protected $Where;
  protected $order_by = [];
  protected $limit_offset;
  protected $limit_rowcount;
  protected $Objects;
  protected $position = 0;

  function __construct($class_name) {
    $this->class_name = $class_name;
    $this->Where = new WhereClause('and');
  }

  function where(...$args) {
    $this->invalidate();

    if (is_array($args[0])) {
      $this->Where->add($this->query_cleanup(':ha'), $args[0]);
    } else {
      $this->Where->add(...$args);
    }
    
    return $this;
  }

  function order_by(...$items) {
    $this->invalidate();

    if (is_array($items[0])) {
      $this->order_by = $items[0];
    }
    else {
      $this->order_by = $items;
    }
    return $this;
  }

  // limit($rowcount) or limit($offset, $rowcount), same as SQL LIMIT
  function limit(int $offset_or_rowcount, ?int $rowcount=null) {
    $this->invalidate();

    if (is_null($rowcount)) {
      $this->limit_rowcount = $offset_or_rowcount;
    } else {
      $this->limit_offset = $offset_or_rowcount;
      $this->limit_rowcount = $rowcount;
    }
    return $this;
  }

  function scope($scope, ...$args) {
    $this->invalidate();

    $Scope = $this->class_name::_orm_runscope($scope, ...$args);

    if (count($this->Where) > 0) {
      $this->Where->add($Scope->Where);
    } else {
      $this->Where = $Scope->Where;
    }

    if (!is_null($Scope->limit_rowcount)) {
      $this->limit_rowcount = $Scope->limit_rowcount;
      $this->limit_offset = $Scope->limit_offset;
    }
    if ($Scope->order_by) {
      $this->order_by = $Scope->order_by;
    }

    return $this;
  }

  protected function run() {
    $table_name = $this->class_name::_tablename();

    $query = 'SELECT * FROM :b WHERE :l';
    $args = [$table_name, $this->Where];

    if (count($this->order_by) > 0) {
      // order_by is either a list of columns ['a', 'b'], or a hash of column => direction ['a' => 'desc']
      $is_list = ($this->order_by == array_values($this->order_by)); // array_is_list() needs PHP 8.1
      $c_string = [];
      foreach ($this->order_by as $key => $value) {
        if ($is_list) {
          $c_string[] = ':c';
          $args[] = $value;
        } else {
          $c_string[] = ':c ' . (strtolower($value) == 'desc' ? 'desc' : 'asc');
          $args[] = $key;
        }
      }
      $query .= ' ORDER BY ' . implode(',', $c_string);
    }

    if (!is_null($this->limit_rowcount)) {
      if (!is_null($this->limit_offset)) {
        $query .= sprintf(' LIMIT %u, %u', $this->limit_offset, $this->limit_rowcount);
      }
      else {
        $query .= sprintf(' LIMIT %u', $this->limit_rowcount);
      }
    }

    $query = $this->query_cleanup($query);
    $this->Objects = $this->class_name::SearchMany($query, ...$args);
    return $this->Objects;
  }

  // discard cached results, so the next access re-runs the query
  protected function invalidate() {
    $this->Objects = null;
    $this->position = 0;
  }

  protected function run_if_missing() {
    if (is_array($this->Objects)) return;
    return $this->run();
  }

  protected function query_cleanup($query) {
    $param_char = $this->class_name::_meekrodb()->param_char;
    return str_replace(':', $param_char, $query);
  }

  function first() {
    if (count($this) == 0) return null;
    return $this[0];
  }

  function last() {
    $count = count($this);
    if ($count == 0) return null;
    return $this[$count-1];
  }

  function toArray() {
    return iterator_to_array($this);
  }

  #[\ReturnTypeWillChange]
  function count() {
    $this->run_if_missing();
    return count($this->Objects);
  }

  // ***** Iterator
  #[\ReturnTypeWillChange]
  function current() {
    $this->run_if_missing();
    return $this->valid() ? $this->Objects[$this->position] : null;
  }
  #[\ReturnTypeWillChange]
  function key() {
    $this->run_if_missing();
    return $this->position;
  }
  #[\ReturnTypeWillChange]
  function next() {
    $this->run_if_missing();
    $this->position++;
  }
  #[\ReturnTypeWillChange]
  function rewind() {
    $this->run_if_missing();
    $this->position = 0;
  }
  #[\ReturnTypeWillChange]
  function valid() {
    $this->run_if_missing();
    return array_key_exists($this->position, $this->Objects);
  }

  // ***** ArrayAccess
  #[\ReturnTypeWillChange]
  function offsetExists($offset) {
    $this->run_if_missing();
    return array_key_exists($offset, $this->Objects);
  }
  #[\ReturnTypeWillChange]
  function offsetGet($offset) {
    $this->run_if_missing();
    return $this->Objects[$offset];
  }
  #[\ReturnTypeWillChange]
  function offsetSet($offset, $value) {
    throw new MeekroORMException("Unable to edit scoped result set");
  }
  #[\ReturnTypeWillChange]
  function offsetUnset($offset) {
    throw new MeekroORMException("Unable to edit scoped result set");
  }
}

class MeekroORMException extends Exception { }
