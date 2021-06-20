<?php
/*
    Copyright (C) 2008 Sergey Tsalkov (stsalkov@gmail.com)

    This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU Lesser General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU Lesser General Public License for more details.

    You should have received a copy of the GNU Lesser General Public License
    along with this program.  If not, see <http://www.gnu.org/licenses/>.
*/


class DB {
  // initial connection
  public static $dbName = '';
  public static $user = '';
  public static $password = '';
  public static $host = 'localhost';
  public static $port = 3306; //hhvm complains if this is null
  public static $socket = null;
  public static $encoding = 'latin1';
  
  // configure workings
  public static $param_char = '%';
  public static $named_param_seperator = '_';
  public static $success_handler = false;
  public static $error_handler = true;
  public static $throw_exception_on_error = false;
  public static $nonsql_error_handler = null;
  public static $pre_sql_handler = false;
  public static $throw_exception_on_nonsql_error = false;
  public static $nested_transactions = false;
  public static $ssl = array('key' => '', 'cert' => '', 'ca_cert' => '', 'ca_path' => '', 'cipher' => '');
  public static $connect_options = array(MYSQLI_OPT_CONNECT_TIMEOUT => 30);
  
  // internal
  protected static $mdb = null;
  public static $variables_to_sync = array('param_char', 'named_param_seperator', 'success_handler', 'error_handler', 'throw_exception_on_error', 'nonsql_error_handler', 'pre_sql_handler', 'throw_exception_on_nonsql_error', 'nested_transactions', 'ssl', 'connect_options');
  
  public static function getMDB() {
    $mdb = DB::$mdb;
    
    if ($mdb === null) {
      $mdb = DB::$mdb = new MeekroDB();
    }

    // Sync everytime because settings might have changed. It's fast.
    $mdb->sync_config(); 
    
    return $mdb;
  }

  public static function __callStatic($name, $args) {
    $fn = array(DB::getMDB(), $name);
    if (! is_callable($fn)) {
      throw new MeekroDBException("MeekroDB does not have a method called $name");
    }

    return call_user_func_array($fn, $args);
  }
  
  public static function debugMode($handler = true) { 
    DB::$success_handler = $handler;
  }
  
}


class MeekroDB {
  // initial connection
  public $dbName = '';
  public $user = '';
  public $password = '';
  public $host = 'localhost';
  public $port = 3306;
  public $socket = null;
  public $encoding = 'latin1';
  
  // configure workings
  public $param_char = '%';
  public $named_param_seperator = '_';
  public $success_handler = false;
  public $error_handler = true;
  public $throw_exception_on_error = false;
  public $nonsql_error_handler = null;
  public $pre_sql_handler = false;
  public $throw_exception_on_nonsql_error = false;
  public $nested_transactions = false;
  public $ssl = array('key' => '', 'cert' => '', 'ca_cert' => '', 'ca_path' => '', 'cipher' => '');
  public $connect_options = array(MYSQLI_OPT_CONNECT_TIMEOUT => 30);
  
  // internal
  public $internal_mysql = null;
  public $server_info = null;
  public $insert_id = 0;
  public $num_rows = 0;
  public $affected_rows = 0;
  public $current_db = null;
  public $nested_transactions_count = 0;

  public function __construct($host=null, $user=null, $password=null, $dbName=null, $port=null, $encoding=null, $socket=null)  {
    if ($host === null) $host = DB::$host;
    if ($user === null) $user = DB::$user;
    if ($password === null) $password = DB::$password;
    if ($dbName === null) $dbName = DB::$dbName;
    if ($port === null) $port = DB::$port;
    if ($socket === null) $socket = DB::$socket;
    if ($encoding === null) $encoding = DB::$encoding;
    
    $this->host = $host;
    $this->user = $user;
    $this->password = $password;
    $this->dbName = $dbName;
    $this->port = $port;
    $this->socket = $socket;
    $this->encoding = $encoding;

    $this->sync_config();
  }

