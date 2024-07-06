<?php
/*
  CREATE TABLE `users_params` (
   `id` bigint(20) unsigned NOT NULL,
   `key` varchar(255) NOT NULL,
   `value` varchar(255) NOT NULL,
   `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
   `expires_at` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
   PRIMARY KEY (`id`,`key`),
   KEY `expires_at` (`expires_at`)
  ) ENGINE=InnoDB DEFAULT CHARSET=latin1;
*/

abstract class MeekroORM {
  // INTERNAL -- DO NOT TOUCH
  protected $_orm_row = [];
  protected $_orm_row_orig = [];
  protected $_orm_assoc_load = [];
  protected static $_orm_inferred_tablestruct = [];

  // (OPTIONAL) SET IN INHERITING CLASS
  // static so they apply to all instances
  protected static $_orm_tablename = null;
  protected static $_orm_tablename_params = null;
  protected static $_orm_tablestruct = []; // cache tablestruct
  protected static $_orm_associations = [];

  static $_orm_columns = [];
  static $_orm_scopes = [];


  // -------------- INFER TABLE STRUCTURE
  public static function _orm_infer_tablestruct() {
    $table = static::_orm_meekrodb()->query("DESCRIBE %b", static::_orm_tablename());
    $struct = array();
    
    foreach ($table as $row) {
      $row['Type'] = preg_split('/\W+/', $row['Type'], -1, PREG_SPLIT_NO_EMPTY);
      $struct[$row['Field']] = $row;
    }

    static::$_orm_inferred_tablestruct[static::_orm_tablename()] = $struct;
  }

  // -------------- SIMPLE HELPER FUNCTIONS
  public static function _orm_tablename() {
    if (static::$_orm_tablename) return static::$_orm_tablename;
    
    $table = strtolower(get_called_class());
    $last_char = substr($table, strlen($table)-1, 1);
    if ($last_char != 's') $table .= 's';
    return $table;
  }

  public static function _orm_tablename_params() {
    if (static::$_orm_tablename_params) return static::$_orm_tablename_params;
    else return static::_orm_tablename() . '_params';
  }

  public static function _orm_meekrodb() { return DB::getMDB(); }
  public static function _orm_primary_key() { $keys = static::_orm_primary_keys(); return $keys[0]; }
  public function _orm_primary_key_value() { return $this->_orm_row[static::_orm_primary_key()]; }

  public static function _orm_primary_keys() {
    $data = array_filter(static::_orm_tablestruct(), function($x) { return $x['Key'] == 'PRI'; });
    if (! $data) throw new Exception(static::_orm_tablename() . " doesn't seem to have any primary keys!");

    return array_keys($data);
  }

  public static function _orm_is_primary_key($key) {
    $struct = static::_orm_tablestruct();
    return ($struct[$key]['Key'] === 'PRI');
  }

  public static function _orm_tablestruct() {
    if (static::$_orm_tablestruct) return static::$_orm_tablestruct;

    $table_name = static::_orm_tablename();
    if (! array_key_exists($table_name, static::$_orm_inferred_tablestruct)) {
      static::_orm_infer_tablestruct();
    }

    return static::$_orm_inferred_tablestruct[$table_name];
  }

  public static function _orm_auto_increment_field() {
    $data = array_filter(static::_orm_tablestruct(), function($x) { return $x['Extra'] == 'auto_increment'; });
    if (! $data) return null;
    $data = array_values($data);
    return $data[0]['Field'];
  }

  protected function _orm_fields() {
    return array_keys(static::_orm_tablestruct());
  }

  protected function _orm_dirty_fields() {
    return array_keys($this->_orm_dirtyhash());
  }

  protected function _orm_dirtyhash() {
    $hash = [];
    foreach ($this->_orm_row as $key => $value) {
      if (!array_key_exists($key, $this->_orm_row_orig) || $value !== $this->_orm_row_orig[$key]) {
        $hash[$key] = static::_orm_run_marshal($value, $key);
      }
    }

    return $hash;
  }

