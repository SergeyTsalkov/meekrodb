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

if (! extension_loaded('pdo')) {
  throw new Exception("MeekroDB requires the pdo extension for PHP");
}

/**
 * @link https://meekro.com/docs/retrieving-data.html Retrieving Data
 * 
 * @method static mixed query(string $query, ...$parameters)
 * @method static mixed queryFirstRow(string $query, ...$parameters)
 * @method static mixed queryFirstField(string $query, ...$parameters)
 * @method static mixed queryFirstList(string $query, ...$parameters)
 * @method static mixed queryFirstColumn(string $query, ...$parameters)
 * @method static mixed queryFullColumns(string $query, ...$parameters)
 * @method static mixed queryWalk(string $query, ...$parameters)
 * 
 * @link https://meekro.com/docs/altering-data.html Altering Data
 * 
 * @method static int insert(string $table_name, array $data, ...$parameters)
 * @method static mixed insertId()
 * @method static int insertIgnore(string $table_name, array $data, ...$parameters)
 * @method static int insertUpdate(string $table_name, array $data, ...$parameters)
 * @method static int replace(string $table_name, array $data, ...$parameters)
 * @method static int update(string $table_name, array $data, ...$parameters)
 * @method static int delete(string $table_name, ...$parameters)
 * @method static int affectedRows()
 * 
 * @link https://meekro.com/docs/transactions.html Transactions
 * 
 * @method static int startTransaction()
 * @method static int commit()
 * @method static int rollback()
 * @method static int transactionDepth()
 * 
 * @link https://meekro.com/docs/hooks.html
 * 
 * @method static int addHook(string $hook_type, callable $fn)
 * @method static void removeHook(string $hook_type, int $hook_id)
 * @method static void removeHooks(string $hook_type)
 * 
 * @link https://meekro.com/docs/misc-methods.html Misc Methods and Variables
 * 
 * @method static void useDB(string $database_name)
 * @method static array tableList(?string $database_name = null)
 * @method static array columnList(string $table_name)
 * @method static void disconnect()
 * @method static PDO get()
 * @method static string lastQuery()
 * @method static mixed parse(string $query, ...$parameters)
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
  public static $connect_options = array(PDO::ATTR_TIMEOUT => 30, PDO::ATTR_STRINGIFY_FETCHES => true);
  public static $dsn = null;
  
  // configure workings
  public static $param_char = '%';
  public static $named_param_seperator = '_';
  public static $nested_transactions = false;
  public static $reconnect_after = 14400;
  public static $logfile;
  
  // internal
  protected static $mdb = null;
  public static $variables_to_sync = array(
    // connection variables
    'dbName', 'user', 'password', 'host', 'port', 'socket', 'encoding', 'connect_options', 'dsn',
    // usage variables
    'param_char', 'named_param_seperator', 'nested_transactions', 'reconnect_after', 'logfile'
  );
  
  public static function getMDB() {
    if (DB::$mdb === null) {
      DB::$mdb = new MeekroDB();
    }

    // Sync everytime because settings might have changed. It's fast.
    DB::$mdb->sync_config(); 
    return DB::$mdb;
  }

  public static function __callStatic($name, $args) {
    $fn = array(DB::getMDB(), $name);
    if (! is_callable($fn)) {
      throw new MeekroDBException("MeekroDB does not have a method called $name");
    }

    return call_user_func_array($fn, $args);
  }

  /**
   * @deprecated
   */
  static function debugMode($enable=true) {
    if ($enable) self::$logfile = fopen('php://output', 'w');
    else self::$logfile = null;
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
  public $connect_options = array(PDO::ATTR_TIMEOUT => 30, PDO::ATTR_STRINGIFY_FETCHES => true);
  public $dsn = '';
  
  // configure workings
  public $param_char = '%';
  public $named_param_seperator = '_';
  public $nested_transactions = false;
  public $reconnect_after = 14400;
  public $logfile;
  
  // internal
  public $internal_pdo = null;
  public $db_type = 'mysql';
  public $affected_rows = 0;
  public $nested_transactions_count = 0;
  public $last_query;
  public $last_query_at=0;

  protected $hooks = array(
    'pre_run' => array(),
    'post_run' => array(),
    'run_success' => array(),
    'run_failed' => array(),
  );

  public function __construct($dsn='', $user='', $password='', $opts=array()) {
    $this->sync_config();

    if ($dsn) $this->dsn = $dsn;
    if ($user) $this->user = $user;
    if ($password) $this->password = $password;
    if ($opts) $this->connect_options = $opts;
  }

  /**
   * @internal 
   * suck in config settings from static class
   */
  public function sync_config() {
    foreach (DB::$variables_to_sync as $variable) {
      $this->$variable = DB::$$variable;
    }
  }

  public function db_type() {
    // $this->db_type var is only set after we connect, so we have to
    // make sure we've connected before returning the info
    $this->get();
    return $this->db_type;
  }
  
  public function get() {
    $pdo = $this->internal_pdo;
    
    if (!($pdo instanceof PDO)) {
      if (! $this->dsn) {
        $dsn = array('host' => $this->host ?: 'localhost');
        if ($this->dbName) $dsn['dbname'] = $this->dbName;
        if ($this->port) $dsn['port'] = $this->port;
        if ($this->socket) $dsn['unix_socket'] = $this->socket;
        if ($this->encoding) $dsn['charset'] = $this->encoding;
        $dsn_parts = array();
        foreach ($dsn as $key => $value) {
          $dsn_parts[] = $key . '=' . $value;
        }
        $this->dsn = 'mysql:' . implode(';', $dsn_parts);
      }

      list($this->db_type) = explode(':', $this->dsn);
      if (!$this->db_type) {
        throw new MeekroDBException("Invalid DSN: " . $this->dsn);
      }

      try {
        $pdo = new PDO($this->dsn, $this->user, $this->password, $this->connect_options);
        $this->internal_pdo = $pdo;
      } catch (PDOException $e) {
        throw new MeekroDBException($e->getMessage());
      }
      
    }
    
    return $pdo;
  }
  
  public function disconnect() {
    $this->internal_pdo = null;
  }

  public function serverVersion() { return $this->get()->getAttribute(PDO::ATTR_SERVER_VERSION); }
  public function transactionDepth() { return $this->nested_transactions_count; }
  public function insertId() { return $this->get()->lastInsertId(); }
  public function affectedRows() { return $this->affected_rows; }
  
  public function lastQuery() { return $this->last_query; }
  
  public function setDB() { return $this->useDB(...func_get_args()); }
  public function useDB($dbName) { 
    $db_type = $this->db_type();
    if (in_array($db_type, array('pgsql', 'sqlite'))) {
      throw new MeekroDBException("Database switching not supported by {$db_type}.");
    }

    $this->_query('useDB', "USE :c", $dbName);
  }
  
  public function startTransaction() {
    $start_transaction = 'START TRANSACTION';
    if ($this->db_type() == 'sqlite') {
      $start_transaction = 'BEGIN TRANSACTION';
    }

    if ($this->nested_transactions && $this->nested_transactions_count > 0) {
      $this->_query('startTransaction', "SAVEPOINT LEVEL{$this->nested_transactions_count}");
      $this->nested_transactions_count++;
    } else {
      $this->_query('startTransaction', $start_transaction);
      $this->nested_transactions_count = 1;
    }

    return $this->nested_transactions_count;
  }
  
  public function commit($all=false) {
    $this->nested_transactions_count--;
    if ($all || $this->nested_transactions_count < 0) {
      $this->nested_transactions_count = 0;
    }

    if ($this->nested_transactions_count > 0) {
      $this->_query('commit', "RELEASE SAVEPOINT LEVEL{$this->nested_transactions_count}");
    } else {
      $this->_query('commit', 'COMMIT');
    }
    
    return $this->nested_transactions_count;
  }
  
  public function rollback($all=false) {
    $this->nested_transactions_count--;
    if ($all || $this->nested_transactions_count < 0) {
      $this->nested_transactions_count = 0;
    }

    if ($this->nested_transactions_count > 0) {
      $this->_query('rollback', "ROLLBACK TO SAVEPOINT LEVEL{$this->nested_transactions_count}");
    } else {
      $this->_query('rollback', 'ROLLBACK');
    }
    
    return $this->nested_transactions_count;
  }

  public function query() {
    return $this->queryHelper(['assoc' => true, 'name' => 'query'], func_get_args());
  }

  public function queryFullColumns() {
    return $this->queryHelper(['fullcols' => true, 'name' => 'queryFullColumns'], func_get_args());
  }

  public function queryWalk() {
    return $this->queryHelper(['walk' => true, 'name' => 'queryWalk'], func_get_args());
  }

  public function queryFirstRow() {
    $result = $this->queryHelper(['assoc' => true, 'name' => 'queryFirstRow'], func_get_args());
    if (!$result || !is_array($result)) return null;
    return reset($result);
  }

  public function queryAllLists() { 
    return $this->queryHelper(['name' => 'queryAllLists'], func_get_args()); 
  }
  
  public function queryFirstList() {
    $result = $this->queryHelper(['name' => 'queryFirstList'], func_get_args()); 
    if (!$result || !is_array($result)) return null;
    return reset($result);
  }
  
  public function queryFirstColumn() { 
    $results = $this->queryHelper(['name' => 'queryFirstColumn'], func_get_args()); 
    $ret = [];
    if (!count($results) || !count($results[0])) return $ret;
    
    foreach ($results as $row) {
      $ret[] = $row[0];
    }
    
    return $ret;
  }
  
  public function queryFirstField() {
    $results = $this->queryHelper(['name' => 'queryFirstField'], func_get_args());
    if (!is_array($results) || !$results) return null;
    $row = $results[0];
    if (!is_array($row) || !$row) return null;
    return $row[0];
  }
  
  public function update() {
    $args = func_get_args();
    if (count($args) < 3) {
      throw new MeekroDBException("update(): at least 3 arguments expected");
    }

    $table = array_shift($args);
    $params = array_shift($args);
    if (! is_array($params)) {
      throw new MeekroDBException("update(): second argument must be assoc array");
    }
    $ParsedQuery = $this->_parse('UPDATE :b SET :hc WHERE ', $table, $params);

    if (is_array($args[0])) {
      $Where = $this->_parse(':ha', $args[0]);
    } else {
      // we don't know if they used named or numbered args, so the where clause
      // must be run through the parser separately
      $Where = $this->parse(...$args);
    }
    
    $ParsedQuery->add($Where);
    return $this->_query('update', $ParsedQuery);
  }

  public function delete() {
    $args = func_get_args();
    if (count($args) < 2) {
      throw new MeekroDBException("delete(): at least 2 arguments expected");
    }

    $table = array_shift($args);
    $ParsedQuery = $this->_parse('DELETE FROM :b WHERE ', $table);

    if (is_array($args[0])) {
      $Where = $this->_parse(':ha', $args[0]);
    } else {
      $Where = $this->parse(...$args);
    }

    $ParsedQuery->add($Where);
    return $this->_query('delete', $ParsedQuery);
  }
  
  protected function insertOrReplace($mode, $table, $datas, $options=array()) {
    $db_type = $this->db_type();
    $fn_name = 'insert';

    if ($mode == 'insert') {
      $action = 'INSERT';
    } else if ($mode == 'ignore') {
      $fn_name = 'insertIgnore';
      if ($db_type == 'sqlite') {
        $action = 'INSERT';
      }
      else if ($db_type == 'pgsql') {
        throw new MeekroDBException("postgres does not support insertIgnore()");
      }
      else {
        $action = 'INSERT IGNORE';
      }
    } else if ($mode == 'replace') {
      $fn_name = 'replace';
      $action = 'REPLACE';
    } else {
      throw new MeekroDBException("insertOrReplace() mode must be: insert, ignore, replace");
    }

    $datas = unserialize(serialize($datas)); // break references within array
    $keys = $values = array();
    
    if (isset($datas[0]) && is_array($datas[0])) {
      foreach ($datas as $datum) {
        ksort($datum);
        if (! $keys) $keys = array_keys($datum);
        $values[] = array_values($datum);  
      }

      $ParsedQuery = $this->_parse(':l INTO :b :lc VALUES :ll?', $action, $table, $keys, $values);
    }
    else {
      $keys = array_keys($datas);
      $values = array_values($datas);

      $ParsedQuery = $this->_parse(':l INTO :b :lc VALUES :l?', $action, $table, $keys, $values);
    }

    $do_update = $mode == 'insert' && isset($options['update']) 
      && is_array($options['update']) && $options['update'];

    if ($mode == 'ignore' && $db_type == 'sqlite') {
      $ParsedQuery->add(' ON CONFLICT DO NOTHING');
    }
    else if ($do_update) {
      $fn_name = 'insertUpdate';
      if ($db_type == 'sqlite') {
        $on_duplicate = 'ON CONFLICT DO UPDATE SET';
        
        $sqlite_version = $this->serverVersion();
        if ($sqlite_version < '3.35') {
          throw new MeekroDBException("sqlite {$sqlite_version} does not support insertUpdate(), please upgrade");
        }
      }
      else if ($db_type == 'pgsql') {
        throw new MeekroDBException("postgres does not support insertUpdate()");
      }
      else {
        $on_duplicate = 'ON DUPLICATE KEY UPDATE';
      }
      $ParsedQuery->add(" {$on_duplicate} ");

      if (array_values($options['update']) !== $options['update']) {
        $Update = $this->_parse(':hc', $options['update']);
      }
      else {
        $update_str = array_shift($options['update']);
        $Update = $this->parse($update_str, ...$options['update']);
      }

      $ParsedQuery->add($Update);
    }

    return $this->_query($fn_name, $ParsedQuery);
  }
  
  public function insert($table, $data) { return $this->insertOrReplace('insert', $table, $data); }
  public function insertIgnore($table, $data) { return $this->insertOrReplace('ignore', $table, $data); }
  public function replace($table, $data) { return $this->insertOrReplace('replace', $table, $data); }
  
  public function insertUpdate() {
    $args = func_get_args();
    $table = array_shift($args);
    $data = array_shift($args);
    
    if (! isset($args[0])) { // update will have all the data of the insert
      if (isset($data[0]) && is_array($data[0])) { //multiple insert rows specified -- failing!
        throw new MeekroDBException("Badly formatted insertUpdate() query -- you didn't specify the update component!");
      }
      
      $args[0] = $data;
    }
    
    if (is_array($args[0])) $update = $args[0];
    else $update = $args;
    
    return $this->insertOrReplace('insert', $table, $data, array('update' => $update)); 
  }
  
  public function sqleval() {
    return $this->parse(...func_get_args());
  }
  
  public function columnList($table) {
    $db_type = $this->db_type();

    if ($db_type == 'sqlite') {
      $query = 'PRAGMA table_info(:b)';
      $primary = 'name';
    }
    else if ($db_type == 'pgsql') {
      $query = 'SELECT column_name, data_type, is_nullable, column_default 
        FROM information_schema.columns WHERE table_name=:s
        ORDER BY ordinal_position';
      $primary = 'column_name';
    }
    else {
      $query = 'SHOW COLUMNS FROM :b';
      $primary = 'Field';
    }

    $data = $this->_query('columnList', $query, $table);
    $columns = array();
    foreach ($data as $row) {
      $key = $row[$primary];
      $row2 = array();
      foreach ($row as $name => $value) {
        $row2[strtolower($name)] = $value;
      }

      $columns[$key] = $row2;
    }

    return $columns;
  }
  
  public function tableList($db = null) {
    if ($this->db_type() == 'sqlite') {
      if ($db) $tbl = "{$db}.sqlite_master";
      else $tbl = "sqlite_master";

      $result = $this->_query('tableList', "SELECT name FROM :b 
        WHERE type='table' AND name NOT LIKE 'sqlite_%'", $tbl);
    }
    else if ($this->db_type() == 'pgsql') {
      $result = $this->_query('tableList', "SELECT table_name
        FROM information_schema.tables
        WHERE table_schema='public'
        ORDER BY table_name");
    }
    else {
      if ($db) {
        $result = $this->_query('tableList', 'SHOW TABLES FROM :c', $db);
      } else {
        $result = $this->_query('tableList', 'SHOW TABLES');
      }
    }

    $column = array();
    foreach ($result as $row) {
      $column[] = reset($row);
    }
    return $column;
  }


  // *************** PARSER AND QUERY RUNNER
  protected function paramsMap() {
    $t = $this;

    $placeholders = function($count, $batches = 1) {
      $question_marks = '(' . implode(',', array_fill(0, $count, '?')) . ')';
      return implode(',', array_fill(0, $batches, $question_marks));
    };

    $join = function(array $Queries, $glue=',', $start='', $end='') {
      $Master = new MeekroDBParsedQuery();
      $parts = array();
      foreach ($Queries as $Query) {
        $parts[] = $Query->query;
        $Master->add('', $Query->params);
      }

      $Master->add($start . implode($glue, $parts) . $end);
      return $Master;
    };

    return array(
      's' => function($arg) use ($t) { 
        return new MeekroDBParsedQuery('?', array(strval($arg)));
      },
      'i' => function($arg) use ($t) { 
        return new MeekroDBParsedQuery('?', array($t->intval($arg)));
      },
      'd' => function($arg) use ($t) { 
        return new MeekroDBParsedQuery('?', array(doubleval($arg)));
      },
      'b' => function($arg) use ($t) { 
        return new MeekroDBParsedQuery($t->formatName($arg, true));
      },
      'c' => function($arg) use ($t) {
        return new MeekroDBParsedQuery($t->formatName($arg));
      },
      'l' => function($arg) use ($t) { 
        return new MeekroDBParsedQuery(strval($arg));
      },
      't' => function($arg) use ($t) {
        return new MeekroDBParsedQuery('?', $t->sanitizeTS($arg));
      },
      'ss' => function($arg) use ($t) { 
        $str = '%' . str_replace(array('%', '_'), array('\%', '\_'), $arg) . '%';
        return new MeekroDBParsedQuery('?', array($str));
      },
      'ls' => function($arg) use ($t, $placeholders) {
        $arg = array_map('strval', $arg);
        return new MeekroDBParsedQuery($placeholders(count($arg)), $arg);
      },
      'li' => function($arg) use ($t, $placeholders) { 
        $arg = array_map(array($t, 'intval'), $arg);
        return new MeekroDBParsedQuery($placeholders(count($arg)), $arg);
      },
      'ld' => function($arg) use ($t, $placeholders) {
        $arg = array_map('doubleval', $arg);
        return new MeekroDBParsedQuery($placeholders(count($arg)), $arg);
      },
      'lb' => function($arg) use ($t) { 
        $str = '('. implode(',', array_map(array($t, 'formatName'), $arg)) . ')';
        return new MeekroDBParsedQuery($str);
      },
      'lc' => function($arg) use ($t) { 
        $str = '('. implode(',', array_map(array($t, 'formatName'), $arg)) . ')';
        return new MeekroDBParsedQuery($str);
      },
      'lt' => function($arg) use ($t, $placeholders) { 
        $arg = array_map(array($t, 'sanitizeTS'), $arg);
        return new MeekroDBParsedQuery($placeholders(count($arg)), $arg);
      },
      '?' => function($arg) use ($t) {
        return $t->sanitize($arg);
      },
      'l?' => function($arg) use ($t, $join) {
        $Queries = array_map(array($t, 'sanitize'), $arg);
        return $join($Queries, ',', '(', ')');
      },
      'll?' => function($arg) use ($t, $join) {
        $arg = array_values($arg);
        
        $count = count($arg); // number of entries to insret
        $length = null; // length of entry
        $Master = array(); // list of queries

        foreach ($arg as $entry) {
          if (! is_array($entry)) {
            throw new MeekroDBException("ll? must be used with a list of assoc arrays");
          }
          if (is_null($length)) {
            $length = count($entry);
          }
          if (count($entry) != $length) {
            throw new MeekroDBException("ll?: all entries must be the same length");
          }

          $Queries = array_map(array($t, 'sanitize'), $entry);
          $Master[] = $join($Queries, ',', '(', ')');
        }

        return $join($Master, ',');
      },
      'hc' => function($arg) use ($t, $join) {
        $Queries = array();
        foreach ($arg as $key => $value) {
          $key = $t->formatName($key);
          $Query = $t->sanitize($value);
          $Queries[] = new MeekroDBParsedQuery($key . '=' . $Query->query, $Query->params);
        }
        return $join($Queries, ',');
      },
      'ha' => function($arg) use ($t, $join) {
        $Queries = array();
        foreach ($arg as $key => $value) {
          $key = $t->formatName($key);
          $Query = $t->sanitize($value);
          $Queries[] = new MeekroDBParsedQuery($key . '=' . $Query->query, $Query->params);
        }
        return $join($Queries, ' AND ');
      },
      'ho' => function($arg) use ($t, $join) {
        $Queries = array();
        foreach ($arg as $key => $value) {
          $key = $t->formatName($key);
          $Query = $t->sanitize($value);
          $Queries[] = new MeekroDBParsedQuery($key . '=' . $Query->query, $Query->params);
        }
        return $join($Queries, ' OR ');
      },

      $this->param_char => function($arg) use ($t) {
        return new MeekroDBParsedQuery($t->param_char);
      },
    );
  }

  protected function paramsMapArrayTypes() {
    return array('ls', 'li', 'ld', 'lb', 'lc', 'll', 'lt', 'l?', 'll?', 'hc', 'ha', 'ho');
  }
  protected function paramsMapOptArrayTypes() {
    return array('b', 'c');
  }

  /**
   * @internal has to be public for PHP 5.3 compatability
   */
  public function sanitizeTS($ts) {
    if (is_string($ts)) {
      return date('Y-m-d H:i:s', strtotime($ts));
    }
    else if ($ts instanceof DateTime) {
      return $ts->format('Y-m-d H:i:s');
    }
    return '';
  }

  /**
   * @internal has to be public for PHP 5.3 compatability
   */
  public function sanitize($input) {
    if (is_object($input)) {
      if ($input instanceof DateTime) {
        return new MeekroDBParsedQuery('?', array($input->format('Y-m-d H:i:s')));
      }
      if ($input instanceof MeekroDBParsedQuery) {
        return $input;
      }
      return new MeekroDBParsedQuery('?', array(strval($input)));
    }

    if (is_null($input)) return new MeekroDBParsedQuery('NULL');
    else if (is_bool($input)) return new MeekroDBParsedQuery('?', array($input ? 1 : 0));
    else if (is_array($input)) return new MeekroDBParsedQuery('');
    return new MeekroDBParsedQuery('?', array($input));
  }

  /**
   * @internal has to be public for PHP 5.3 compatability
   */
  public function formatName($name, $can_join=false) {
    if (is_array($name)) {
      if ($can_join) return implode('.', array_map(array($this, 'formatName'), $name));
      throw new MeekroDBException("Invalid column/table name");
    }
    $char = '`';
    if ($this->db_type() == 'pgsql') $char = '"';
    return $char . str_replace($char, $char . $char, $name) . $char; 
  }
  
  /**
   * @internal has to be public for PHP 5.3 compatability
   */
  public function intval($var) {
    if (PHP_INT_SIZE == 8) return intval($var);
    return floor(doubleval($var));
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
      'val' => '',
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

    if ($use_named_args) {
      if ($use_numbered_args) {
        throw new MeekroDBException("You can't mix named and numbered args!");
      }

      if (count($args) != 1 || !is_array($args[0])) {
        throw new MeekroDBException("If you use named args, you must pass an assoc array of args!");
      }
    }

    if ($use_numbered_args) {
      if ($max_numbered_arg+1 > count($args)) {
        throw new MeekroDBException(sprintf('Expected %d args, but only got %d!', $max_numbered_arg+1, count($args)));
      }
    }

    foreach ($queryParts as &$Part) {
      if (is_string($Part)) continue;

      if (!is_null($Part['named_arg'])) {
        $key = $Part['named_arg'];
        if (! array_key_exists($key, $args[0])) {
          throw new MeekroDBException("Couldn't find named arg {$key}!");
        }

        $Part['val'] = $args[0][$key];
      }
      else if (!is_null($Part['arg'])) {
        $key = $Part['arg'];
        $Part['val'] = $args[$key];
      }
    }
    
    return $queryParts;
  }

  function parse($query) {
    $args = func_get_args();
    array_shift($args);

    $ParsedQuery = new MeekroDBParsedQuery();
    if (! $args) {
      $ParsedQuery->add($query);
      return $ParsedQuery;
    }

    $Map = $this->paramsMap();
    $array_types = $this->paramsMapArrayTypes();
    $opt_array_types = $this->paramsMapOptArrayTypes();
    foreach ($this->preParse($query, $args) as $Part) {
      if (is_string($Part)) {
        $ParsedQuery->add($Part);
        continue;
      }

      $fn = $Map[$Part['type']];
      $is_array_type = in_array($Part['type'], $array_types, true);
      $is_opt_array_type = in_array($Part['type'], $opt_array_types, true);

      $key = is_null($Part['named_arg']) ? $Part['arg'] : $Part['named_arg'];
      $val = $Part['val'];

      if ($is_array_type && !is_array($val)) {
        throw new MeekroDBException("Expected an array for arg $key but didn't get one!");
      }
      if (!$is_array_type && !$is_opt_array_type && is_array($val)) {
        $val = '';
      }
      if (is_array($val) && count($val) == 0) {
        throw new MeekroDBException("Arg {$key} array can't be empty!");
      }
      
      if ($val instanceof WhereClause) {
        if ($Part['type'] != 'l') {
          throw new MeekroDBException("WhereClause must be used with l arg, you used {$Part['type']} instead!");
        }

        list($clause_sql, $clause_args) = $val->textAndArgs();
        $ParsedSubQuery = $this->parse($clause_sql, ...$clause_args);
        $ParsedQuery->add($ParsedSubQuery);
      }
      else {
        $ParsedSubQuery = $fn($val);
        if (! ($ParsedSubQuery instanceof MeekroDBParsedQuery)) {
          throw new MeekroDBException("Unable to parse query");
        }

        $ParsedQuery->add($ParsedSubQuery);
      }
    }

    return $ParsedQuery;
  }

  protected function _query() {
    $param_char = $this->param_char;
    $this->param_char = ':';

    $args = func_get_args();
    $func_name = array_shift($args);

    try {
      return $this->queryHelper(array('assoc' => true, 'name' => $func_name), $args);
    } finally {
      $this->param_char = $param_char;
    }
  }
  protected function _parse() {
    $param_char = $this->param_char;
    $this->param_char = ':';

    try {
      return $this->parse(...func_get_args());
    } finally {
      $this->param_char = $param_char;
    }
  }
  
  protected function queryHelper($opts, $args) {
    if (!isset($opts['name'])) {
      throw new MeekroDBException("queryHelper() must get source function name");
    }
    $opts_fullcols = (isset($opts['fullcols']) && $opts['fullcols']);
    $opts_raw = (isset($opts['raw']) && $opts['raw']);
    $opts_unbuf = (isset($opts['unbuf']) && $opts['unbuf']);
    $opts_assoc = (isset($opts['assoc']) && $opts['assoc']);
    $opts_walk = (isset($opts['walk']) && $opts['walk']);
    $func_name = $opts['name'];
    $is_buffered = !($opts_unbuf || $opts_walk);

    if ($this->reconnect_after > 0 && time() - $this->last_query_at >= $this->reconnect_after) {
      $this->disconnect();
    }

    $query = array_shift($args);
    if ($query instanceof MeekroDBParsedQuery) {
      $ParsedQuery = $query;
    } else {
      $ParsedQuery = $this->parse($query, ...$args);
    }
    $query = $ParsedQuery->query;
    $params = $ParsedQuery->params;

    list($query, $params) = $this->runHook('pre_run', array(
      'query' => $query,
      'params' => $params,
      'func_name' => $func_name,
    ));
    $query = trim($query);
    $this->last_query = $query;
    $this->last_query_at = time();
    
    $starttime = microtime(true);
    $pdo = $this->get();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $db_type = $this->db_type();
    if ($db_type == 'mysql') {
      $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $is_buffered);
    }
    else if ($db_type == 'pgsql') {
      $pdo->setAttribute(PDO::PGSQL_ATTR_DISABLE_PREPARES, 1);
    }
    
    $result = $Exception = null;
    try {
      if ($params) {
        $result = $pdo->prepare($query);
        foreach ($params as $i => $param) {
          if (is_int($param)) {
            $data_type = PDO::PARAM_INT;
          }
          else if (is_string($param) && $db_type == 'pgsql' && !mb_check_encoding($param)) {
            $data_type = PDO::PARAM_LOB;
          }
          else {
            $data_type = PDO::PARAM_STR;
          }
          $result->bindValue($i+1, $param, $data_type);
        }

        $result->execute();
      }
      else {
        $result = $pdo->query($query);
      }
      
    } catch (PDOException $e) {
      $Exception = new MeekroDBException(
        $e->getMessage(), $query, $params, $e->getCode()
      );
    }
    
    $runtime = microtime(true) - $starttime;
    $runtime = sprintf('%f', $runtime * 1000);
    
    $got_result_set = ($result && $result->columnCount() > 0);
    if ($result && !$got_result_set) $this->affected_rows = $result->rowCount();
    else $this->affected_rows = false;

    $hookHash = array(
      'query' => $query,
      'params' => $params,
      'runtime' => $runtime,
      'func_name' => $func_name,
      'exception' => null,
      'error' => null,
      'rows' => null,
      'affected' => null
    );
    if ($Exception) {
      $hookHash['exception'] = $Exception;
      $hookHash['error'] = $Exception->getMessage();
    } else {
      $hookHash['affected'] = $this->affected_rows;
    }

    $return = false;
    $skip_result_fetch = ($opts_walk || $opts_raw);
    
    if (!$skip_result_fetch && $got_result_set) {
      $return = array();

      $infos = null;
      if ($opts_fullcols) {
        $infos = array();
        for ($i = 0; $i < $result->columnCount(); $i++) {
          $info = $result->getColumnMeta($i);
          if (isset($info['table']) && strlen($info['table'])) {
            $infos[$i] = $info['table'] . '.' . $info['name'];
          }
          else {
            $infos[$i] = $info['name'];
          }
        }
      }

      while ($row = $result->fetch($opts_assoc ? PDO::FETCH_ASSOC : PDO::FETCH_NUM)) {
        if ($infos) $row = array_combine($infos, $row);
        $return[] = $row;
      }
    }

    if (is_array($return)) {
      $hookHash['rows'] = count($return);
    }

    $this->defaultRunHook($hookHash);
    $this->runHook('post_run', $hookHash);
    if ($Exception) {
      if ($this->runHook('run_failed', $hookHash) !== false) {
        throw $Exception;
      }
    }
    else {
      $this->runHook('run_success', $hookHash);
    }
    
    if ($opts_walk) return new MeekroDBWalk($result);
    else if ($opts_raw) return $result;
    else if ($result) $result->closeCursor();

    if (is_array($return)) return $return;
    return $this->affected_rows;
  }

  // *************** HOOKS
  function addHook($type, $fn) {
    if (! array_key_exists($type, $this->hooks)) {
      throw new MeekroDBException("Hook type $type is not recognized");
    }

    if (! is_callable($fn)) {
      throw new MeekroDBException("Second arg to addHook() must be callable");
    }

    $this->hooks[$type][] = $fn;
    end($this->hooks[$type]);
    return key($this->hooks[$type]);
  }

  function removeHook($type, $index) {
    if (! array_key_exists($type, $this->hooks)) {
      throw new MeekroDBException("Hook type $type is not recognized");
    }

    if (! array_key_exists($index, $this->hooks[$type])) {
      throw new MeekroDBException("That hook does not exist");
    }

    unset($this->hooks[$type][$index]);
  }

  function removeHooks($type) {
    if (! array_key_exists($type, $this->hooks)) {
      throw new MeekroDBException("Hook type $type is not recognized");
    }

    $this->hooks[$type] = array();
  }

  protected function runHook($type, $args=array()) {
    if (! array_key_exists($type, $this->hooks)) {
      throw new MeekroDBException("Hook type $type is not recognized");
    }

    if ($type == 'pre_run') {
      foreach ($this->hooks[$type] as $hook) {
        $result = call_user_func($hook, $args);
        if (is_string($result)) {
          $args['query'] = $result;
        }
        else if (is_array($result) && count($result) == 2) {
          list($args['query'], $args['params']) = $result;
        }
        else if (!is_null($result)) {
          throw new MeekroDBException("pre_run hook must return a query string or [query, params] array");
        }
      }

      return array($args['query'], $args['params']);
    }
    else if ($type == 'post_run') {

      foreach ($this->hooks[$type] as $hook) {
        call_user_func($hook, $args);
      }
    }
    else if ($type == 'run_success') {
      
      foreach ($this->hooks[$type] as $hook) {
        call_user_func($hook, $args);
      }
    }
    else if ($type == 'run_failed') {
      
      foreach ($this->hooks[$type] as $hook) {
        $result = call_user_func($hook, $args);
        if ($result === false) return false;
      }
    }
    else {
      throw new MeekroDBException("runHook() type $type not recognized");
    }
  }

  protected function defaultRunHook($args) {
    if (! $this->logfile) return;

    $query = $args['query'];
    $query = preg_replace('/\s+/', ' ', $query);

    $results[] = sprintf('[%s]', date('Y-m-d H:i:s'));
    $results[] = sprintf('QUERY: %s', $query);

    if ($params = $args['params']) {
      $results[] = sprintf('PARAMS: %s', implode(', ', $params));
    }

    $results[] = sprintf('RUNTIME: %s ms', $args['runtime']);

    if ($args['affected']) {
      $results[] = sprintf('AFFECTED ROWS: %s', $args['affected']);
    }
    if ($args['rows']) {
      $results[] = sprintf('RETURNED ROWS: %s', $args['rows']);
    }
    if ($args['error']) {
      $results[] = 'ERROR: ' . $args['error'];
    }
    
    $results = implode("\n", $results) . "\n\n";

    if (is_resource($this->logfile)) {
      fwrite($this->logfile, $results);
    } else {
      file_put_contents($this->logfile, $results, FILE_APPEND);
    }
  }

  // *************** DEPRECATED METHODS
  /**
   * @deprecated
   */
  public function debugMode($enable=true) {
    if ($enable) $this->logfile = fopen('php://output', 'w');
    else $this->logfile = null;
  }

  /**
   * @deprecated
   */
  public function queryRaw() { 
    return $this->queryHelper(array('raw' => true, 'name' => 'queryRaw'), func_get_args());
  }

  /**
   * @deprecated
   */
  public function queryRawUnbuf() { 
    return $this->queryHelper(array('raw' => true, 'unbuf' => true, 'name' => 'queryRawUnbuf'), func_get_args());
  }

}

class MeekroDBWalk {
  protected $result;

  function __construct(PDOStatement $result) {
    $this->result = $result;
  }

  function next() {
    if (!$this->result) return;
    $row = $this->result->fetch(PDO::FETCH_ASSOC);
    if ($row === false) $this->free();
    return $row;
  }

  function free() {
    if (!$this->result) return;
    $this->result->closeCursor();
    $this->result = null;
  }

  function __destruct() {
    $this->free();
  }
}

class WhereClause {
  public $type = 'and'; //AND or OR
  public $negate = false;
  public $clauses = array();
  
  function __construct($type) {
    $type = strtolower($type);
    if ($type !== 'or' && $type !== 'and') throw new MeekroDBException('you must use either WhereClause(and) or WhereClause(or)');
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
  protected $params = array();
  
  function __construct($message='', $query='', $params=array(), $code = 0) {
    parent::__construct($message);
    $this->query = $query;
    $this->params = $params;
    $this->code = $code;
  }
  
  public function getQuery() { return $this->query; }
  public function getParams() { return $this->params; }
}

class MeekroDBParsedQuery {
  public $query = '';
  public $params = array();

  function __construct($query='', $params = array()) {
    $this->query = $query;
    $this->params = $params;
  }

  function add($query, $params = array()) {
    if ($query instanceof MeekroDBParsedQuery) {
      return $this->add($query->query, $query->params);
    }

    $this->query .= $query;
    $this->params = array_merge($this->params, array_values($params));
  }

  function toArray() {
    return array_merge(array($this->query), $this->params);
  }
};