  // suck in config settings from static class
  public function sync_config() {
    foreach (DB::$variables_to_sync as $variable) {
      if ($this->$variable !== DB::$$variable) {
        $this->$variable = DB::$$variable;
      }
    }
  }
  
  public function get() {
    $mysql = $this->internal_mysql;
    
    if (!($mysql instanceof MySQLi)) {
      if (! $this->port) $this->port = ini_get('mysqli.default_port');
      $this->current_db = $this->dbName;
      $mysql = new mysqli();

      $connect_flags = 0;
      if ($this->ssl['key']) {
        $mysql->ssl_set($this->ssl['key'], $this->ssl['cert'], $this->ssl['ca_cert'], $this->ssl['ca_path'], $this->ssl['cipher']);
        $connect_flags |= MYSQLI_CLIENT_SSL;
      } 
      foreach ($this->connect_options as $key => $value) {
        $mysql->options($key, $value);
      }

      // suppress warnings, since we will check connect_error anyway
      @$mysql->real_connect($this->host, $this->user, $this->password, $this->dbName, $this->port, $this->socket, $connect_flags);
      
      if ($mysql->connect_error) {
        return $this->nonSQLError('Unable to connect to MySQL server! Error: ' . $mysql->connect_error);
      }
      
      $mysql->set_charset($this->encoding);
      $this->internal_mysql = $mysql;
      $this->server_info = $mysql->server_info;
    }
    
    return $mysql;
  }
  
  public function disconnect() {
    $mysqli = $this->internal_mysql;
    if ($mysqli instanceof MySQLi) {
      if ($thread_id = $mysqli->thread_id) $mysqli->kill($thread_id); 
      $mysqli->close();
    }
    $this->internal_mysql = null; 
  }
  
  public function nonSQLError($message) {
    if ($this->throw_exception_on_nonsql_error) {
      $e = new MeekroDBException($message);
      throw $e;
    }
    
    $error_handler = is_callable($this->nonsql_error_handler) ? $this->nonsql_error_handler : 'meekrodb_error_handler';
        
    call_user_func($error_handler, array(
      'type' => 'nonsql',
      'error' => $message
    ));
  }
  
  public function debugMode($handler = true) {
    $this->success_handler = $handler;
  }
  
  public function serverVersion() { $this->get(); return $this->server_info; }
  public function transactionDepth() { return $this->nested_transactions_count; }
  public function insertId() { return $this->insert_id; }
  public function affectedRows() { return $this->affected_rows; }
  public function count() { $args = func_get_args(); return call_user_func_array(array($this, 'numRows'), $args); }
  public function numRows() { return $this->num_rows; }
  
  public function useDB() { $args = func_get_args(); return call_user_func_array(array($this, 'setDB'), $args); }
  public function setDB($dbName) {
    $db = $this->get();
    if (! $db->select_db($dbName)) return $this->nonSQLError("Unable to set database to $dbName");
    $this->current_db = $dbName;
  }
  
  
  public function startTransaction() {
    if ($this->nested_transactions && $this->serverVersion() < '5.5') {
      return $this->nonSQLError("Nested transactions are only available on MySQL 5.5 and greater. You are using MySQL " . $this->serverVersion());
    }
    
    if (!$this->nested_transactions || $this->nested_transactions_count == 0) {
      $this->query('START TRANSACTION');
      $this->nested_transactions_count = 1;
    } else {
      $this->query("SAVEPOINT LEVEL{$this->nested_transactions_count}");
      $this->nested_transactions_count++;
    }
    
    return $this->nested_transactions_count;
  }
  
  public function commit($all=false) {
    if ($this->nested_transactions && $this->serverVersion() < '5.5') {
      return $this->nonSQLError("Nested transactions are only available on MySQL 5.5 and greater. You are using MySQL " . $this->serverVersion());
    }
    
    if ($this->nested_transactions && $this->nested_transactions_count > 0)
      $this->nested_transactions_count--;
    
    if (!$this->nested_transactions || $all || $this->nested_transactions_count == 0) {
      $this->nested_transactions_count = 0;
      $this->query('COMMIT');
    } else {
      $this->query("RELEASE SAVEPOINT LEVEL{$this->nested_transactions_count}");
    }
    
    return $this->nested_transactions_count;
  }
  