  protected function _where() {
    $where = new WhereClause('and');

    foreach (static::_orm_primary_keys() as $key) {
      $where->add('%b = %?', $key, $this->_attribute_get($key));
    }
    
    return $where;
  }

  protected function _orm_run_callback() {
    $args = func_get_args();
    $func_name = array_shift($args);
    $func_call = array($this, $func_name);
    if (is_callable($func_call)) return call_user_func_array($func_call, $args);
    return false;
  }


  public function _orm_is_fresh() { return !$this->_orm_row_orig; }

  public function _attribute_set($key, $value) {
    if ($this->_attribute_exists($key)) {
      return $this->_orm_row[$key] = $value;
    } else {
      return $this->$key = $value;
    }
  }

  public function _attribute_get($key) {
    if ($this->_attribute_exists($key)) {
      return $this->_orm_row[$key];
    } else {
      return $this->$key;
    }
  }
  
  public function _attribute_exists($key) { return array_key_exists($key, static::_orm_tablestruct()); }


  // -------------- TYPES AND MARSHAL / UNMARSHAL
  public static function _orm_colinfo($column, $type) {
    if (! is_array(static::$_orm_columns)) return;
    if (! array_key_exists($column, static::$_orm_columns)) return;

    $info = static::$_orm_columns[$column];
    return $info[$type] ?? null;
  }

  public static function _orm_coltype($column) {
    if ($type = static::_orm_colinfo($column, 'type')) {
      return $type;
    }

    $struct = static::_orm_tablestruct();
    $type = strval($struct[$column]['Type'][0]);

    static $typemap = [
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
    ];

    if (array_key_exists($type, $typemap)) {
      return $typemap[$type];
    }

    return 'string';
  }

  public static function _orm_colnull($column) {
    $struct = static::_orm_tablestruct();
    $type = strtolower($struct[$column]['Null']);
    return $type == 'yes';
  }

  public static function _orm_run_marshal($data, $column) {
    $type = static::_orm_coltype($column);
    $class = get_called_class();
    $name = "_orm_typemarshal_{$type}";
    $is_nullable = static::_orm_colnull($column);

    $default = '';
    if ($type == 'int' || $type == 'double') $default = 0;
    else if ($type == 'datetime') $default = '0000-00-00 00:00:00';

    if (method_exists($class, $name)) {
      $data = $class::$name($data);
    }

    if (is_null($data) && !$is_nullable) {
      $data = $default;
    }

    return $data;
  }

  public static function _orm_run_unmarshal($data, $column) {
    $type = static::_orm_coltype($column);
    $class = get_called_class();
    $name = "_orm_typeunmarshal_{$type}";
    if (method_exists($class, $name)) {
      $data = $class::$name($data);
    }
    return $data;
  }

  public static function _orm_typemarshal_bool($data) {
    if (is_null($data)) return null;
    return $data ? 1 : 0;
  }
  public static function _orm_typeunmarshal_bool($data) {
    if (is_null($data)) return null;
    return !!$data;
  }

  public static function _orm_typeunmarshal_int($data) {
    if (is_null($data)) return null;
    return intval($data);
  }
  public static function _orm_typeunmarshal_double($data) {
    if (is_null($data)) return null;
    return doubleval($data);
  }

  public static function _orm_typemarshal_datetime($data) {
    if (is_null($data)) return null;
    if ($data instanceof \DateTime) return $data->format('Y-m-d H:i:s');
    return $data;
  }
  public static function _orm_typeunmarshal_datetime($data) {
    if (is_null($data)) return null;
    if (!$data || !class_exists('Carbon\Carbon')) return $data;
    return new Carbon\Carbon($data);
  }

