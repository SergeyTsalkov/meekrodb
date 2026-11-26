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
 * @method static mixed queryAllLists(string $query, ...$parameters)
 * @method static mixed queryFullColumns(string $query, ...$parameters)
 * @method static mixed queryWalk(string $query, ...$parameters)
 *
 * @link https://meekro.com/docs/altering-data.html Altering Data
 *
 * @method static int|array|null insert(string $table_name, array $data, array $options = [])
 * @method static mixed insertId()
 * @method static int|array|null insertIgnore(string $table_name, array $data, array $options = [])
 * @method static int insertUpdate(string $table_name, array $data, ...$parameters)
 * @method static int|array|null replace(string $table_name, array $data, array $options = [])
 * @method static int update(string $table_name, array $data, ...$parameters)
 * @method static int delete(string $table_name, ...$parameters)
 * @method static int affectedRows()
 * @method static mixed sqleval(string $query, ...$parameters)
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
 * @method static void setDB(string $database_name)
 * @method static array tableList(?string $database_name = null)
 * @method static array columnList(string $table_name)
 * @method static void disconnect()
 * @method static PDO get()
 * @method static string lastQuery()
 * @method static string serverVersion()
 * @method static bool supports(string $feature)
 * @method static void debugMode(bool $enable = true)
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
  public $last_query_at=0; // time of the last query, or of connecting if no query has run since
  protected $supports_cache = array();
  protected $params_map_keys = array(); // param_char => placeholder names, see paramsMapKeys()

  protected $hooks = array(
    'pre_run' => array(),
    'post_run' => array(),
    'run_success' => array(),
    'run_failed' => array(),
  );

  public function __construct(string $dsn='', string $user='', string $password='', array $opts=array()) {
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

  public function dbType() {
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
      $this->db_type = strtolower($this->db_type);
      if (!in_array($this->db_type, ['mysql', 'sqlite', 'pgsql'])) {
        throw new MeekroDBException("Invalid DSN: " . $this->dsn);
      }

      try {
        $pdo = new PDO($this->dsn, $this->user, $this->password, $this->connect_options);
        $this->internal_pdo = $pdo;
        // a new connection isn't idle: without this, reconnect_after would drop it on the first query
        $this->last_query_at = time();
      } catch (PDOException $e) {
        throw MeekroDBException::fromException($e);
      }
      
    }
    
    return $pdo;
  }
  
  public function disconnect() {
    $this->internal_pdo = null;
    $this->supports_cache = array();
    // any open transaction ends with the connection
    $this->nested_transactions_count = 0;
  }

  /**
   * Check whether the connected database supports a feature.
   * Features: 'returning', 'insert_ignore', 'insert_update'
   */
  public function supports($feature) {
    if (! in_array($feature, array('returning', 'insert_ignore', 'insert_update'), true)) {
      throw new MeekroDBException("supports(): unknown feature: {$feature}");
    }
    if (! array_key_exists($feature, $this->supports_cache)) {
      $this->supports_cache[$feature] = $this->checkSupports($feature);
    }
    return $this->supports_cache[$feature];
  }

  protected function checkSupports($feature) {
    $db_type = $this->dbType();
    $version = $this->serverVersion();

    // MariaDB reports e.g. "10.11.6-MariaDB-1" or "5.5.5-10.11.6-MariaDB"
    $mariadb_version = null;
    if ($db_type == 'mysql' && preg_match('/(\d+\.\d+\.\d+)-MariaDB/i', $version, $match)) {
      $mariadb_version = $match[1];
    }

    switch ($feature) {
      case 'returning':
        if ($db_type == 'pgsql') return true;
        if ($db_type == 'sqlite') return version_compare($version, '3.35', '>=');
        return $mariadb_version && version_compare($mariadb_version, '10.5', '>=');

      case 'insert_ignore':
        return $db_type != 'pgsql';

      case 'insert_update':
        if ($db_type == 'pgsql') return false;
        if ($db_type == 'sqlite') return version_compare($version, '3.35', '>=');
        return true;
    }
    return false;
  }

  public function serverVersion() { return $this->get()->getAttribute(PDO::ATTR_SERVER_VERSION); }
  public function transactionDepth() { return $this->nested_transactions_count; }
  public function insertId() { return $this->get()->lastInsertId(); }
  public function affectedRows() { return $this->affected_rows; }
  
  public function lastQuery() { return $this->last_query; }

  public function debugMode($enable=true) {
    if ($enable) $this->logfile = fopen('php://output', 'w');
    else $this->logfile = null;
  }

  public function setDB() { return $this->useDB(...func_get_args()); }
  public function useDB($dbName) { 
    if (in_array($this->dbType(), array('pgsql', 'sqlite'))) {
      throw new MeekroDBException(sprintf('Database switching not supported by %s', $this->dbType()));
    }

    $this->_query('useDB', "USE :c", $dbName);
  }
  
  public function startTransaction() {
    $start_transaction = 'START TRANSACTION';
    if ($this->dbType() == 'sqlite') {
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
    return $this->endTransaction('commit', $all, 'RELEASE SAVEPOINT', 'COMMIT');
  }

  public function rollback($all=false) {
    return $this->endTransaction('rollback', $all, 'ROLLBACK TO SAVEPOINT', 'ROLLBACK');
  }

  // shared by commit() and rollback(): ends the innermost savepoint if we're still nested
  // (or the whole transaction if $all is set, or if we're at the outermost level)
  protected function endTransaction($func_name, $all, $savepoint_sql, $transaction_sql) {
    $this->nested_transactions_count--;
    if ($all || $this->nested_transactions_count < 0) {
      $this->nested_transactions_count = 0;
    }

    if ($this->nested_transactions_count > 0) {
      $this->_query($func_name, "{$savepoint_sql} LEVEL{$this->nested_transactions_count}");
    } else {
      $this->_query($func_name, $transaction_sql);
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
    if (!$results || !is_array($results)) return [];
    return array_column($results, 0);
  }
  
  public function queryFirstField() {
    $results = $this->queryHelper(['name' => 'queryFirstField'], func_get_args());
    if (!$results || !is_array($results)) return null;
    return $results[0][0];
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
    $ParsedQuery->add($this->parseWhere($args));
    return $this->_query('update', $ParsedQuery);
  }

  public function delete() {
    $args = func_get_args();
    if (count($args) < 2) {
      throw new MeekroDBException("delete(): at least 2 arguments expected");
    }

    $table = array_shift($args);
    $ParsedQuery = $this->_parse('DELETE FROM :b WHERE ', $table);
    $ParsedQuery->add($this->parseWhere($args));
    return $this->_query('delete', $ParsedQuery);
  }

  // WHERE clause for update() and delete(): either a hash of column => value, or a query string
  // with its own args. The string is parsed separately from the rest of the query because we don't
  // know whether it uses named or numbered args.
  protected function parseWhere(array $args) {
    if (is_array($args[0])) {
      return $this->_parse(':ha', $args[0]);
    }
    return $this->parse(...$args);
  }
  
  protected function insertOrReplace($mode, $table, $datas, $options=array()) {
    $db_type = $this->dbType();
    $fn_name = 'insert';

    if ($mode == 'insert') {
      $action = 'INSERT';
    } else if ($mode == 'ignore') {
      $fn_name = 'insertIgnore';
      if (! $this->supports('insert_ignore')) {
        throw new MeekroDBException("postgres does not support insertIgnore()");
      }
      else if ($db_type == 'sqlite') {
        // works on all sqlite 3.x, unlike ON CONFLICT DO NOTHING (3.24+)
        $action = 'INSERT OR IGNORE';
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

    $returning = $options['returning'] ?? null;
    if ($returning) {
      if (! $this->supports('returning')) {
        throw new MeekroDBException("{$db_type} " . $this->serverVersion() . " does not support RETURNING");
      }

      if ($returning === true || $returning === '*') {
        $returning_str = '*';
      }
      else if (is_array($returning)) {
        $returning_str = implode(',', array_map(array($this, 'formatName'), array_values($returning)));
      }
      else {
        throw new MeekroDBException("returning option must be true, '*', or an array of column names");
      }
    }

    $datas = unserialize(serialize($datas)); // break references within array
    $keys = $values = array();
    $array_is_list = ($datas === [] || $datas === array_values($datas));
    $is_multi = ($array_is_list && count($datas) > 0 && is_array($datas[0]));
    
    if ($is_multi) {
      foreach ($datas as $datum) {
        ksort($datum);
        if (! $keys) {
          $keys = array_keys($datum);
        }
        else if ($keys !== array_keys($datum)) {
          throw new MeekroDBException("when inserting multiple rows at once, they must have the same keys");
        }

        $values[] = array_values($datum);  
      }

      $ParsedQuery = $this->_parse(':l INTO :b :lc VALUES :ll?', $action, $table, $keys, $values);
    }
    else {
      $keys = array_keys($datas);
      $values = array_values($datas);

      if ($db_type == 'mysql' || count($values) > 0)  {
        $ParsedQuery = $this->_parse(':l INTO :b :lc VALUES :l?', $action, $table, $keys, $values);
      } else {
        $ParsedQuery = $this->_parse(':l INTO :b DEFAULT VALUES', $action, $table);
      }
    }

    $do_update = $mode == 'insert' && isset($options['update']) 
      && is_array($options['update']) && $options['update'];

    if ($do_update) {
      $fn_name = 'insertUpdate';
      if (! $this->supports('insert_update')) {
        if ($db_type == 'pgsql') throw new MeekroDBException("postgres does not support insertUpdate()");
        throw new MeekroDBException("{$db_type} " . $this->serverVersion() . " does not support insertUpdate(), please upgrade");
      }
      else if ($db_type == 'sqlite') {
        $on_duplicate = 'ON CONFLICT DO UPDATE SET';
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

    if ($returning) {
      $ParsedQuery->add(" RETURNING {$returning_str}");
    }

    $result = $this->_query($fn_name, $ParsedQuery);
    if (! $returning) return $result;

    // return shape matches the input: one row in, one row (or null) out
    $rows = is_array($result) ? $result : array();
    $this->affected_rows = count($rows);
    if ($is_multi) return $rows;
    return $rows ? $rows[0] : null;
  }

  protected function insertOptions($fn_name, $options) {
    $allowed = array('returning');
    foreach (array_keys($options) as $key) {
      if (! in_array($key, $allowed, true)) {
        throw new MeekroDBException("{$fn_name}(): unknown option: {$key}");
      }
    }
    return $options;
  }
  
  public function insert($table, $data, $options=array()) {
    return $this->insertOrReplace('insert', $table, $data, $this->insertOptions('insert', $options));
  }
  public function insertIgnore($table, $data, $options=array()) {
    return $this->insertOrReplace('ignore', $table, $data, $this->insertOptions('insertIgnore', $options));
  }
  public function replace($table, $data, $options=array()) {
    return $this->insertOrReplace('replace', $table, $data, $this->insertOptions('replace', $options));
  }
  
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
    $db_type = $this->dbType();

    $args = array($table);
    if ($db_type == 'sqlite') {
      $query = 'PRAGMA table_info(:b)';
      $primary = 'name';
    }
    else if ($db_type == 'pgsql') {
      // the same table name can exist in several schemas: use the one an unqualified query would
      // reach, i.e. the first one on search_path
      $query = 'SELECT column_name, data_type, is_nullable, column_default 
        FROM information_schema.columns WHERE table_name=:s
        AND table_schema = (
          SELECT n.nspname FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
          WHERE c.relname = :s AND pg_table_is_visible(c.oid)
        )
        ORDER BY ordinal_position';
      $primary = 'column_name';
      $args[] = $table;
    }
    else {
      $query = 'SHOW COLUMNS FROM :b';
      $primary = 'Field';
    }

    $data = $this->_query('columnList', $query, ...$args);
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
    if ($this->dbType() == 'sqlite') {
      if ($db) $tbl = "{$db}.sqlite_master";
      else $tbl = "sqlite_master";

      $result = $this->_query('tableList', "SELECT name FROM :b 
        WHERE type='table' AND name NOT LIKE 'sqlite_%'", $tbl);
    }
    else if ($this->dbType() == 'pgsql') {
      // tables from every schema on search_path; if a name exists in several of them, only
      // the one an unqualified query would reach (the first on search_path) is visible
      $result = $this->_query('tableList', "SELECT table_name
        FROM information_schema.tables
        WHERE table_schema = ANY(current_schemas(false))
        AND pg_table_is_visible((quote_ident(table_schema) || '.' || quote_ident(table_name))::regclass)
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
    // "(?,?,?)" with $count question marks, repeated $batches times: "(?,?),(?,?)"
    $placeholders = function($count, $batches = 1) {
      $question_marks = '(' . implode(',', array_fill(0, $count, '?')) . ')';
      return implode(',', array_fill(0, $batches, $question_marks));
    };

    // combine several ParsedQueries into one: their SQL joined by $glue and wrapped in $start/$end,
    // and their params concatenated in order
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

    // escape LIKE wildcards, so the string is matched literally
    $like_escape = function($arg) {
      return str_replace(array('%', '_'), array('\%', '\_'), $arg);
    };

    // column => value pairs, joined by $glue (for SET, or WHERE with AND/OR)
    $hash = function($arg, $glue) use ($join) {
      $Queries = array();
      foreach ($arg as $key => $value) {
        $key = $this->formatName($key);
        $Query = $this->sanitize($value);
        $Queries[] = new MeekroDBParsedQuery($key . '=' . $Query->query, $Query->params);
      }
      return $join($Queries, $glue);
    };

    // list of quoted table/column names: "(`a`,`b`)"
    $name_list = function($arg) {
      $str = '('. implode(',', array_map(array($this, 'formatName'), $arg)) . ')';
      return new MeekroDBParsedQuery($str);
    };

    return array(
      's' => function($arg) { 
        return new MeekroDBParsedQuery('?', array(strval($arg)));
      },
      'i' => function($arg) { 
        return new MeekroDBParsedQuery('?', array($this->intval($arg)));
      },
      'd' => function($arg) { 
        return new MeekroDBParsedQuery('?', array(floatval($arg)));
      },
      'b' => function($arg) { 
        return new MeekroDBParsedQuery($this->formatName($arg, true));
      },
      'c' => function($arg) {
        return new MeekroDBParsedQuery($this->formatName($arg));
      },
      'l' => function($arg) { 
        return new MeekroDBParsedQuery(strval($arg));
      },
      't' => function($arg) {
        return new MeekroDBParsedQuery('?', array($this->sanitizeTS($arg)));
      },
      'ss' => function($arg) use ($like_escape) { 
        return new MeekroDBParsedQuery('?', array('%' . $like_escape($arg) . '%'));
      },
      'ssb' => function($arg) use ($like_escape) { 
        return new MeekroDBParsedQuery('?', array($like_escape($arg) . '%'));
      },
      'sse' => function($arg) use ($like_escape) { 
        return new MeekroDBParsedQuery('?', array('%' . $like_escape($arg)));
      },
      'ls' => function($arg) use ($placeholders) {
        $arg = array_map('strval', $arg);
        return new MeekroDBParsedQuery($placeholders(count($arg)), $arg);
      },
      'li' => function($arg) use ($placeholders) { 
        $arg = array_map(array($this, 'intval'), $arg);
        return new MeekroDBParsedQuery($placeholders(count($arg)), $arg);
      },
      'ld' => function($arg) use ($placeholders) {
        $arg = array_map('floatval', $arg);
        return new MeekroDBParsedQuery($placeholders(count($arg)), $arg);
      },
      'lb' => $name_list,
      'lc' => $name_list,
      'lt' => function($arg) use ($placeholders) { 
        $arg = array_map(array($this, 'sanitizeTS'), $arg);
        return new MeekroDBParsedQuery($placeholders(count($arg)), $arg);
      },
      '?' => function($arg) {
        return $this->sanitize($arg);
      },
      'l?' => function($arg) use ($join) {
        $Queries = array_map(array($this, 'sanitize'), $arg);
        return $join($Queries, ',', '(', ')');
      },
      'll?' => function($arg) use ($join) {
        $arg = array_values($arg);
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

          $Queries = array_map(array($this, 'sanitize'), $entry);
          $Master[] = $join($Queries, ',', '(', ')');
        }

        return $join($Master, ',');
      },
      'hc' => function($arg) use ($hash) { return $hash($arg, ','); },
      'ha' => function($arg) use ($hash) { return $hash($arg, ' AND '); },
      'ho' => function($arg) use ($hash) { return $hash($arg, ' OR '); },

      $this->param_char => function($arg) {
        return new MeekroDBParsedQuery($this->param_char);
      },
    );
  }

  // placeholder names from paramsMap(), cached because nextQueryParam() needs them for every
  // placeholder in every query, and building paramsMap() creates a closure for each one.
  // cached per param_char, since that's one of the keys (and _query()/_parse() switch it temporarily)
  protected function paramsMapKeys() {
    $param_char = $this->param_char;
    if (! array_key_exists($param_char, $this->params_map_keys)) {
      $this->params_map_keys[$param_char] = array_keys($this->paramsMap());
    }
    return $this->params_map_keys[$param_char];
  }

  protected function paramsMapArrayTypes() {
    return array('ls', 'li', 'ld', 'lb', 'lc', 'lt', 'l?', 'll?', 'hc', 'ha', 'ho');
  }
  protected function paramsMapOptArrayTypes() {
    return array('b', 'c');
  }

  protected function sanitizeTS($ts) {
    if (is_string($ts)) {
      return date('Y-m-d H:i:s', strtotime($ts));
    }
    else if ($ts instanceof DateTime) {
      return $ts->format('Y-m-d H:i:s');
    }
    return '';
  }

  protected function sanitize($input) {
    if (is_object($input)) {
      if ($input instanceof DateTime) {
        return new MeekroDBParsedQuery('?', array($input->format('Y-m-d H:i:s')));
      }
      if ($input instanceof MeekroDBParsedQuery) {
        return $input;
      }
      return new MeekroDBParsedQuery('?', array(strval($input)));
    }

    if (is_null($input) || is_array($input)) return new MeekroDBParsedQuery('NULL');
    else if (is_bool($input)) return new MeekroDBParsedQuery('?', array($input ? 1 : 0));
    return new MeekroDBParsedQuery('?', array($input));
  }

  protected function formatName($name, $can_join=false) {
    if (is_array($name)) {
      if ($can_join) return implode('.', array_map(array($this, 'formatName'), $name));
      throw new MeekroDBException("Invalid column/table name");
    }
    $char = '`';
    if ($this->dbType() == 'pgsql') $char = '"';
    return $char . str_replace($char, $char . $char, $name) . $char; 
  }

  protected function intval($var) {
    if (PHP_INT_SIZE == 8) return intval($var);
    return floor(floatval($var));
  }

  protected function nextQueryParam($query) {
    $keys = $this->paramsMapKeys();

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
      if ($max_numbered_arg+1 != count($args)) {
        throw new MeekroDBException(sprintf('Expected %d args, but got %d!', $max_numbered_arg+1, count($args)));
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
      
      if ($val instanceof WhereClause) {
        if ($Part['type'] != 'l' && $Part['type'] != '?') {
          throw new MeekroDBException("WhereClause must be used with l or ?, you used {$Part['type']} instead!");
        }

        list($clause_sql, $clause_args) = $val->textAndArgs($this->param_char);
        $ParsedSubQuery = $this->parse($clause_sql, ...$clause_args);
        $ParsedQuery->add($ParsedSubQuery);
      }
      else if ($val instanceof MeekroDBParsedQuery) {
        if ($Part['type'] != 'l' && $Part['type'] != '?') {
          throw new MeekroDBException("a ParsedQuery must be used with l or ?, you used {$Part['type']} instead!");
        }

        $ParsedQuery->add($val);
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
    $opts_fullcols = !empty($opts['fullcols']);
    $opts_raw = !empty($opts['raw']);
    $opts_unbuf = !empty($opts['unbuf']);
    $opts_assoc = !empty($opts['assoc']);
    $opts_walk = !empty($opts['walk']);
    $func_name = $opts['name'];
    $is_buffered = !($opts_unbuf || $opts_walk);

    // never reconnect in the middle of a transaction: that would silently discard it, and run the
    // rest of the queries outside of it. Better to try the old connection and fail loudly if it's gone.
    $is_idle = ($this->reconnect_after > 0 && time() - $this->last_query_at >= $this->reconnect_after);
    if ($is_idle && $this->nested_transactions_count == 0) {
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
    $result = $Exception = null;

    try {
      $pdo = $this->getForQuery($is_buffered);

      if ($params) {
        $result = $pdo->prepare($query);
        $this->bindParams($result, $params);
        $result->execute();
      }
      else {
        $result = $pdo->query($query);
      }
      
    } catch (PDOException|MeekroDBException $e) {

      if ($e instanceof PDOException) {
        $Exception = MeekroDBException::fromException($e);
      } else {
        $Exception = $e;
      }

      $Exception->setQuery($query, $params);
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
      $return = $this->fetchRows($result, $opts_assoc, $opts_fullcols);
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

  // PDO connection, configured for the next query
  protected function getForQuery($is_buffered) {
    $pdo = $this->get();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    switch ($this->dbType()) {
      case 'mysql':
        $pdo->setAttribute(PHP_VERSION_ID >= 80500 ? Pdo\Mysql::ATTR_USE_BUFFERED_QUERY : PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $is_buffered);
        break;
      
      case 'pgsql':
        $pdo->setAttribute(PHP_VERSION_ID >= 80500 ? Pdo\Pgsql::ATTR_DISABLE_PREPARES : PDO::PGSQL_ATTR_DISABLE_PREPARES, 1);
        break;
    }
    return $pdo;
  }

  protected function bindParams(PDOStatement $statement, array $params) {
    foreach ($params as $i => $param) {
      if (is_int($param)) {
        $data_type = PDO::PARAM_INT;
      }
      else if (is_string($param) && $this->dbType() == 'pgsql' && !mb_check_encoding($param)) {
        // binary data on pgsql (e.g. for BYTEA columns)
        $data_type = PDO::PARAM_LOB;
      }
      else {
        $data_type = PDO::PARAM_STR;
      }
      $statement->bindValue($i+1, $param, $data_type);
    }
  }

  // all rows of the result set; with $fullcols, rows are keyed by "table.column"
  // instead of just "column" (where the driver knows the column's table)
  protected function fetchRows(PDOStatement $result, $assoc, $fullcols) {
    $columns = null;
    if ($fullcols) {
      $columns = array();
      for ($i = 0; $i < $result->columnCount(); $i++) {
        $info = $result->getColumnMeta($i);
        if (isset($info['table']) && strlen($info['table'])) {
          $columns[$i] = $info['table'] . '.' . $info['name'];
        }
        else {
          $columns[$i] = $info['name'];
        }
      }
    }

    $rows = array();
    while ($row = $result->fetch($assoc ? PDO::FETCH_ASSOC : PDO::FETCH_NUM)) {
      if ($columns) $row = array_combine($columns, $row);
      $rows[] = $row;
    }
    return $rows;
  }

  // *************** HOOKS
  protected function checkHookType($type) {
    if (! array_key_exists($type, $this->hooks)) {
      throw new MeekroDBException("Hook type $type is not recognized");
    }
  }

  function addHook($type, $fn) {
    $this->checkHookType($type);

    if (! is_callable($fn)) {
      throw new MeekroDBException("Second arg to addHook() must be callable");
    }

    $this->hooks[$type][] = $fn;
    end($this->hooks[$type]);
    return key($this->hooks[$type]);
  }

  function removeHook($type, $index) {
    $this->checkHookType($type);

    if (! array_key_exists($index, $this->hooks[$type])) {
      throw new MeekroDBException("That hook does not exist");
    }

    unset($this->hooks[$type][$index]);
  }

  function removeHooks($type) {
    $this->checkHookType($type);
    $this->hooks[$type] = array();
  }

  protected function runHook($type, $args=array()) {
    $this->checkHookType($type);

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

    // run_failed: any hook returning false suppresses the exception, and skips the remaining hooks
    // post_run, run_success: return values are ignored
    foreach ($this->hooks[$type] as $hook) {
      $result = call_user_func($hook, $args);
      if ($type == 'run_failed' && $result === false) return false;
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

class WhereClause implements Countable {
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
    }
    else if ($sql instanceof MeekroDBParsedQuery) {
      // the placeholder is written with %, and swapped for the real param_char in textAndArgs()
      $this->clauses[] = array('sql' => '%?', 'args' => [$sql], 'is_parsed' => true);
    }
    else {
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
  
  #[\ReturnTypeWillChange]
  function count() {
    return count($this->clauses);
  }
  
  // $param_char should match the MeekroDB instance that will parse the result
  function textAndArgs($param_char = '%') {
    $sql = array();
    $args = array();
    
    if (count($this) == 0) return array('(1=1)', $args);
    
    foreach ($this->clauses as $clause) {
      if ($clause instanceof WhereClause) { 
        list($clause_sql, $clause_args) = $clause->textAndArgs($param_char);
      } else {
        $clause_sql = $clause['sql'];
        $clause_args = $clause['args'];

        // added parsed queries: only ever "%?", maybe wrapped in "NOT (...)" by negateLast()
        if (! empty($clause['is_parsed'])) {
          $clause_sql = str_replace('%?', $param_char . '?', $clause_sql);
        }
      }
      
      $sql[] = "($clause_sql)";
      $args = array_merge($args, $clause_args);
    }
    
    if (count($this) == 1) $sql = $sql[0];
    else if ($this->type == 'and') $sql = sprintf('(%s)', implode(' AND ', $sql));
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
  protected $strCode = ''; // PDOException supplies both an int code and a str code, and they're different

  static function fromException(Exception $e) {
    $code = 0;
    $strCode = '';

    if (is_int($e->getCode())) {
      $code = $e->getCode();
    }
    else if (isset($e->errorInfo) && is_array($e->errorInfo)) {
      if (is_string($e->errorInfo[0])) {
        $strCode = $e->errorInfo[0];
      }
      if (is_int($e->errorInfo[1])) {
        $code = $e->errorInfo[1];
      }
    }

    $Exception = new self($e->getMessage(), $code, $e);
    $Exception->strCode = $strCode;
    return $Exception;
  }

  public function getStrCode() { return $this->strCode; }
  public function getQuery() { return $this->query; }
  public function getParams() { return $this->params; }
  public function setQuery($query, array $params) {
    $this->query = $query;
    $this->params = $params;
  }
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
