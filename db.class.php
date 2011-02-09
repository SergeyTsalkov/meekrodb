<?
class DB
{
  public static $debug = false;
  public static $insert_id = 0;
  public static $num_rows = 0;
  public static $affected_rows = 0;
  public static $stmt = null;
  public static $queryResult = null;
  public static $old_db = null;
  public static $current_db = null;
  public static $current_db_limit = 0;
  public static $dbName = null;
  public static $user = '';
  public static $password = '';
  public static $host = 'localhost';
  public static $encoding = 'latin1';
  public static $remap_db = array();
  public static $remap_query = false;
  
  public static function get($dbName = '') {
    static $mysql = null;
    
    if ($mysql == null) {
      if (DB::$dbName != '') $dbName = DB::$dbName;
      DB::$current_db = $dbName;
      $mysql = new mysqli(DB::$host, DB::$user, DB::$password, $dbName);
      DB::query("SET NAMES %s", DB::$encoding);
    } 
    
    return $mysql;
  }
  
  public static function debugMode() {
    DB::$debug = true;
  }
  
  public static function insertId() { return DB::$insert_id; }
  public static function numRows() { return DB::$num_rows; }
  public static function count() { return call_user_func_array('DB::numRows', func_get_args()); }
  public static function affectedRows() { return DB::$affected_rows; }
  
  public static function useDB() { return call_user_func_array('DB::setDB', func_get_args()); }
  public static function setDB($dbName, $limit=0) {
    if (DB::$remap_db[$dbName]) $dbName = DB::$remap_db[$dbName];
    
    if (DB::$current_db == $dbName) return true;
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
    DB::query('START TRANSACTION');
  }
  
  public static function commit() {
    DB::query('COMMIT');
  }
  
  public static function rollback() {
    DB::query('ROLLBACK');
  }
  
  public static function escape($str) {
    $db = DB::get($dbName);
    return $db->real_escape_string($str);
  }
  
  private static function formatTableName($table) {
    if (strpos($table, '.')) {
      list($table_db, $table_table) = explode('.', $table, 2);
      $table = "`$table_db`.`$table_table`";
    } else {
      $table = "`$table`";
    }
    
    return $table;
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
    call_user_func_array('DB::query', &$args);
  }
  
  public static function insertOrReplace($which, $table, $data) {
    $data = unserialize(serialize($data)); // break references within array
    $keys_str = implode(', ', array_map(function($x) { return "`" . $x . "`"; }, array_keys($data)));
    
    foreach ($data as &$datum) {
      if (is_array($datum)) $datum = serialize($datum);
      $datum = "'" . DB::escape($datum) . "'";
    }
    $values_str = implode(', ', array_values($data));
    
    $table = self::formatTableName($table);
    
    DB::query("$which INTO $table ($keys_str) VALUES ($values_str)");
  }
  
  public static function insert($table, $data) {
    return DB::insertOrReplace('INSERT', $table, $data);
  }
  
  public static function replace($table, $data) {
    return DB::insertOrReplace('REPLACE', $table, $data);
  }
  
  public static function columnList($table) {
    DB::query("SHOW COLUMNS FROM $table");
    $A = array();
    while ($row = DB::fetchRow()) {
      $A[] = $row['Field'];
    }
    
    return $A;
  }
  
  public static function tableList($db = null) {
    if ($db) DB::useDB($db);
    else return;
    DB::query("SHOW TABLES");
    $A = array();
    while ($row = DB::fetchRow()) {
      $A[] = $row['Tables_in_' . $db];
    }
    
    return $A;
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
        if ($posList[$pos] && strlen($posList[$pos]) > strlen($type)) continue;
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
      
      if ($type == '%ls') $result = array_map(function($x) { return "'" . DB::escape($x) . "'"; }, $arg);
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
    $args = $allArgs = func_get_args();
    
    $sql = array_shift($args);
    
    if (DB::$remap_query && count(DB::$remap_db) > 0) {
      $sql = str_replace(array_keys(DB::$remap_db), array_values(DB::$remap_db), $sql);
    }
    
    $sql = call_user_func_array('DB::parseQueryParams', $allArgs);
    
    $db = DB::get();
    
    if (DB::$debug) $starttime = microtime(true);
    $result = $db->query($sql);
    if (DB::$debug) $runtime = microtime(true) - $starttime;
    
    if (!$sql || $error = DB::checkError()) {
      echo "ATTEMPTED QUERY: $sql<br>\n";
      echo "ERROR: $error<br>\n";
      debug_print_backtrace();
      die;
    } else if (DB::$debug) {
      $runtime = sprintf('%f', $runtime * 1000);
      echo "QUERY: $sql [$runtime ms]<br>\n";
    }
    
    DB::$insert_id = $db->insert_id;
    DB::$num_rows = $result->num_rows;
    DB::$affected_rows = $db->affected_rows;
    DB::$queryResult = $result;
    
    DB::checkUseDB();
    
    return $result;
  }
  
  public static function queryAllRows() {
    $args = func_get_args();
    
    $q = call_user_func_array('DB::query', &$args);
    return DB::fetchAllRows($q);
  }
  
  public static function queryOneRow() { return call_user_func_array('DB::queryFirstRow', func_get_args()); }
  public static function queryFirstRow() {
    $args = func_get_args();
    
    call_user_func_array('DB::query', &$args);
    
    if (DB::numRows() == 0) return null;
    return DB::fetchRow();
  }
  
  public static function queryFirstColumn() {
    $args = func_get_args();
    array_unshift($args, null);
    return call_user_func_array('DB::queryOneColumn', $args);
  }
  
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
  
  public static function queryFirstField() {
    $args = func_get_args();
    array_unshift($args, null);
    return call_user_func_array('DB::queryOneField', $args);
  }
  
  public static function queryOneField() {
    $args = func_get_args();
    $column = array_shift($args);
    
    $row = call_user_func_array('DB::queryOneRow', $args);
    if ($row == null) { 
      return null;
    } else if ($field === null) {
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

?>