  public function rollback($all=false) {
    if ($this->nested_transactions && $this->serverVersion() < '5.5') {
      return $this->nonSQLError("Nested transactions are only available on MySQL 5.5 and greater. You are using MySQL " . $this->serverVersion());
    }
    
    if ($this->nested_transactions && $this->nested_transactions_count > 0)
      $this->nested_transactions_count--;
    
    if (!$this->nested_transactions || $all || $this->nested_transactions_count == 0) {
      $this->nested_transactions_count = 0;
      $this->query('ROLLBACK');
    } else {
      $this->query("ROLLBACK TO SAVEPOINT LEVEL{$this->nested_transactions_count}");
    }
    
    return $this->nested_transactions_count;
  }
  
  function formatTableName($table) {
    $table = trim($table, '`');
    
    if (strpos($table, '.')) return implode('.', array_map(array($this, 'formatTableName'), explode('.', $table)));
    else return '`' . str_replace('`', '``', $table) . '`'; 
  }
  
  public function update() {
    $args = func_get_args();
    $table = array_shift($args);
    $params = array_shift($args);

    $update_part = $this->parse(
      str_replace('%', $this->param_char, "UPDATE %b SET %hc"),
      $table, $params
    );

    // we don't know if they used named or numbered args, so the where clause
    // must be run through the parser separately
    $where_part = call_user_func_array(array($this, 'parse'), $args);
    $query = $update_part . ' WHERE ' . $where_part;
    return $this->query($query);
  }
  
  public function insertOrReplace($which, $table, $datas, $options=array()) {
    $datas = unserialize(serialize($datas)); // break references within array
    $keys = $values = array();
    
    if (isset($datas[0]) && is_array($datas[0])) {
      $var = '%ll?';
      foreach ($datas as $datum) {
        ksort($datum);
        if (! $keys) $keys = array_keys($datum);
        $values[] = array_values($datum);  
      }
      
    } else {
      $var = '%l?';
      $keys = array_keys($datas);
      $values = array_values($datas);
    }

    if ($which != 'INSERT' && $which != 'INSERT IGNORE' && $which != 'REPLACE') {
      return $this->nonSQLError('insertOrReplace() must be called with one of: INSERT, INSERT IGNORE, REPLACE');
    }
    
    if (isset($options['update']) && is_array($options['update']) && $options['update'] && $which == 'INSERT') {
      if (array_values($options['update']) !== $options['update']) {
        return $this->query(
          str_replace('%', $this->param_char, "INSERT INTO %b %lb VALUES $var ON DUPLICATE KEY UPDATE %hc"), 
          $table, $keys, $values, $options['update']);
      } else {
        $update_str = array_shift($options['update']);
        $query_param = array(
          str_replace('%', $this->param_char, "INSERT INTO %b %lb VALUES $var ON DUPLICATE KEY UPDATE ") . $update_str, 
          $table, $keys, $values);
        $query_param = array_merge($query_param, $options['update']);
        return call_user_func_array(array($this, 'query'), $query_param);
      }
      
    } 
    
    return $this->query(
      str_replace('%', $this->param_char, "%l INTO %b %lb VALUES $var"), 
      $which, $table, $keys, $values);
  }
  
  public function insert($table, $data) { return $this->insertOrReplace('INSERT', $table, $data); }
  public function insertIgnore($table, $data) { return $this->insertOrReplace('INSERT IGNORE', $table, $data); }
  public function replace($table, $data) { return $this->insertOrReplace('REPLACE', $table, $data); }
  
  public function insertUpdate() {
    $args = func_get_args();
    $table = array_shift($args);
    $data = array_shift($args);
    
    if (! isset($args[0])) { // update will have all the data of the insert
      if (isset($data[0]) && is_array($data[0])) { //multiple insert rows specified -- failing!
        return $this->nonSQLError("Badly formatted insertUpdate() query -- you didn't specify the update component!");
      }
      
      $args[0] = $data;
    }
    
    if (is_array($args[0])) $update = $args[0];
    else $update = $args;
    
    return $this->insertOrReplace('INSERT', $table, $data, array('update' => $update)); 
  }
  