  // -------------- ASSOCIATIONS
  public static function is_assoc($name) { return !! static::_orm_assoc($name); }
  protected static function _orm_assoc($name) {
    if (! array_key_exists($name, static::$_orm_associations)) return null;
    $assoc = static::$_orm_associations[$name];

    $assoc['class_name'] = $assoc['class_name'] ?? $name;
    $assoc['foreign_key'] = $assoc['foreign_key'] ?? strtolower($name) . '_id';
    return $assoc;
  }

  public function assoc($name) {
    if (! static::is_assoc($name)) return null;
    if (! isset($this->_orm_assoc_load[$name])) {
      $this->_orm_assoc_load[$name] = $this->_load_assoc($name);
    }
    
    return $this->_orm_assoc_load[$name];
  }

  protected function _load_assoc($name) {
    $assoc = static::_orm_assoc($name);
    if (! $assoc) {
      throw new MeekroORMException("Unknown assocation: $name");
    }

    $class_name = $assoc['class_name'];
    $foreign_key = $assoc['foreign_key'];
    $primary_key = $class_name::_orm_primary_key();

    if (! is_subclass_of($class_name, __CLASS__)) {
      throw new MeekroORMException(sprintf('%s is not a class that inherits from %s', $class_name, get_class()));
    }

    if ($assoc['type'] == 'belongs_to') {
      return $class_name::Search([
        $primary_key => $this->$foreign_key,
      ]);
    }
    else if ($assoc['type'] == 'has_one') {
      return $class_name::Search([
        $assoc['foreign_key'] => $this->_orm_primary_key_value(),
      ]);
    }
    else if ($assoc['type'] == 'has_many') {
      return $class_name::Where('%c=%?', $assoc['foreign_key'], $this->_orm_primary_key_value());
    }
    else {
      throw new Exception("Invalid type for $name association");
    }
  }


  // -------------- ARRAY ACCESS
  // public function offsetGet(mixed $offset): mixed { return $this->__get($offset); }
  // public function offsetSet(mixed $offset, mixed $value): void { $this->__set($offset, $value); }
  // public function offsetExists(mixed $offset): bool { return ($this->offsetGet($offset) !== null); }
  // public function offsetUnset(mixed $offset): void { $this->__set($offset, null); }

  // -------------- CONSTRUCTORS
  public static function LoadFromHash(array $row = array()) {
    $class_name = get_called_class();
    $Obj = new $class_name();
    foreach ($row as $key => $value) {
      $Obj->_orm_row[$key] = static::_orm_run_unmarshal($value, $key);
    }
    
    $Obj->_orm_row_orig = $Obj->_orm_row;
    return $Obj;
  }

  public static function Load() {
    $keys = static::_orm_primary_keys();
    $values = func_get_args();
    if (count($values) != count($keys)) {
      throw new Exception(sprintf("Load on %s must be called with %d parameters!", 
        static::_orm_tablename(), count($keys)));
    }

    return static::Search(array_combine($keys, $values));
  }

  protected static function _orm_query_from_hash(array $hash, $one, $lock=false) {
    $query = "SELECT * FROM %b WHERE %ha";
    if ($one) $query .= " LIMIT 1";
    if ($lock) $query .= " FOR UPDATE";

    return array($query, static::_orm_tablename(), $hash);
  }

  public static function Search() {
    static::_orm_tablestruct(); // infer the table structure first in case we run FOUND_ROWS()

    $args = func_get_args();
    if (is_array($args[0])) {
      $opts_default = array('lock' => false);
      $opts = isset($args[1]) && is_array($args[1]) ? $args[1] : array();
      $opts = array_merge($opts_default, $opts);

      $args = static::_orm_query_from_hash($args[0], true, $opts['lock']);
    }

    $row = call_user_func_array(array(static::_orm_meekrodb(), 'queryFirstRow'), $args);
    if (is_array($row)) return static::LoadFromHash($row);
    else return null;
  }

