<?php
/*
    Copyright (C) 2008-2011 Sergey Tsalkov (stsalkov@gmail.com)

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
  public static $port = null;
  public static $encoding = 'latin1';
  
  // configure workings
  public static $queryMode = 'queryAllRows';
  public static $param_char = '%';
  public static $success_handler = false;
  public static $error_handler = true;
  public static $throw_exception_on_error = false;
  public static $nonsql_error_handler = null;
  public static $throw_exception_on_nonsql_error = false;
  
  // internal
  protected static $mdb = null;
  
  public static function getMDB() {
    $mdb = DB::$mdb;
    
    if ($mdb === null) {
      $mdb = DB::$mdb = new MeekroDB();
    }
    
    if ($mdb->queryMode !== DB::$queryMode) $mdb->queryMode = DB::$queryMode;
    if ($mdb->param_char !== DB::$param_char) $mdb->param_char = DB::$param_char;
    if ($mdb->success_handler !== DB::$success_handler) $mdb->success_handler = DB::$success_handler;
    if ($mdb->error_handler !== DB::$error_handler) $mdb->error_handler = DB::$error_handler;
    if ($mdb->throw_exception_on_error !== DB::$throw_exception_on_error) $mdb->throw_exception_on_error = DB::$throw_exception_on_error;
    if ($mdb->nonsql_error_handler !== DB::$nonsql_error_handler) $mdb->nonsql_error_handler = DB::$nonsql_error_handler;
    if ($mdb->throw_exception_on_nonsql_error !== DB::$throw_exception_on_nonsql_error) $mdb->throw_exception_on_nonsql_error = DB::$throw_exception_on_nonsql_error;
    
    return $mdb;
  }
  
  public static function query() { $args = func_get_args(); return call_user_func_array(array(DB::getMDB(), 'query'), $args); }
  public static function quickPrepare() { $args = func_get_args(); return call_user_func_array(array(DB::getMDB(), 'quickPrepare'), $args); }
  public static function queryFirstRow() { $args = func_get_args(); return call_user_func_array(array(DB::getMDB(), 'queryFirstRow'), $args); }
  public static function queryOneRow() { $args = func_get_args(); return call_user_func_array(array(DB::getMDB(), 'queryOneRow'), $args); }
  public static function queryFirstList() { $args = func_get_args(); return call_user_func_array(array(DB::getMDB(), 'queryFirstList'), $args); }
  public static function queryOneList() { $args = func_get_args(); return call_user_func_array(array(DB::getMDB(), 'queryOneList'), $args); }
  public static function queryFirstColumn() { $args = func_get_args(); return call_user_func_array(array(DB::getMDB(), 'queryFirstColumn'), $args); }
  public static function queryOneColumn() { $args = func_get_args(); return call_user_func_array(array(DB::getMDB(), 'queryOneColumn'), $args); }
  public static function queryFirstField() { $args = func_get_args(); return call_user_func_array(array(DB::getMDB(), 'queryFirstField'), $args); }
  public static function queryOneField() { $args = func_get_args(); return call_user_func_array(array(DB::getMDB(), 'queryOneField'), $args); }
  public static function queryRaw() { $args = func_get_args(); return call_user_func_array(array(DB::getMDB(), 'queryRaw'), $args); }
  public static function queryNull() { $args = func_get_args(); return call_user_func_array(array(DB::getMDB(), 'queryNull'), $args); }
  public static function queryBuf() { $args = func_get_args(); return call_user_func_array(array(DB::getMDB(), 'queryBuf'), $args); }
  public static function queryUnbuf() { $args = func_get_args(); return call_user_func_array(array(DB::getMDB(), 'queryUnbuf'), $args); }
  
  public static function insert() { $args = func_get_args(); return call_user_func_array(array(DB::getMDB(), 'insert'), $args); }
  public static function insertIgnore() { $args = func_get_args(); return call_user_func_array(array(DB::getMDB(), 'insertIgnore'), $args); }
  public static function insertUpdate() { $args = func_get_args(); return call_user_func_array(array(DB::getMDB(), 'insertUpdate'), $args); }
  public static function replace() { $args = func_get_args(); return call_user_func_array(array(DB::getMDB(), 'replace'), $args); }
  public static function update() { $args = func_get_args(); return call_user_func_array(array(DB::getMDB(), 'update'), $args); }
  public static function delete() { $args = func_get_args(); return call_user_func_array(array(DB::getMDB(), 'delete'), $args); }
  
  public static function insertId() { $args = func_get_args(); return call_user_func_array(array(DB::getMDB(), 'insertId'), $args); }
  public static function count() { $args = func_get_args(); return call_user_func_array(array(DB::getMDB(), 'count'), $args); }
  public static function affectedRows() { $args = func_get_args(); return call_user_func_array(array(DB::getMDB(), 'affectedRows'), $args); }
  
  public static function useDB() { $args = func_get_args(); return call_user_func_array(array(DB::getMDB(), 'useDB'), $args); }
  public static function startTransaction() { $args = func_get_args(); return call_user_func_array(array(DB::getMDB(), 'startTransaction'), $args); }
  public static function commit() { $args = func_get_args(); return call_user_func_array(array(DB::getMDB(), 'commit'), $args); }
  public static function rollback() { $args = func_get_args(); return call_user_func_array(array(DB::getMDB(), 'rollback'), $args); }
  public static function tableList() { $args = func_get_args(); return call_user_func_array(array(DB::getMDB(), 'tableList'), $args); }
  public static function columnList() { $args = func_get_args(); return call_user_func_array(array(DB::getMDB(), 'columnList'), $args); }
  
  public static function sqlEval() { $args = func_get_args(); return call_user_func_array(array(DB::getMDB(), 'sqlEval'), $args); }
  public static function nonSQLError() { $args = func_get_args(); return call_user_func_array(array(DB::getMDB(), 'nonSQLError'), $args); }
  
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
  public $port = null;
  public $encoding = 'latin1';
  
  // configure workings
  public $queryMode = 'queryAllRows';
  public $param_char = '%';
  public $success_handler = false;
  public $error_handler = true;
  public $throw_exception_on_error = false;
  public $nonsql_error_handler = null;
  public $throw_exception_on_nonsql_error = false;
  
  // internal
  public $internal_mysql = null;
  public $insert_id = 0;
  public $num_rows = 0;
  public $affected_rows = 0;
  public $queryResult = null;
  public $queryResultType = null;
  public $old_db = null;
  public $current_db = null;
  
  
  public function __construct($host=null, $user=null, $password=null, $dbName=null, $port=null, $encoding=null) {
    if ($host === null) $host = DB::$host;
    if ($user === null) $user = DB::$user;
    if ($password === null) $password = DB::$password;
    if ($dbName === null) $dbName = DB::$dbName;
    if ($port === null) $port = DB::$port;
    if ($encoding === null) $encoding = DB::$encoding;
    
    $this->host = $host;
    $this->user = $user;
    $this->password = $password;
    $this->dbName = $dbName;
    $this->port = $port;
    $this->encoding = $encoding;
  }
  
  public function get() {
    $mysql = $this->internal_mysql;
    
    if ($mysql == null) {
      if (! $this->port) $this->port = ini_get('mysqli.default_port');
      $this->current_db = $this->dbName;
      $mysql = new mysqli($this->host, $this->user, $this->password, $this->dbName, $this->port);
      
      if ($mysql->connect_error) {
        $this->nonSQLError('Unable to connect to MySQL server! Error: ' . $mysql->connect_error);
      }
      
      $mysql->set_charset($this->encoding);
      $this->internal_mysql = $mysql;
    }
    
    return $mysql;
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
  
  public function insertId() { return $this->insert_id; }
  public function affectedRows() { return $this->affected_rows; }
  public function count() { $args = func_get_args(); return call_user_func_array(array($this, 'numRows'), $args); }
  public function numRows() { return $this->num_rows; }
  
  public function useDB() { $args = func_get_args(); return call_user_func_array(array($this, 'setDB'), $args); }
  public function setDB($dbName) {
    $db = $this->get();
    $this->old_db = $this->current_db;
    if (! $db->select_db($dbName)) $this->nonSQLError("Unable to set database to $dbName");
    $this->current_db = $dbName;
  }
  
  
  public function startTransaction() {
    $this->queryNull('START TRANSACTION');
  }
  
  public function commit() {
    $this->queryNull('COMMIT');
  }
  
  public function rollback() {
    $this->queryNull('ROLLBACK');
  }
  
  public function escape($str) {
    $db = $this->get();
    return $db->real_escape_string($str);
  }
  
  public function sanitize($value) {
    if (is_object($value) && ($value instanceof MeekroDBEval)) {
      $value = $value->text;
    } else {
      if (is_array($value) || is_object($value)) $value = serialize($value);
      
      if (is_string($value)) $value = "'" . $this->escape($value) . "'";
      else if (is_null($value)) $value = 'NULL';
      else if (is_bool($value)) $value = ($value ? 1 : 0);
    }
    
    return $value;
  }
  
  protected function formatTableName($table) {
    $table = str_replace('`', '', $table);
    if (strpos($table, '.')) {
      list($table_db, $table_table) = explode('.', $table, 2);
      $table = "`$table_db`.`$table_table`";
    } else {
      $table = "`$table`";
    }
    
    return $table;
  }
  
  protected function prependCall($function, $args, $prepend) {
    array_unshift($args, $prepend);
    return call_user_func_array($function, $args);
  }
  
  protected function wrapStr($strOrArray, $wrapChar, $escape = false) {
    if (! is_array($strOrArray)) {
      if ($escape) return $wrapChar . $this->escape($strOrArray) . $wrapChar;
      else return $wrapChar . $strOrArray . $wrapChar;
    } else {
      $R = array();
      foreach ($strOrArray as $element) {
        $R[] = $this->wrapStr($element, $wrapChar, $escape);
      }
      return $R;
    }
      
  }
  
  public function freeResult($result) {
    if (! ($result instanceof MySQLi_Result)) return;
    return $result->free();
  }
  
  public function update() {
    $args = func_get_args();
    $table = array_shift($args);
    $params = array_shift($args);
    $where = array_shift($args);
    $buildquery = "UPDATE " . self::formatTableName($table) . " SET ";
    $keyval = array();
    foreach ($params as $key => $value) {
      $value = $this->sanitize($value);
      $keyval[] = "`" . $key . "`=" . $value;
    }
    
    $buildquery = "UPDATE " . self::formatTableName($table) . " SET " . implode(', ', $keyval) . " WHERE " . $where;
    array_unshift($args, $buildquery);
    call_user_func_array(array($this, 'queryNull'), $args);
  }
  
  public function insertOrReplace($which, $table, $datas, $options=array()) {
    $datas = unserialize(serialize($datas)); // break references within array
    $keys = null;
    
    if (isset($datas[0]) && is_array($datas[0])) {
      $many = true;
    } else {
      $datas = array($datas);
      $many = false;
    }
    
    foreach ($datas as $data) {
      if (! $keys) {
        $keys = array_keys($data);
        if ($many) sort($keys);
      }
      
      $insert_values = array();
      
      foreach ($keys as $key) {
        if ($many && !isset($data[$key])) $this->nonSQLError('insert/replace many: each assoc array must have the same keys!'); 
        $datum = $data[$key];
        $datum = $this->sanitize($datum);
        $insert_values[] = $datum;
      }
      
      
      $values[] = '(' . implode(', ', $insert_values) . ')';
    }
    
    $table = self::formatTableName($table);
    $keys_str = implode(', ', $this->wrapStr($keys, '`'));
    $values_str = implode(',', $values);
    
    if (isset($options['ignore']) && $options['ignore'] && strtolower($which) == 'insert') { 
      $this->queryNull("INSERT IGNORE INTO $table ($keys_str) VALUES $values_str");
      
    } else if (isset($options['update']) && $options['update'] && strtolower($which) == 'insert') {
      $this->queryNull("INSERT INTO $table ($keys_str) VALUES $values_str ON DUPLICATE KEY UPDATE {$options['update']}");
      
    } else { 
      $this->queryNull("$which INTO $table ($keys_str) VALUES $values_str");
    }
  }
  
  public function insert($table, $data) { return $this->insertOrReplace('INSERT', $table, $data); }
  public function insertIgnore($table, $data) { return $this->insertOrReplace('INSERT', $table, $data, array('ignore' => true)); }
  public function replace($table, $data) { return $this->insertOrReplace('REPLACE', $table, $data); }
  
  public function insertUpdate() {
    $args = func_get_args();
    $table = array_shift($args);
    $data = array_shift($args);
    
    if (! isset($args[0])) { // update will have all the data of the insert
      if (isset($data[0]) && is_array($data[0])) { //multiple insert rows specified -- failing!
        $this->nonSQLError("Badly formatted insertUpdate() query -- you didn't specify the update component!");
      }
      
      $args[0] = $data;
    }
    
    if (is_array($args[0])) {
      $keyval = array();
      foreach ($args[0] as $key => $value) {
        $value = $this->sanitize($value);
        $keyval[] = "`" . $key . "`=" . $value;
      }
      $updatestr = implode(', ', $keyval);
      
    } else {
      $updatestr = call_user_func_array(array($this, 'parseQueryParams'), $args);
    }
    
    return $this->insertOrReplace('INSERT', $table, $data, array('update' => $updatestr)); 
  }
  
  public function delete() {
    $args = func_get_args();
    $table = self::formatTableName(array_shift($args));
    $where = array_shift($args);
    $buildquery = "DELETE FROM $table WHERE $where";
    array_unshift($args, $buildquery);
    call_user_func_array(array($this, 'queryNull'), $args);
  }
  
  public function sqleval() {
    $args = func_get_args();
    $text = call_user_func_array(array($this, 'parseQueryParams'), $args);
    return new MeekroDBEval($text);
  }
  
  public function columnList($table) {
    return $this->queryOneColumn('Field', "SHOW COLUMNS FROM $table");
  }
  
  public function tableList($db = null) {
    if ($db) $this->useDB($db);
    $result = $this->queryFirstColumn('SHOW TABLES');
    if ($db && $this->old_db) $this->useDB($this->old_db);
    return $result;
  }
  
  public function parseQueryParamsOld() {
    $args = func_get_args();
    $sql = array_shift($args);
    $types = array_shift($args);
    $types = str_split($types);
    
    foreach ($args as $arg) {
      $type = array_shift($types);
      $pos = strpos($sql, '?');
      if ($pos === false) $this->nonSQLError("Badly formatted SQL query: $sql");  
      
      if ($type == 's') $replacement = "'" . $this->escape($arg) . "'";
      else if ($type == 'i') $replacement = intval($arg);
      else $this->nonSQLError("Badly formatted SQL query: $sql");
      
      $sql = substr_replace($sql, $replacement, $pos, 1);
    }
    return $sql;
  }
  
  public function parseQueryParamsNew() {
    $args = func_get_args();
    $sql = array_shift($args);
    $args_all = $args;
    $posList = array();
    $pos_adj = 0;
    $param_char_length = strlen($this->param_char);
    $types = array(
      $this->param_char . 'll', // list of literals
      $this->param_char . 'ls', // list of strings
      $this->param_char . 'l',  // literal
      $this->param_char . 'li', // list of integers
      $this->param_char . 'ld', // list of decimals
      $this->param_char . 'lb', // list of backticks
      $this->param_char . 's',  // string
      $this->param_char . 'i',  // integer
      $this->param_char . 'd',  // double / decimal
      $this->param_char . 'b',  // backtick
      $this->param_char . 'ss'  // search string (like string, surrounded with %'s)
    );
    
    foreach ($types as $type) {
      $lastPos = 0;
      while (($pos = strpos($sql, $type, $lastPos)) !== false) {
        $lastPos = $pos + 1;
        if (isset($posList[$pos]) && strlen($posList[$pos]) > strlen($type)) continue;
        $posList[$pos] = $type;
      }
    }
    
    ksort($posList);
    
    foreach ($posList as $pos => $type) {
      $type = substr($type, $param_char_length);
      $length_type = strlen($type) + $param_char_length;
      
      if ($arg_number_length = strspn($sql, '0123456789', $pos + $pos_adj + $length_type)) {
        $arg_number = substr($sql, $pos + $pos_adj + $length_type, $arg_number_length);
        if (! isset($args_all[$arg_number])) $this->nonSQLError("Non existent argument reference (arg $arg_number): $sql");
        
        $arg = $args_all[$arg_number];
        
      } else {
        $arg_number = 0;
        $arg = array_shift($args);
      }
      
      if (in_array($type, array('s', 'i', 'd', 'b', 'l'))) {
        $array_type = false;
        $arg = array($arg);
        $type = 'l' . $type;
      } else if ($type == 'ss') {
        $result = "'%" . $this->escape(str_replace(array('%', '_'), array('\%', '\_'), $arg)) . "%'";
      } else {
        $array_type = true;
        if (! is_array($arg)) $this->nonSQLError("Badly formatted SQL query: $sql -- expecting array, but didn't get one!");
      }
      
      if ($type == 'ls') $result = $this->wrapStr($arg, "'", true);
      else if ($type == 'li') $result = array_map('intval', $arg);
      else if ($type == 'ld') $result = array_map('floatval', $arg);
      else if ($type == 'lb') $result = array_map('$this->formatTableName', $arg);
      else if ($type == 'll') $result = $arg;
      else if (! $result) $this->nonSQLError("Badly formatted SQL query: $sql");
      
      if (is_array($result)) {
        if (! $array_type) $result = $result[0];
        else $result = '(' . implode(',', $result) . ')';
      }
      
      $sql = substr_replace($sql, $result, $pos + $pos_adj, $length_type + $arg_number_length);
      $pos_adj += strlen($result) - ($length_type + $arg_number_length);
    }
    return $sql;
  }
  
  public function parseQueryParams() {
    $args = func_get_args();
    if (count($args) < 2) return $args[0];
    
    if (is_string($args[1]) && preg_match('/^[is]+$/', $args[1]) && substr_count($args[0], '?') > 0)
      return call_user_func_array(array($this, 'parseQueryParamsOld'), $args);
    else
      return call_user_func_array(array($this, 'parseQueryParamsNew'), $args);
  }
  
  public function quickPrepare() { $args = func_get_args(); return call_user_func_array(array($this, 'query'), $args); }
  
  public function query() {
    $args = func_get_args();
    if ($this->queryMode == 'buffered' || $this->queryMode == 'unbuffered') {
      return $this->prependCall(array($this, 'queryHelper'), $args, $this->queryMode);
    } else {
      return call_user_func_array(array($this, 'queryAllRows'), $args);
    }
  }
  
  public function queryNull() { $args = func_get_args(); return $this->prependCall(array($this, 'queryHelper'), $args, 'null'); }
  public function queryRaw() { $args = func_get_args(); return $this->prependCall(array($this, 'queryHelper'), $args, 'buffered'); }
  public function queryBuf() { $args = func_get_args(); return $this->prependCall(array($this, 'queryHelper'), $args, 'buffered'); }
  public function queryUnbuf() { $args = func_get_args(); return $this->prependCall(array($this, 'queryHelper'), $args, 'unbuffered'); }
  
  protected function queryHelper() {
    $args = func_get_args();
    $type = array_shift($args);
    if ($type != 'buffered' && $type != 'unbuffered' && $type != 'null') {
      $this->nonSQLError('Error -- first argument to queryHelper must be buffered or unbuffered!');
    }
    $is_buffered = ($type == 'buffered');
    $is_null = ($type == 'null');
    
    $sql = call_user_func_array(array($this, 'parseQueryParams'), $args);
    
    $db = $this->get();
    
    if ($this->success_handler) $starttime = microtime(true);
    $result = $db->query($sql, $is_buffered ? MYSQLI_STORE_RESULT : MYSQLI_USE_RESULT);
    if ($this->success_handler) $runtime = microtime(true) - $starttime;
    
    if (!$sql || $error = $this->checkError()) {
      if ($this->error_handler) {
        $error_handler = is_callable($this->error_handler) ? $this->error_handler : 'meekrodb_error_handler';
        
        call_user_func($error_handler, array(
          'type' => 'sql',
          'query' => $sql,
          'error' => $error
        ));
      }
      
      if ($this->throw_exception_on_error) {
        $e = new MeekroDBException($error, $sql);
        throw $e;
      }
    } else if ($this->success_handler) {
      $runtime = sprintf('%f', $runtime * 1000);
      $success_handler = is_callable($this->success_handler) ? $this->success_handler : 'meekrodb_debugmode_handler';
      
      call_user_func($success_handler, array(
        'query' => $sql,
        'runtime' => $runtime
      )); 
    }
    
    $this->queryResult = $result;
    $this->queryResultType = $type;
    $this->insert_id = $db->insert_id;
    $this->affected_rows = $db->affected_rows;
    
    if ($is_buffered) $this->num_rows = $result->num_rows;
    else $this->num_rows = null;
    
    if ($is_null) {
      $this->freeResult($result);
      $this->queryResult = $this->queryResultType = null;
      return null;
    }
    
    return $result;
  }
  
  public function queryAllRows() {
    $args = func_get_args();
    
    $query = call_user_func_array(array($this, 'queryUnbuf'), $args);
    $result = $this->fetchAllRows($query);
    $this->freeResult($query);
    $this->num_rows = count($result);
    
    return $result;
  }
  
  public function queryAllArrays() {
    $args = func_get_args();
    
    $query = call_user_func_array(array($this, 'queryUnbuf'), $args);
    $result = $this->fetchAllArrays($query);
    $this->freeResult($query);
    $this->num_rows = count($result);
    
    return $result;
  }
  
  public function queryOneList() { $args = func_get_args(); return call_user_func_array(array($this, 'queryFirstList'), $args); }
  public function queryFirstList() {
    $args = func_get_args();
    $query = call_user_func_array(array($this, 'queryUnbuf'), $args);
    $result = $this->fetchArray($query);
    $this->freeResult($query);
    return $result;
  }
  
  public function queryOneRow() { $args = func_get_args(); return call_user_func_array(array($this, 'queryFirstRow'), $args); }
  public function queryFirstRow() {
    $args = func_get_args();
    $query = call_user_func_array(array($this, 'queryUnbuf'), $args);
    $result = $this->fetchRow($query);
    $this->freeResult($query);
    return $result;
  }
  
  
  public function queryFirstColumn() { 
    $args = func_get_args();
    $results = call_user_func_array(array($this, 'queryAllArrays'), $args);
    $ret = array();
    
    if (!count($results) || !count($results[0])) return $ret;
    
    foreach ($results as $row) {
      $ret[] = $row[0];
    }
    
    return $ret;
  }
  
  public function queryOneColumn() {
    $args = func_get_args();
    $column = array_shift($args);
    $results = call_user_func_array(array($this, 'queryAllRows'), $args);
    $ret = array();
    
    if (!count($results) || !count($results[0])) return $ret;
    if ($column === null) {
      $keys = array_keys($results[0]);
      $column = $keys[0];
    }
    
    foreach ($results as $row) {
      $ret[] = $row[$column];
    }
    
    return $ret;
  }
  
  public function queryFirstField() { 
    $args = func_get_args();
    $row = call_user_func_array(array($this, 'queryFirstList'), $args);
    if ($row == null) return null;    
    return $row[0];
  }
  
  public function queryOneField() {
    $args = func_get_args();
    $column = array_shift($args);
    
    $row = call_user_func_array(array($this, 'queryOneRow'), $args);
    if ($row == null) { 
      return null;
    } else if ($column === null) {
      $keys = array_keys($row);
      $column = $keys[0];
    }  
    
    return $row[$column];
  }
  
  protected function checkError() {
    $db = $this->get();
    if ($db->error) {
      $error = $db->error;
      $db->rollback();
      return $error;
    }
    
    return false;
  }
  
  public function fetchRow($result = null) {
    if ($result === null) $result = $this->queryResult;
    if (! ($result instanceof MySQLi_Result)) return null;
    return $result->fetch_assoc();
  }
  
  public function fetchAllRows($result = null) {
    $A = array();
    while ($row = $this->fetchRow($result)) {
      $A[] = $row;
    }
    return $A;
  }
  
  public function fetchArray($result = null) {
    if ($result === null) $result = $this->queryResult;
    if (! ($result instanceof MySQLi_Result)) return null;
    return $result->fetch_row();
  }
  
  public function fetchAllArrays($result = null) {
    $A = array();
    while ($row = $this->fetchArray($result)) {
      $A[] = $row;
    }
    return $A;
  }
  
  
}

class WhereClause {
  public $type = 'and'; //AND or OR
  public $negate = false;
  public $clauses = array();
  public $mdb = null;
  
  function __construct($type, $mdb=null) {
    $type = strtolower($type);
    if ($type != 'or' && $type != 'and') DB::nonSQLError('you must use either WhereClause(and) or WhereClause(or)');
    $this->type = $type;
    
    if ($mdb === null) $this->mdb = DB::getMDB();
    else if ($mdb instanceof MeekroDB) $this->mdb = $mdb;
    else DB::nonSQLError('the second argument to new WhereClause() must be an instance of class MeekroDB');
  }
  
  function add() {
    $args = func_get_args();
    if ($args[0] instanceof WhereClause) {
      $this->clauses[] = $args[0];
      return $args[0];
    } else {
      $r = call_user_func_array(array($this->mdb, 'parseQueryParams'), $args);
      $this->clauses[] = $r;
      return $r;
    }
  }
  
  function negateLast() {
    $i = count($this->clauses) - 1;
    if (!isset($this->clauses[$i])) return;
    
    if ($this->clauses[$i] instanceof WhereClause) {
      $this->clauses[$i]->negate();
    } else {
      $this->clauses[$i] = 'NOT (' . $this->clauses[$i] . ')';
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
  
  function text() {
    if (count($this->clauses) == 0) return '(1)';
    
    $A = array();
    foreach ($this->clauses as $clause) {
      if ($clause instanceof WhereClause) $clause = $clause->text();
      $A[] = '(' . $clause . ')';
    }
    
    $A = array_unique($A);
    if ($this->type == 'and') $A = implode(' AND ', $A);
    else $A = implode(' OR ', $A);
    
    if ($this->negate) $A = '(NOT ' . $A . ')';
    return $A;
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
  
  function __construct($message='', $query='') {
    parent::__construct($message);
    $this->query = $query;
  }
  
  public function getQuery() { return $this->query; }
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
  
  debug_print_backtrace();
  
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
