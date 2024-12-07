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

  // -------------- SIMPLE HELPER FUNCTIONS
  private static function _orm_struct() {
    $table_name = static::_tablename();
    if (! array_key_exists($table_name, self::$_orm_struct)) {
      self::$_orm_struct[$table_name] = new MeekroORMTable(get_called_class());
    }
    return self::$_orm_struct[$table_name];
  }

  public static function _orm_struct_reset() {
    self::$_orm_struct = [];
  }

  public static function _tablename() {
    if (static::$_tablename) return static::$_tablename;
    
    $table = strtolower(get_called_class());
    $last_char = substr($table, strlen($table)-1, 1);
    if ($last_char != 's') $table .= 's';
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

  private function _tr_enabled() {
    if (is_bool($this->_use_transactions)) {
      return $this->_use_transactions;
    }
    return static::_meekrodb()->nested_transactions;
  }
  private function _tr_start() {
    if (! $this->_tr_enabled()) return;
    static::_meekrodb()->startTransaction();
  }
  private function _tr_commit() {
    if (! $this->_tr_enabled()) return;
    static::_meekrodb()->commit();
  }
  private function _tr_rollback() {
    if (! $this->_tr_enabled()) return;
    static::_meekrodb()->rollback();
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


  public function isFresh() {
    return $this->_orm_is_fresh;
  }

  // -------------- GET/SET AND MARSHAL / UNMARSHAL
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

  public function has($key) {
    return !! static::_orm_coltype($key);
  }

  // return by ref on __get() lets $Obj->var[] = 'array_element' work properly
  public function &get($key) {
    if (! $this->has($key)) {
      throw new MeekroORMException("$this does not have key $key");
    }
    if (! array_key_exists($key, $this->_orm_row)) {
      // only variables can be returned by reference
      $null = null;
      return $null;
    }

    return $this->_orm_row[$key];
  }

  public function getraw($key) {
    if (! $this->has($key)) {
      throw new MeekroORMException("$this does not have key $key");
    }
    $value = $this->_orm_row[$key] ?? null;
    return $this->_marshal($key, $value);
  }

  public function set($key, $value) {
    if (! $this->has($key)) {
      throw new MeekroORMException("$this does not have key $key");
    }
    $this->_orm_row[$key] = $value;
  }

  public function setraw($key, $value) {
    if (! $this->has($key)) {
      throw new MeekroORMException("$this does not have key $key");
    }
    $this->_orm_row[$key] = $this->_unmarshal($key, $value);
  }

  public function _marshal($key, $value) {
    $type = static::_orm_coltype($key);
    $is_nullable = static::_orm_struct()->column_nullable($key);
    
    $fieldmarshal = "_marshal_field_{$key}";  
    $typemarshal = "_marshal_type_{$type}";
    if (method_exists($this, $fieldmarshal)) {
      $value = $this->$fieldmarshal($key, $value, $is_nullable);
    }
    else if (method_exists($this, $typemarshal)) {
      $value = $this->$typemarshal($key, $value, $is_nullable);
    }

    return $value;
  }

  public function _unmarshal($key, $value) {
    $type = static::_orm_coltype($key);
    $is_nullable = static::_orm_struct()->column_nullable($key);
    
    $fieldmarshal = "_unmarshal_field_{$key}";  
    $typemarshal = "_unmarshal_type_{$type}";
    if (method_exists($this, $fieldmarshal)) {
      $value = $this->$fieldmarshal($key, $value, $is_nullable);
    }
    else if (method_exists($this, $typemarshal)) {
      $value = $this->$typemarshal($key, $value, $is_nullable);
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
    return doubleval($value);
  }
  public function _unmarshal_type_double($key, $value) {
    if (is_null($value)) return null;
    return doubleval($value);
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

  private function _load_assoc($name) {
    $assoc = static::_orm_assoc($name);
    if (! $assoc) {
      throw new MeekroORMException("Unknown assocation: $name");
    }

    $class_name = $assoc['class_name'];
    $foreign_key = $assoc['foreign_key'];
    $primary_key = $class_name::_orm_struct()->primary_key();
    $primary_value = $this->getraw($primary_key);

    if (! is_subclass_of($class_name, __CLASS__)) {
      throw new MeekroORMException(sprintf('%s is not a class that inherits from %s', $class_name, get_class()));
    }

    if ($assoc['type'] == 'belongs_to') {
      return $class_name::Load($this->$foreign_key);
    }
    else if ($assoc['type'] == 'has_one') {
      return $class_name::Search([
        $assoc['foreign_key'] => $primary_value,
      ]);
    }
    else if ($assoc['type'] == 'has_many') {
      return $class_name::Where([$assoc['foreign_key'] => $primary_value]);
    }
    else {
      throw new Exception("Invalid type for $name association");
    }
  }

  // -------------- CONSTRUCTORS
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
    $class_name = get_called_class();
    $Obj = new $class_name();
    $Obj->_load_hash($row);
    return $Obj;
  }

  public static function Load(...$values) {
    $keys = static::_orm_struct()->primary_keys();
    if (count($values) != count($keys)) {
      throw new Exception(sprintf("Load on %s must be called with %d parameters!", 
        get_called_class(), count($keys)));
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
    return new MeekroORMScope(get_called_class());
  }

  public static function where(...$args) {
    $Scope = new MeekroORMScope(get_called_class());
    $Scope->where(...$args);
    return $Scope;
  }

  public static function scope(...$scopes) {
    $Scope = new MeekroORMScope(get_called_class());
    $Scope->scope(...$scopes);
    return $Scope;
  }

  public function save($run_callbacks=null) {
    return $this->_save(null, $run_callbacks);
  }

  // if $savefields is set, only those fields will be saved
  private function _save($savefields, $run_callbacks=null) {
    if (! is_bool($run_callbacks)) $run_callbacks = true;

    $is_fresh = $this->isFresh();
    $have_committed = false;
    $table = static::_tablename();
    $mdb = static::_meekrodb();

    $this->_tr_start();
    try {
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

      if ($is_fresh) {
        $mdb->insert($table, $replace);
        $this->_orm_is_fresh = false;

        // for reload() to work below, we need to know what our auto-increment value is
        if ($aifield = static::_orm_struct()->ai_field()) {
          $this->set($aifield, $mdb->insertId());
        }
      }
      else if (count($replace) > 0) {
        $mdb->update($table, $replace, $this->_whereHash());
      }
      
      // don't reload if we did a partial save only
      if ($savefields) {
        $this->_orm_row_orig = array_merge($this->_orm_row_orig, $replace);
      } else {
        $this->reload();
      }
      
      if ($run_callbacks) {
        if ($is_fresh) $this->_orm_run_callback('_post_create', $fields);
        else $this->_orm_run_callback('_post_update', $fields);
        $this->_orm_run_callback('_post_save', $fields);
      }
      $this->_tr_commit();
      $have_committed = true;

    } finally {
      if (! $have_committed) $this->_tr_rollback();
    }

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

  public function update($one, $two=null) {
    if ($this->isFresh()) {
      throw new MeekroORMException("Unable to update(): record is fresh");
    }
    if (is_array($one)) $hash = $one;
    else $hash = [$one => $two];

    foreach ($hash as $key => $value) {
      $this->set($key, $value);
    }

    return $this->_save(array_keys($hash));
  }

  public function destroy() {
    $this->_tr_start();
    $have_committed = false;

    try {
      $this->_orm_run_callback('_pre_destroy');
      static::_orm_query('query', 'DELETE FROM :b WHERE :ha', static::_tablename(), $this->_whereHash());
      $this->_orm_run_callback('_post_destroy');
      $this->_tr_commit();
      $have_committed = true;
    } finally {
      if (! $have_committed) $this->_tr_rollback();
    }
  }

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
    return get_called_class();
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
    $this->Objects = null;
    $this->position = 0;

    if (is_array($args[0])) {
      $this->Where->add($this->query_cleanup(':ha'), $args[0]);
    } else {
      $this->Where->add(...$args);
    }
    
    return $this;
  }

  function order_by(...$items) {
    if (is_array($items[0])) {
      $this->order_by = $items[0];
    }
    else {
      $this->order_by = $items;
    }
    return $this;
  }

  function limit(int $one, int $two=null) {
    if (is_null($two)) {
      $this->limit_rowcount = $one;
    } else {
      $this->limit_offset = $one;
      $this->limit_rowcount = $two;
    }
    return $this;
  }

  function scope($scope, ...$args) {
    $this->Objects = null;
    $this->position = 0;

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
      // array_is_list
      if ($this->order_by == array_values($this->order_by)) {
        $c_string = array_fill(0, count($this->order_by), ':c');
        $query .= ' ORDER BY ' . implode(',', $c_string);
        $args = array_merge($args, array_values($this->order_by));
      }
      else {
        $c_string = [];
        foreach ($this->order_by as $column => $order) {
          $c_string[] = ':c ' . (strtolower($order) == 'desc' ? 'desc' : 'asc');
        }
        $query .= ' ORDER BY ' . implode(',', $c_string);
        $args = array_merge($args, array_keys($this->order_by));
      }
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

?>