<?php
class DB
{
  public static $debug = false;
  public static $insert_id = 0;
  public static $num_rows = 0;
  public static $affected_rows = 0;
  public static $stmt = null;
  public static $queryResult = null;
  public static $queryResultType = null;
  public static $old_db = null;
  public static $current_db = null;
  public static $current_db_limit = 0;
  public static $dbName = null;
  public static $user = '';
  public static $password = '';
  public static $host = 'localhost';
  public static $encoding = 'latin1';
  public static $queryMode = 'queryAllRows'; //buffered, unbuffered, queryAllRows
  public static $error_handler = 'meekrodb_error_handler';
  
  public static function get($dbName = '') {
    static $mysql = null;
    
    if ($mysql == null) {
      if (DB::$dbName != '') $dbName = DB::$dbName;
      DB::$current_db = $dbName;
      $mysql = new mysqli(DB::$host, DB::$user, DB::$password, $dbName);
      DB::queryNull("SET NAMES %s", DB::$encoding);
    } 
    
    return $mysql;
  }
  
  public static function debugMode() {
    DB::$debug = true;
  }
  
  public static function insertId() { return DB::$insert_id; }
  public static function affectedRows() { return DB::$affected_rows; }
  public static function count() { return call_user_func_array('DB::numRows', func_get_args()); }
  public static function numRows() { return DB::$num_rows; }
  
  public static function useDB() { return call_user_func_array('DB::setDB', func_get_args()); }
  public static function setDB($dbName, $limit=0) {
    $db = DB::get();
    DB::$old_db = DB::$current_db;
    if (! $db->select_db($dbName)) die("unable to set db to $dbName");
    DB::$current_db = $dbName;
    DB::$current_db_limit = $limit;
    
    if (DB::$debug) { 
      if ($limit) echo "Setting DB to $dbName for $limit queries<br>\n";
      else echo "Setting DB to $dbName for $limit queries<br>\n";
    }
  }
  
  
  public static function startTransaction() {
    DB::queryNull('START TRANSACTION');
  }
  
  public static function commit() {
    DB::queryNull('COMMIT');
  }
  
  public static function rollback() {
    DB::queryNull('ROLLBACK');
  }
  
  public static function escape($str) {
    $db = DB::get(DB::$dbName);
    return $db->real_escape_string($str);
  }
  
  private static function formatTableName($table) {
    $table = str_replace('`', '', $table);
    if (strpos($table, '.')) {
      list($table_db, $table_table) = explode('.', $table, 2);
      $table = "`$table_db`.`$table_table`";
    } else {
      $table = "`$table`";
    }
    
    return $table;
  }
  
  private static function prependCall($function, $args, $prepend) {
    array_unshift($args, $prepend);
    return call_user_func_array($function, $args);
  }
  
  private static function wrapStr($strOrArray, $wrapChar, $escape = false) {
    if (! is_array($strOrArray)) {
      if ($escape) return $wrapChar . DB::escape($strOrArray) . $wrapChar;
      else return $wrapChar . $strOrArray . $wrapChar;
    } else {
      $R = array();
      foreach ($strOrArray as $element) {
        $R[] = DB::wrapStr($element, $wrapChar, $escape);
      }
      return $R;
    }
      
  }
  
  public static function freeResult($result) {
    if (! ($result instanceof MySQLi_Result)) return;
    return $result->free();
  }
  
  public static function update() {
    $args = func_get_args();
    $table = array_shift($args);
    $params = array_shift($args);
    $where = array_shift($args);
    $buildquery = "UPDATE " . self::formatTableName($table) . " SET ";
    $keyval = array();
    foreach ($params as $key => $value) {
      $keyval[] = "`" . $key . "`=" . (is_int($value) ? $value : "'" . DB::escape($value) . "'");
    }
    
    $buildquery = "UPDATE " . self::formatTableName($table) . " SET " . implode(', ', $keyval) . " WHERE " . $where;
    array_unshift($args, $buildquery);
    call_user_func_array('DB::queryNull', $args);
  }
  
  public static function insertOrReplace($which, $table, $data) {
    $data = unserialize(serialize($data)); // break references within array
    $keys_str = implode(', ', DB::wrapStr(array_keys($data), '`'));
    
    foreach ($data as &$datum) {
      if (is_array($datum)) $datum = serialize($datum);
      $datum = "'" . DB::escape($datum) . "'";
    }
    $values_str = implode(', ', array_values($data));
    
    $table = self::formatTableName($table);
    
    DB::queryNull("$which INTO $table ($keys_str) VALUES ($values_str)");
  }
  
  public static function insert($table, $data) {
    return DB::insertOrReplace('INSERT', $table, $data);
  }
  