  public function delete() {
    $args = func_get_args();
    $table = $this->formatTableName(array_shift($args));

    $where = call_user_func_array(array($this, 'parse'), $args);
    $query = "DELETE FROM {$table} WHERE {$where}";
    return $this->query($query);
  }
  
  public function sqleval() {
    $args = func_get_args();
    $text = call_user_func_array(array($this, 'parse'), $args);
    return new MeekroDBEval($text);
  }
  
  public function columnList($table) {
    $data = $this->query("SHOW COLUMNS FROM %b", $table);
    $columns = array();
    foreach ($data as $row) {
      $columns[$row['Field']] = array(
        'type' => $row['Type'],
        'null' => $row['Null'],
        'key' => $row['Type'],
        'default' => $row['Default'],
        'extra' => $row['Extra']
      );
    }

    return $columns;
  }
  
  public function tableList($db = null) {
    if ($db) {
      $olddb = $this->current_db;
      $this->useDB($db);
    }

    $result = $this->queryFirstColumn('SHOW TABLES');
    if (isset($olddb)) $this->useDB($olddb);
    return $result;
  }

  protected function paramsMap() {
    $t = $this;

    return array(
      's' => function($arg) use ($t) { return $t->escape($arg); },
      'i' => function($arg) use ($t) { return $t->intval($arg); },
      'd' => function($arg) use ($t) { return doubleval($arg); },
      'b' => function($arg) use ($t) { return $t->formatTableName($arg); },
      'l' => function($arg) use ($t) { return strval($arg); },
      't' => function($arg) use ($t) { return $t->escapeTS($arg); },
      'ss' => function($arg) use ($t) { return $t->escape("%" . str_replace(array('%', '_'), array('\%', '\_'), $arg) . "%"); },

      'ls' => function($arg) use ($t) { return array_map(array($t, 'escape'), $arg); },
      'li' => function($arg) use ($t) { return array_map(array($t, 'intval'), $arg); },
      'ld' => function($arg) use ($t) { return array_map('doubleval', $arg); },
      'lb' => function($arg) use ($t) { return array_map(array($t, 'formatTableName'), $arg); },
      'll' => function($arg) use ($t) { return array_map('strval', $arg); },
      'lt' => function($arg) use ($t) { return array_map(array($t, 'escapeTS'), $arg); },

      '?' => function($arg) use ($t) { return $t->sanitize($arg); },
      'l?' => function($arg) use ($t) { return $t->sanitize($arg, 'list'); },
      'll?' => function($arg) use ($t) { return $t->sanitize($arg, 'doublelist'); },
      'hc' => function($arg) use ($t) { return $t->sanitize($arg, 'hash'); },
      'ha' => function($arg) use ($t) { return $t->sanitize($arg, 'hash', ' AND '); },
      'ho' => function($arg) use ($t) { return $t->sanitize($arg, 'hash', ' OR '); },

      $this->param_char => function($arg) use ($t) { return $t->param_char; },
    );
  }

  protected function nextQueryParam($query) {
    $keys = array_keys($this->paramsMap());

    $first_position = PHP_INT_MAX;
    $first_param = null;
    $first_type = null;
    $arg = null;
    $named_arg = null;
    foreach ($keys as $key) {
      $fullkey = $this->param_char . $key;
      $pos = strpos($query, $fullkey);
      if ($pos === false) continue;

      if ($pos <= $first_position) {
        $first_position = $pos;
        $first_param = $fullkey;
        $first_type = $key;
      }
    }

    if (is_null($first_param)) return;

    $first_position_end = $first_position + strlen($first_param);
    $named_seperator_length = strlen($this->named_param_seperator);
    $arg_mask = '0123456789';
    $named_arg_mask = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_';
    
    if ($arg_number_length = strspn($query, $arg_mask, $first_position_end)) {
      $arg = intval(substr($query, $first_position_end, $arg_number_length));
      $first_param = substr($query, $first_position, strlen($first_param) + $arg_number_length);
    }
    else if (substr($query, $first_position_end, $named_seperator_length) == $this->named_param_seperator) {
      $named_arg_length = strspn($query, $named_arg_mask, $first_position_end + $named_seperator_length);

      if ($named_arg_length > 0) {
        $named_arg = substr($query, $first_position_end + $named_seperator_length, $named_arg_length);
        $first_param = substr($query, $first_position, strlen($first_param) + $named_seperator_length + $named_arg_length);
      }
    }

    return array(
      'param' => $first_param,
      'type' => $first_type,
      'pos' => $first_position,
      'arg' => $arg,
      'named_arg' => $named_arg,
    );
  }