  public static function SearchMany() {
    static::_orm_tablestruct(); // infer the table structure first in case we run FOUND_ROWS()

    $args = func_get_args();
    if (is_array($args[0])) $args = static::_orm_query_from_hash($args[0], false);
    
    $result = [];
    $rows = call_user_func_array(array(static::_orm_meekrodb(), 'query'), $args);
    if (is_array($rows)) {
      foreach ($rows as $row) {
        $result[] = static::LoadFromHash($row);
      }
    }
    return $result;
  }


  // -------------- DYNAMIC METHODS
  public function __set($key, $value) {
    if (!$this->_orm_is_fresh() && $this->_orm_is_primary_key($key)) {
      throw new MeekroORMException("Can't update primary key!");
    }
    else if (array_key_exists($key, static::_orm_tablestruct())) {
      $callback = $this->_orm_run_callback("_set_$key", $value);
      if ($callback === false) $this->_attribute_set($key, $value);
    }
    else {
      $this->$key = $value;
    }
  }

  // return by ref on __get() lets $Obj->var[] = 'array_element' work properly
  public function &__get($key) {
    if (static::is_assoc($key)) {
      // return by reference requires temp var
      $result = $this->assoc($key);
      return $result;
    }
    if (array_key_exists($key, static::_orm_tablestruct())) {
      $callback = $this->_orm_run_callback("_get_$key");
      if ($callback !== false) {
        return $callback;
      }

      // return by reference requires temp var
      $result = $this->_attribute_get($key);
      return $result;
    }

    return $this->$key;
  }

  static function _orm_scopes() {
    return [];
  }