  public static function replace($table, $data) {
    return DB::insertOrReplace('REPLACE', $table, $data);
  }
  
  public static function columnList($table) {
    return DB::queryOneColumn('Field', "SHOW COLUMNS FROM $table");
  }
  
  public static function tableList($db = null) {
    if ($db) DB::useDB($db);
    $db = DB::$current_db;
    return DB::queryOneColumn('Tables_in_' . $db, "SHOW TABLES");
  }
  
  private static function checkUseDB() {
    if (DB::$current_db_limit > 0) { 
      DB::$current_db_limit -= 1;
      if (DB::$current_db_limit == 0) DB::useDB(DB::$old_db);
    }
  }
  
  public static function parseQueryParamsOld() {
    $args = func_get_args();
    $sql = array_shift($args);
    $types = array_shift($args);
    $types = str_split($types);
    
    foreach ($args as $arg) {
      $type = array_shift($types);
      $pos = strpos($sql, '?');
      if ($pos === false) die("Badly formatted SQL query: $sql");
      
      if ($type == 's') $replacement = "'" . DB::escape($arg) . "'";
      else if ($type == 'i') $replacement = intval($arg);
      else die("Badly formatted SQL query: $sql");
      
      $sql = substr_replace($sql, $replacement, $pos, 1);
    }
    return $sql;
  }
  
  /*
    %s = string
    %i = integer
    %d = decimal / double
    %b = backtick
    %l = literal
    
    %ls = list of strings
    %li = list of integers
    %ld = list of doubles
    %ll = list of literals
    %lb = list of backticks
  */
  