  protected function preParse($query, $args) {
    $arg_ct = 0;
    $max_numbered_arg = 0;
    $use_numbered_args = false;
    $use_named_args = false;
    
    $queryParts = array();
    while ($Param = $this->nextQueryParam($query)) {
      if ($Param['pos'] > 0) {
        $queryParts[] = substr($query, 0, $Param['pos']);
      }

      if ($Param['type'] != $this->param_char && is_null($Param['arg']) && is_null($Param['named_arg'])) {
        $Param['arg'] = $arg_ct++;
      }

      if (! is_null($Param['arg'])) {
        $use_numbered_args = true;
        $max_numbered_arg = max($max_numbered_arg, $Param['arg']);
      }
      if (! is_null($Param['named_arg'])) {
        $use_named_args = true;
      }

      $queryParts[] = $Param;
      $query = substr($query, $Param['pos'] + strlen($Param['param']));
    }

    if (strlen($query) > 0) {
      $queryParts[] = $query;
    }

    if ($use_named_args && $use_numbered_args) {
      return $this->nonSQLError("You can't mix named and numbered args!");
    }

    if ($use_named_args && count($args) != 1) {
      return $this->nonSQLError("If you use named args, you must pass an assoc array of args!");
    }

    if ($use_numbered_args && $max_numbered_arg+1 > count($args)) {
      return $this->nonSQLError(sprintf('Expected %d args, but only got %d!', $max_numbered_arg+1, count($args)));
    }

    return $queryParts;
  }

  function parse($query) {
    $args = func_get_args();
    array_shift($args);
    $query = trim($query);

    if (! $args) return $query;
    $queryParts = $this->preParse($query, $args);

    $array_types = array('ls', 'li', 'ld', 'lb', 'll', 'lt', 'l?', 'll?', 'hc', 'ha', 'ho');
    $Map = $this->paramsMap();
    $query = '';
    foreach ($queryParts as $Part) {
      if (is_string($Part)) {
        $query .= $Part;
        continue;
      }

      $fn = $Map[$Part['type']];
      $is_array_type = in_array($Part['type'], $array_types, true);

      $val = null;
      if (!is_null($Part['named_arg'])) {
        $key = $Part['named_arg'];
        if (! array_key_exists($key, $args[0])) {
          return $this->nonSQLError("Couldn't find named arg {$key}!");
        }

        $val = $args[0][$key];
      }
      else if (!is_null($Part['arg'])) {
        $key = $Part['arg'];
        $val = $args[$key];
      }

      if ($is_array_type && !is_array($val)) {
        return $this->nonSQLError("Expected an array for arg $key but didn't get one!");
      }
      if ($is_array_type && count($val) == 0) {
        return $this->nonSQLError("Arg {$key} array can't be empty!");
      }
      if (!$is_array_type && is_array($val)) {
        $val = '';
      }

      if (is_object($val) && ($val instanceof WhereClause)) {
        if ($Part['type'] != 'l') {
          return $this->nonSQLError("WhereClause must be used with l arg, you used {$Part['type']} instead!");
        }

        list($clause_sql, $clause_args) = $val->textAndArgs();
        array_unshift($clause_args, $clause_sql); 
        $result = call_user_func_array(array($this, 'parse'), $clause_args);
      }
      else {
        $result = $fn($val);
        if (is_array($result)) $result = '(' . implode(',', $result) . ')';
      }
      
      $query .= $result;
    }

    return $query;
  }
  