  static function _orm_runscope($scope, ...$args) {
    $scopes = static::_orm_scopes();
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

  static function where(...$args) {
    $Scope = new MeekroORMScope(get_called_class());
    $Scope->where(...$args);
    return $Scope;
  }

  static function scope(...$scopes) {
    $Scope = new MeekroORMScope(get_called_class());
    $Scope->scope(...$scopes);
    return $Scope;
  }

  public function save($run_callbacks=true) {
    $is_fresh = $this->_orm_is_fresh();
    $have_committed = false;

    DB::startTransaction();

    try {
      if ($run_callbacks) {
        $fields = $this->_orm_dirty_fields();

        foreach ($fields as $field) {
          $this->_orm_run_callback("_validate_{$field}");
        }
        
        $this->_orm_run_callback('_pre_save', $fields);
        if ($is_fresh) $this->_orm_run_callback('_pre_create', $fields);
        else $this->_orm_run_callback('_pre_update', $fields);
      }
      
      // dirty fields list might change while running the _pre callbacks
      $replace = $this->_orm_dirtyhash();
      $fields = array_keys($replace);

      if ($is_fresh) {
        static::_orm_meekrodb()->insert(static::_orm_tablename(), $replace);

        if ($aifield = static::_orm_auto_increment_field()) {
          $this->_orm_row[$aifield] = static::_orm_meekrodb()->insertId();
        }
        
      } else if (count($replace) > 0) {
        static::_orm_meekrodb()->update(static::_orm_tablename(), $replace, "%l", $this->_where());
      }
      
      $this->_orm_row_orig = $this->_orm_row;
      $this->reload(); // for INSERTs, pick up any default values that MySQL may have set

      if ($run_callbacks) {
        if ($is_fresh) $this->_orm_run_callback('_post_create', $fields);
        else $this->_orm_run_callback('_post_update', $fields);
        $this->_orm_run_callback('_post_save', $fields);
      }
      DB::commit();
      $have_committed = true;

    } finally {
      if (! $have_committed) DB::rollback();
    }

    if ($run_callbacks) {
      $this->_orm_run_callback('_post_commit', $fields);
    }
  }

  public function reload($lock=false) {
    if ($this->_orm_is_fresh()) throw new MeekroORMException("Can't reload unsaved record!");

    $primary_keys = static::_orm_primary_keys();
    $primary_values = array();
    foreach ($primary_keys as $key) {
      $primary_values[] = $this->_attribute_get($key);
    }

    $new = static::Search(array_combine($primary_keys, $primary_values), ['lock' => $lock]);
    if (! $new) throw new Exception("Unable to reload $this -- missing!");
    $this->_orm_row = $this->_orm_row_orig = $new->_orm_row;
    $this->_orm_assoc_load = [];
  }

  public function lock() { $this->reload(true); }

  public function update($key, $value=null) {
    if (is_array($key)) $hash = $key;
    else $hash = array($key => $value);
    //$dirty_fields = array_keys($hash);

    $this->_orm_row = array_merge($this->_orm_row, $hash);

    if (! $this->_orm_is_fresh()) {
      //$this->_orm_run_callback('_pre_save', $dirty_fields);
      //$this->_orm_run_callback('_pre_update', $dirty_fields);

      static::_orm_meekrodb()->update(static::_orm_tablename(), $hash, "%l", $this->_where());
      $this->_orm_row_orig = array_merge($this->_orm_row_orig, $hash);

      //$this->_orm_run_callback('_post_update', $dirty_fields);
      //$this->_orm_run_callback('_post_save', $dirty_fields);
    }
  }

  public function destroy() {
    DB::startTransaction();

    try {
      $this->_orm_run_callback('_pre_destroy');
      static::_orm_meekrodb()->query("DELETE FROM %b WHERE %l LIMIT 1", static::_orm_tablename(), $this->_where());
      $this->_orm_run_callback('_post_destroy');
      DB::commit();
      $have_committed = true;
    } finally {
      if (! $have_committed) DB::rollback();
    }
  }

  public function toHash() {
    return $this->_orm_row;
  }

  public function __toString() {
    return static::_orm_tablename();
  }



  // -------------- PARAMS
  public function setparam($key, $value, $ttl=0) {
    static::_orm_meekrodb()->replace(static::_orm_tablename_params(), array(
      'id' => $this->_orm_primary_key_value(),
      'key' => strval($key),
      'value' => strval($value),
      'expires_at' => $ttl ? static::_orm_meekrodb()->sqleval('DATE_ADD(NOW(), INTERVAL %i SECOND)', $ttl) : 0
    ));
  }

  public function param($key) {
    return static::_orm_meekrodb()->queryFirstField("SELECT value FROM %b WHERE id=%i AND `key`=%s AND (expires_at=0 OR expires_at > NOW())", 
      static::_orm_tablename_params(), $this->_orm_primary_key_value(), $key);
  }

  public function unsetparam($key) {
    return static::_orm_meekrodb()->query("DELETE FROM %b WHERE id=%i AND `key`=%s", 
      static::_orm_tablename_params(), $this->_orm_primary_key_value(), $key);
  }

  public function unsetallparams() {
    return static::_orm_meekrodb()->query("DELETE FROM %b WHERE id=%i", 
      static::_orm_tablename_params(), $this->_orm_primary_key_value());
  }
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

    $this->Where->add(...$args);
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
    $table_name = $this->class_name::_orm_tablename();

    $query = 'SELECT * FROM %b WHERE %l';
    $args = [$table_name, $this->Where];

    if (count($this->order_by) > 0) {
      // array_is_list
      if ($this->order_by == array_values($this->order_by)) {
        $c_string = array_fill(0, count($this->order_by), '%c');
        $query .= ' ORDER BY ' . implode(',', $c_string);
        $args = array_merge($args, array_values($this->order_by));
      }
      else {
        $c_string = [];
        foreach ($this->order_by as $column => $order) {
          $c_string[] = '%c ' . (strtolower($order) == 'desc' ? 'desc' : 'asc');
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

    $this->Objects = $this->class_name::SearchMany($query, ...$args);
    return $this->Objects;
  }

  protected function run_if_missing() {
    if (is_array($this->Objects)) return;
    return $this->run();
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