  public static function parseQueryParamsNew() {
    $args = func_get_args();
    $sql = array_shift($args);
    $posList = array();
    $pos_adj = 0;
    $types = array('%ll', '%ls', '%l', '%li', '%ld', '%lb', '%s', '%i', '%d', '%b', '%ss');
    
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
      $arg = array_shift($args);
      
      if (in_array($type, array('%s', '%i', '%d', '%b', '%l'))) {
        $array_type = false;
        $arg = array($arg);
        $length_type = strlen($type);
        $type = '%l' . substr($type, 1);
      } else if ($type == '%ss') {
        $result = "'%" . DB::escape(str_replace(array('%', '_'), array('\%', '\_'), $arg)) . "%'";
        $length_type = strlen($type);
      } else {
        $array_type = true;
        $length_type = strlen($type);
        if (! is_array($arg)) die("Badly formatted SQL query: $sql -- expecting array, but didn't get one!");
      }
      
      if ($type == '%ls') $result = DB::wrapStr($arg, "'", true);
      else if ($type == '%li') $result = array_map('intval', $arg);
      else if ($type == '%ld') $result = array_map('floatval', $arg);
      else if ($type == '%lb') $result = array_map('DB::formatTableName', $arg);
      else if ($type == '%ll') $result = $arg;
      else if (! $result) die("Badly formatted SQL query: $sql");
      
      if (is_array($result)) {
        if (! $array_type) $result = $result[0];
        else $result = '(' . implode(',', $result) . ')';
      }
      
      $sql = substr_replace($sql, $result, $pos + $pos_adj, $length_type);
      $pos_adj += strlen($result) - $length_type;
    }
    return $sql;
  }
  
  public static function parseQueryParams() {
    $args = func_get_args();
    if (count($args) < 2) return $args[0];
    
    if (is_string($args[1]) && preg_match('/^[is]+$/', $args[1]) && substr_count($args[0], '?') > 0)
      return call_user_func_array('DB::parseQueryParamsOld', $args);
    else
      return call_user_func_array('DB::parseQueryParamsNew', $args);
  }
  
  public static function quickPrepare() { return call_user_func_array('DB::query', func_get_args()); }
  
  public static function query() {
    if (DB::$queryMode == 'buffered' || DB::$queryMode == 'unbuffered') {
      return DB::prependCall('DB::queryHelper', func_get_args(), DB::$queryMode);
    } else {
      return call_user_func_array('DB::queryAllRows', func_get_args());
    }
  }
  
  public static function queryNull() { return DB::prependCall('DB::queryHelper', func_get_args(), 'null'); }
  public static function queryBuf() { return DB::prependCall('DB::queryHelper', func_get_args(), 'buffered'); }
  public static function queryUnbuf() { return DB::prependCall('DB::queryHelper', func_get_args(), 'unbuffered'); }
  
  public static function queryHelper() {
    $args = func_get_args();
    $type = array_shift($args);
    if ($type != 'buffered' && $type != 'unbuffered' && $type != 'null') die("Error -- first argument to queryHelper must be buffered or unbuffered!");
    $is_buffered = ($type == 'buffered');
    $is_null = ($type == 'null');
    
    $sql = call_user_func_array('DB::parseQueryParams', $args);
    
    $db = DB::get();
    
    if (DB::$debug) $starttime = microtime(true);
    $result = $db->query($sql, $is_buffered ? MYSQLI_STORE_RESULT : MYSQLI_USE_RESULT);
    if (DB::$debug) $runtime = microtime(true) - $starttime;
    
    if (!$sql || $error = DB::checkError()) {
      if (function_exists(DB::$error_handler)) {
        call_user_func(DB::$error_handler, array(
          'query' => $sql,
          'error' => $error
        ));
      }
    } else if (DB::$debug) {
      $runtime = sprintf('%f', $runtime * 1000);
      $sqlShow = "$sql (" . ($is_buffered ? 'MYSQLI_STORE_RESULT' : 'MYSQLI_USE_RESULT') . ")";
      echo "QUERY: $sqlShow [$runtime ms]<br>\n";
    }
    
    DB::$queryResult = $result;
    DB::$queryResultType = $type;
    DB::$insert_id = $db->insert_id;
    DB::$affected_rows = $db->affected_rows;
    
    if ($is_buffered) DB::$num_rows = $result->num_rows;
    else DB::$num_rows = null;
    
    //TODO: fix DB switch back 
    //DB::checkUseDB();
    
    if ($is_null) {
      DB::freeResult($result);
      DB::$queryResult = DB::$queryResultType = null;
      return null;
    }
    
    return $result;
  }
  
  public static function queryAllRows() {
    $args = func_get_args();
    
    $query = call_user_func_array('DB::queryUnbuf', $args);
    $result = DB::fetchAllRows($query);
    DB::freeResult($query);
    DB::$num_rows = count($result);
    
    return $result;
  }
  
  public static function queryOneRow() { return call_user_func_array('DB::queryFirstRow', func_get_args()); }
  public static function queryFirstRow() {
    $args = func_get_args();
    $query = call_user_func_array('DB::queryUnbuf', $args);
    $result = DB::fetchRow($query);
    DB::freeResult($query);
    return $result;
  }
  
  
  public static function queryFirstColumn() { return DB::prependCall('DB::queryOneColumn', func_get_args(), null); }
  public static function queryOneColumn() {
    $args = func_get_args();
    $column = array_shift($args);
    $results = call_user_func_array('DB::queryAllRows', $args);
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
  
  public static function queryFirstField() { return DB::prependCall('DB::queryOneField', func_get_args(), null); }
  public static function queryOneField() {
    $args = func_get_args();
    $column = array_shift($args);
    
    $row = call_user_func_array('DB::queryOneRow', $args);
    if ($row == null) { 
      return null;
    } else if ($column === null) {
      $keys = array_keys($row);
      $column = $keys[0];
    }  
    
    return $row[$column];
  }
  
  private static function checkError() {
    $db = DB::get();
    if ($db->error) {
      $error = $db->error;
      $db->rollback();
      return $error;
    }
    
    return false;
  }
  
  public static function fetchRow($result = null) {
    if ($result === null) $result = DB::$queryResult;
    if (! ($result instanceof MySQLi_Result)) return null;
    return $result->fetch_assoc();
  }
  
  public static function fetchAllRows($result = null) {
    $A = array();
    while ($row = DB::fetchRow($result)) {
      $A[] = $row;
    }
    return $A;
  }
}

class WhereClause {
  public $type = 'and'; //AND or OR
  public $negate = false;
  public $clauses = array();
  
  function __construct($type) {
    $this->type = strtolower($type);
  }
  
  function add() {
    $args = func_get_args();
    if ($args[0] instanceof WhereClause) {
      $this->clauses[] = $args[0];
      return $args[0];
    } else {
      $r = call_user_func_array('DB::parseQueryParams', $args);
      $this->clauses[] = $r;
      return $r;
    }
  }
  
  function negateLast() {
    $i = count($this->clauses) - 1;
    $this->clauses[$i] = 'NOT (' . $this->clauses[$i] . ')';
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
  
  function text($minimal = false) {
    if (count($this->clauses) == 0) {
      if ($minimal) return '(1)';
      else return '';
    }
    
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

function meekrodb_error_handler($params) {
  $out[] = "QUERY: " . $params['query'];
  $out[] = "ERROR: " . $params['error'];
  $out[] = "";
  
  if (php_sapi_name() == 'cli' && empty($_SERVER['REMOTE_ADDR'])) {
    echo implode("\n", $out);
  } else {
    echo implode("<br>\n", $out);
  }
  
  debug_print_backtrace();
  
  die;
}

?>