  public function escape($str) { return "'" . $this->get()->real_escape_string(strval($str)) . "'"; }
  
  public function sanitize($value, $type='basic', $hashjoin=', ') {
    if ($type == 'basic') {
      if (is_object($value)) {
        if ($value instanceof MeekroDBEval) return $value->text;
        else if ($value instanceof DateTime) return $this->escape($value->format('Y-m-d H:i:s'));
        else return $this->escape($value); // use __toString() value for objects, when possible
      }
      
      if (is_null($value)) return 'NULL';
      else if (is_bool($value)) return ($value ? 1 : 0);
      else if (is_int($value)) return $value;
      else if (is_float($value)) return $value;
      else if (is_array($value)) return "''";
      else return $this->escape($value);

    } else if ($type == 'list') {
      if (is_array($value)) {
        $value = array_values($value);
        return '(' . implode(', ', array_map(array($this, 'sanitize'), $value)) . ')';
      } else {
        return $this->nonSQLError("Expected array parameter, got something different!");
      }
    } else if ($type == 'doublelist') {
      if (is_array($value) && array_values($value) === $value && is_array($value[0])) {
        $cleanvalues = array();
        foreach ($value as $subvalue) {
          $cleanvalues[] = $this->sanitize($subvalue, 'list');
        }
        return implode(', ', $cleanvalues);

      } else {
        return $this->nonSQLError("Expected double array parameter, got something different!");
      }
    } else if ($type == 'hash') {
      if (is_array($value)) {
        $pairs = array();
        foreach ($value as $k => $v) {
          $pairs[] = $this->formatTableName($k) . '=' . $this->sanitize($v);
        }
        
        return implode($hashjoin, $pairs);
      } else {
        return $this->nonSQLError("Expected hash (associative array) parameter, got something different!");
      }
    } else {
      return $this->nonSQLError("Invalid type passed to sanitize()!");
    }
    
  }

  function escapeTS($ts) {
    if (is_string($ts)) {
      $str = date('Y-m-d H:i:s', strtotime($ts));
    }
    else if (is_object($ts) && ($ts instanceof DateTime)) {
      $str = $ts->format('Y-m-d H:i:s');
    }

    return $this->escape($str);
  }
  
  function intval($var) {
    if (PHP_INT_SIZE == 8) return intval($var);
    return floor(doubleval($var));
  }
  
  protected function prependCall($function, $args, $prepend) { array_unshift($args, $prepend); return call_user_func_array($function, $args); }
  public function query() { $args = func_get_args(); return $this->prependCall(array($this, 'queryHelper'), $args, 'assoc'); }
  public function queryAllLists() { $args = func_get_args(); return $this->prependCall(array($this, 'queryHelper'), $args, 'list'); }
  public function queryFullColumns() { $args = func_get_args(); return $this->prependCall(array($this, 'queryHelper'), $args, 'full'); }

  public function queryRaw() { $args = func_get_args(); return $this->prependCall(array($this, 'queryHelper'), $args, 'raw_buf'); }
  public function queryRawUnbuf() { $args = func_get_args(); return $this->prependCall(array($this, 'queryHelper'), $args, 'raw_unbuf'); }
  
