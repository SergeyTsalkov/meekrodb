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
class DB
{
    // initial connection
    public static $dbName = '';
    public static $user = '';
    public static $password = '';
    public static $host = 'localhost';
    public static $port = 3306;
    //hhvm complains if this is null
    public static $socket = \null;
    public static $encoding = 'latin1';
    // configure workings
    public static $param_char = '%';
    public static $named_param_seperator = '_';
    public static $nested_transactions = \false;
    public static $ssl = array('key' => '', 'cert' => '', 'ca_cert' => '', 'ca_path' => '', 'cipher' => '');
    public static $connect_options = array(\MYSQLI_OPT_CONNECT_TIMEOUT => 30);
    public static $logfile;
    // internal
    protected static $mdb = \null;
    public static $variables_to_sync = array('param_char', 'named_param_seperator', 'nested_transactions', 'ssl', 'connect_options', 'logfile');
    public static function getMDB()
    {
    }
    public static function __callStatic($name, $args)
    {
    }
    // --- begin deprecated methods (kept for backwards compatability)
    static function debugMode($enable = \true)
    {
    }
    // initial connection
    public $dbName = '';
    public $user = '';
    public $password = '';
    public $host = 'localhost';
    public $port = 3306;
    public $socket = \null;
    public $encoding = 'latin1';
    // configure workings
    public $param_char = '%';
    public $named_param_seperator = '_';
    public $nested_transactions = \false;
    public $ssl = array('key' => '', 'cert' => '', 'ca_cert' => '', 'ca_path' => '', 'cipher' => '');
    public $connect_options = array(\MYSQLI_OPT_CONNECT_TIMEOUT => 30);
    public $logfile;
    // internal
    public $internal_mysql = \null;
    public $server_info = \null;
    public $insert_id = 0;
    public $num_rows = 0;
    public $affected_rows = 0;
    public $current_db = \null;
    public $nested_transactions_count = 0;
    public $last_query;
    protected $hooks = array('pre_parse' => array(), 'pre_run' => array(), 'post_run' => array(), 'run_success' => array(), 'run_failed' => array());
    public static function __construct($host = \null, $user = \null, $password = \null, $dbName = \null, $port = \null, $encoding = \null, $socket = \null)
    {
    }
    // suck in config settings from static class
    public static function sync_config()
    {
    }
    public static function get()
    {
    }
    public static function disconnect()
    {
    }
    function addHook($type, $fn)
    {
    }
    function removeHook($type, $index)
    {
    }
    function removeHooks($type)
    {
    }
    function runHook($type, $args = array())
    {
    }
    protected function defaultRunHook($args)
    {
    }
    public static function serverVersion()
    {
    }
    public static function transactionDepth()
    {
    }
    public static function insertId()
    {
    }
    public static function affectedRows()
    {
    }
    public static function count()
    {
    }
    public static function numRows()
    {
    }
    public static function lastQuery()
    {
    }
    public static function useDB()
    {
    }
    public static function setDB($dbName)
    {
    }
    public static function startTransaction()
    {
    }
    public static function commit($all = \false)
    {
    }
    public static function rollback($all = \false)
    {
    }
    function formatTableName($table)
    {
    }
    public static function update()
    {
    }
    public static function insertOrReplace($which, $table, $datas, $options = array())
    {
    }
    public static function insert($table, $data)
    {
    }
    public static function insertIgnore($table, $data)
    {
    }
    public static function replace($table, $data)
    {
    }
    public static function insertUpdate()
    {
    }
    public static function delete()
    {
    }
    public static function sqleval()
    {
    }
    public static function columnList($table)
    {
    }
    public static function tableList($db = \null)
    {
    }
    protected function paramsMap()
    {
    }
    protected function nextQueryParam($query)
    {
    }
    protected function preParse($query, $args)
    {
    }
    function parse($query)
    {
    }
    public static function escape($str)
    {
    }
    public static function sanitize($value, $type = 'basic', $hashjoin = ', ')
    {
    }
    function escapeTS($ts)
    {
    }
    function intval($var)
    {
    }
    public static function query()
    {
    }
    public static function queryAllLists()
    {
    }
    public static function queryFullColumns()
    {
    }
    public static function queryWalk()
    {
    }
    protected function queryHelper($opts, $args)
    {
    }
    public static function queryFirstRow()
    {
    }
    public static function queryFirstList()
    {
    }
    public static function queryFirstColumn()
    {
    }
    public static function queryFirstField()
    {
    }
    // --- begin deprecated methods (kept for backwards compatability)
    public static function debugMode($enable = \true)
    {
    }
    public static function queryRaw()
    {
    }
    public static function queryOneList()
    {
    }
    public static function queryOneRow()
    {
    }
    public static function queryOneField()
    {
    }
    public static function queryOneColumn()
    {
    }
}
class MeekroDBWalk
{
    protected $mysqli;
    protected $result;
    function __construct(\MySQLi $mysqli, $result)
    {
    }
    function next()
    {
    }
    function free()
    {
    }
    function __destruct()
    {
    }
}
class WhereClause
{
    public $type = 'and';
    //AND or OR
    public $negate = \false;
    public $clauses = array();
    function __construct($type)
    {
    }
    function add()
    {
    }
    function negateLast()
    {
    }
    function negate()
    {
    }
    function addClause($type)
    {
    }
    function count()
    {
    }
    function textAndArgs()
    {
    }
}
class DBTransaction
{
    private $committed = \false;
    function __construct()
    {
    }
    function __destruct()
    {
    }
    function commit()
    {
    }
}
class MeekroDBException extends \Exception
{
    protected $query = '';
    function __construct($message = '', $query = '', $code = 0)
    {
    }
    public static function getQuery()
    {
    }
}
class MeekroDBEval
{
    public $text = '';
    function __construct($text)
    {
    }
}
class SimpleTest
{
    public static function assert($boolean)
    {
    }
    protected function fail($msg = '')
    {
    }
}
class BasicTest extends \SimpleTest
{
    function __construct()
    {
    }
    function test_1_create_table()
    {
    }
    function test_1_5_empty_table()
    {
    }
    function test_2_insert_row()
    {
    }
    function test_3_more_inserts()
    {
    }
    function test_4_query()
    {
    }
    function test_4_1_query()
    {
    }
    function test_4_2_delete()
    {
    }
    function test_4_3_insertmany()
    {
    }
    function test_5_insert_blobs()
    {
    }
    function test_6_insert_ignore()
    {
    }
    function test_7_insert_update()
    {
    }
    function test_8_lb()
    {
    }
    function test_9_fullcolumns()
    {
    }
    function test_901_updatewithspecialchar()
    {
    }
    function test_902_faketable()
    {
    }
    function test_10_parse()
    {
    }
}
class CallTest extends \SimpleTest
{
    function test_1_create_procedure()
    {
    }
    function test_2_run_procedure()
    {
    }
}
class HookTest extends \SimpleTest
{
    static function static_error_callback($hash)
    {
    }
    function nonstatic_error_callback($hash)
    {
    }
    function test_1_error_handler()
    {
    }
    function test_2_exception_catch()
    {
    }
    function test_3_success_handler()
    {
    }
    function test_4_error_handler()
    {
    }
    function test_5_post_run_success()
    {
    }
    function test_6_post_run_failed()
    {
    }
    function test_7_pre_run()
    {
    }
    function test_8_pre_parse()
    {
    }
    function test_9_enough_args()
    {
    }
    function test_10_named_keys_present()
    {
    }
    function test_11_expect_array()
    {
    }
    function test_12_named_keys_without_array()
    {
    }
    function test_13_mix_named_numbered_args()
    {
    }
    function test_14_arrays_not_empty()
    {
    }
    function test_15_named_array_not_empty()
    {
    }
}
class ObjectTest extends \SimpleTest
{
    public $mdb;
    function __construct()
    {
    }
    function test_1_create_table()
    {
    }
    function test_1_5_empty_table()
    {
    }
    function test_2_insert_row()
    {
    }
    function test_3_more_inserts()
    {
    }
    function test_4_query()
    {
    }
    function test_4_1_query()
    {
    }
    function test_4_2_delete()
    {
    }
    function test_4_3_insertmany()
    {
    }
    function test_5_insert_blobs()
    {
    }
    function test_6_insert_ignore()
    {
    }
    function test_7_insert_update()
    {
    }
}
class TransactionTest extends \SimpleTest
{
    function test_1_transactions()
    {
    }
}
class TransactionTest_55 extends \SimpleTest
{
    function test_1_transactions()
    {
    }
    function test_2_transactions()
    {
    }
    function test_3_transaction_rollback_all()
    {
    }
}
class WalkTest extends \SimpleTest
{
    function test_1_walk()
    {
    }
    function test_2_walk_empty()
    {
    }
    function test_3_walk_insert()
    {
    }
    function test_4_walk_incomplete()
    {
    }
}
class WhereClauseTest extends \SimpleTest
{
    function test_1_basic_where()
    {
    }
    function test_2_simple_grouping()
    {
    }
    function test_3_negate_last()
    {
    }
    function test_4_negate_last_query()
    {
    }
    function test_5_negate()
    {
    }
    function test_6_negate_two()
    {
    }
    function test_7_or()
    {
    }
}
function my_error_handler($hash)
{
}
function my_success_handler($hash)
{
}
function microtime_float()
{
}
