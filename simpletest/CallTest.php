<?php
class CallTest extends SimpleTest {
  function test_1_create_procedure() {
    if ($this->db_type != 'mysql') return;

    DB::query("DROP PROCEDURE IF EXISTS myProc");
    DB::query("CREATE PROCEDURE myProc()
    BEGIN
      SELECT * FROM accounts;
    END");
  }

  function test_2_run_procedure() {
    if ($this->db_type != 'mysql') return;

    $r = DB::query("CALL myProc()");
    $this->assert($r[0]['username'] === 'Abe');
    $this->assert($r[2]['user.age'] === '914');
  }

}