  protected function queryHelper() {
    $args = func_get_args();
    $type = array_shift($args);
    $db = $this->get();

    $is_buffered = true;
    $row_type = 'assoc'; // assoc, list, raw
    $full_names = false;

    switch ($type) {
      case 'assoc':
        break;
      case 'list':
        $row_type = 'list';
        break;
      case 'full':
        $row_type = 'list';
        $full_names = true;
        break;
      case 'raw_buf':
        $row_type = 'raw';
        break;
      case 'raw_unbuf':
        $is_buffered = false;
        $row_type = 'raw';
        break;
      default:
        return $this->nonSQLError('Error -- invalid argument to queryHelper!');
    }

    $sql = call_user_func_array(array($this, 'parse'), $args);

    if ($this->pre_sql_handler !== false && is_callable($this->pre_sql_handler)) {
      $sql = call_user_func($this->pre_sql_handler, $sql);
    }
    
    if ($this->success_handler) $starttime = microtime(true);
    $result = $db->query($sql, $is_buffered ? MYSQLI_STORE_RESULT : MYSQLI_USE_RESULT);
    if ($this->success_handler) $runtime = microtime(true) - $starttime;
    else $runtime = 0;

    // ----- BEGIN ERROR HANDLING
    if (!$sql || $db->error) {
      if ($this->error_handler) {
        $error_handler = is_callable($this->error_handler) ? $this->error_handler : 'meekrodb_error_handler';
        
        call_user_func($error_handler, array(
          'type' => 'sql',
          'query' => $sql,
          'error' => $db->error,
          'code' => $db->errno
        ));
      }
      
      if ($this->throw_exception_on_error) {
        $e = new MeekroDBException($db->error, $sql, $db->errno);
        throw $e;
      }
    } else if ($this->success_handler) {
      $runtime = sprintf('%f', $runtime * 1000);
      $success_handler = is_callable($this->success_handler) ? $this->success_handler : 'meekrodb_debugmode_handler';
      
      call_user_func($success_handler, array(
        'query' => $sql,
        'runtime' => $runtime,
        'affected' => $db->affected_rows
      )); 
    }

    // ----- END ERROR HANDLING

    $this->insert_id = $db->insert_id;
    $this->affected_rows = $db->affected_rows;

    // mysqli_result->num_rows won't initially show correct results for unbuffered data
    if ($is_buffered && ($result instanceof MySQLi_Result)) $this->num_rows = $result->num_rows;
    else $this->num_rows = null;

    if ($row_type == 'raw' || !($result instanceof MySQLi_Result)) return $result;

    $return = array();

    if ($full_names) {
      $infos = array();
      foreach ($result->fetch_fields() as $info) {
        if (strlen($info->table)) $infos[] = $info->table . '.' . $info->name;
        else $infos[] = $info->name;
      }
    }

    while ($row = ($row_type == 'assoc' ? $result->fetch_assoc() : $result->fetch_row())) {
      if ($full_names) $row = array_combine($infos, $row);
      $return[] = $row;
    }

    // free results
    $result->free();
    while ($db->more_results()) {
      $db->next_result();
      if ($result = $db->use_result()) $result->free();
    }
    
    return $return;
  }

  public function queryFirstRow() {
    $args = func_get_args();
    $result = call_user_func_array(array($this, 'query'), $args);
    if (!$result || !is_array($result)) return null;
    return reset($result);
  }

  public function queryFirstList() {
    $args = func_get_args();
    $result = call_user_func_array(array($this, 'queryAllLists'), $args);
    if (!$result || !is_array($result)) return null;
    return reset($result);
  }
  
  public function queryFirstColumn() { 
    $args = func_get_args();
    $results = call_user_func_array(array($this, 'queryAllLists'), $args);
    $ret = array();
    
    if (!count($results) || !count($results[0])) return $ret;
    
    foreach ($results as $row) {
      $ret[] = $row[0];
    }
    
    return $ret;
  }
  
  public function queryFirstField() { 
    $args = func_get_args();
    $row = call_user_func_array(array($this, 'queryFirstList'), $args);
    if ($row == null) return null;    
    return $row[0];
  }
}

class WhereClause {
  public $type = 'and'; //AND or OR
  public $negate = false;
  public $clauses = array();
  
  function __construct($type) {
    $type = strtolower($type);
    if ($type !== 'or' && $type !== 'and') return DB::nonSQLError('you must use either WhereClause(and) or WhereClause(or)');
    $this->type = $type;
  }
  
  function add() {
    $args = func_get_args();
    $sql = array_shift($args);
    
    if ($sql instanceof WhereClause) {
      $this->clauses[] = $sql;
    } else {
      $this->clauses[] = array('sql' => $sql, 'args' => $args);
    }
  }
  
