<?php
class CallTest extends SimpleTest {
  function __construct() {
    $this->resetDatabase();
  }

  function skip() {
    if ($this->db_type == 'sqlite') return "no support for procedures";
    if ($this->db_type == 'pgsql') return "procedure testing not yet implemented";
  }

  // * a stored procedure's result set comes back like a query's
  function test_1_procedure() {
    DB::query("DROP PROCEDURE IF EXISTS myProc");
    DB::query("CREATE PROCEDURE myProc()
    BEGIN
      SELECT * FROM users ORDER BY user_id;
    END");

    $rows = DB::query("CALL myProc()");
    $this->assert(count($rows) === 4);
    $this->assert($rows[0]['name'] === 'Abe');
    $this->assert($rows[3]['name'] === "Dana O'Neil");
  }
}