  function negateLast() {
    $i = count($this->clauses) - 1;
    if (!isset($this->clauses[$i])) return;
    
    if ($this->clauses[$i] instanceof WhereClause) {
      $this->clauses[$i]->negate();
    } else {
      $this->clauses[$i]['sql'] = 'NOT (' . $this->clauses[$i]['sql'] . ')';
    }
  }
  
  function negate() {
    $this->negate = ! $this->negate;
  }
  
  function addClause($type) {
    $r = new WhereClause($type);
    $this->add($r);
    return $r;
  }
  
  function count() {
    return count($this->clauses);
  }
  
  function textAndArgs() {
    $sql = array();
    $args = array();
    
    if (count($this->clauses) == 0) return array('(1)', $args);
    
    foreach ($this->clauses as $clause) {
      if ($clause instanceof WhereClause) { 
        list($clause_sql, $clause_args) = $clause->textAndArgs();
      } else {
        $clause_sql = $clause['sql'];
        $clause_args = $clause['args'];
      }
      
      $sql[] = "($clause_sql)";
      $args = array_merge($args, $clause_args);
    }
    
    if ($this->type == 'and') $sql = sprintf('(%s)', implode(' AND ', $sql));
    else $sql = sprintf('(%s)', implode(' OR ', $sql));
    
    if ($this->negate) $sql = '(NOT ' . $sql . ')';
    return array($sql, $args);
  }
}

class DBTransaction {
  private $committed = false;
  
  function __construct() { 
    DB::startTransaction(); 
  }
  function __destruct() { 
    if (! $this->committed) DB::rollback(); 
  }
  function commit() {
    DB::commit();
    $this->committed = true;
  }
  
  
}

class MeekroDBException extends Exception {
  protected $query = '';
  
  function __construct($message='', $query='', $code = 0) {
    parent::__construct($message);
    $this->query = $query;
	$this->code = $code;
  }
  
  public function getQuery() { return $this->query; }
}

class DBHelper {
  /*
    verticalSlice
    1. For an array of assoc rays, return an array of values for a particular key
    2. if $keyfield is given, same as above but use that hash key as the key in new array
  */
  
  public static function verticalSlice($array, $field, $keyfield = null) {
    $array = (array) $array;
    
    $R = array();
    foreach ($array as $obj) {
      if (! array_key_exists($field, $obj)) die("verticalSlice: array doesn't have requested field\n");
      
      if ($keyfield) {
        if (! array_key_exists($keyfield, $obj)) die("verticalSlice: array doesn't have requested field\n");  
        $R[$obj[$keyfield]] = $obj[$field];
      } else { 
        $R[] = $obj[$field];
      }
    }
    return $R;
  }
  
  /*
    reIndex
    For an array of assoc rays, return a new array of assoc rays using a certain field for keys
  */
  
  public static function reIndex() {
    $fields = func_get_args();
    $array = array_shift($fields);
    $array = (array) $array;
    
    $R = array();
    foreach ($array as $obj) {
      $target =& $R;
      
      foreach ($fields as $field) {
        if (! array_key_exists($field, $obj)) die("reIndex: array doesn't have requested field\n");
        
        $nextkey = $obj[$field];
        $target =& $target[$nextkey];
      }
      $target = $obj;
    }
    return $R;
  }
}

function meekrodb_error_handler($params) {
  if (isset($params['query'])) $out[] = "QUERY: " . $params['query'];
  if (isset($params['error'])) $out[] = "ERROR: " . $params['error'];
  $out[] = "";
  
  if (php_sapi_name() == 'cli' && empty($_SERVER['REMOTE_ADDR'])) {
    echo implode("\n", $out);
  } else {
    echo implode("<br>\n", $out);
  }
  
  die;
}

function meekrodb_debugmode_handler($params) {
  echo "QUERY: " . $params['query'] . " [" . $params['runtime'] . " ms]";
  if (php_sapi_name() == 'cli' && empty($_SERVER['REMOTE_ADDR'])) {
    echo "\n";
  } else {
    echo "<br>\n";
  }
}

class MeekroDBEval {
  public $text = '';
  
  function __construct($text) {
    $this->text = $text;
  }
}

?>
