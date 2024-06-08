<?php

class WalkTest extends SimpleTest {
  function test_1_walk() {
    $Walk = DB::queryWalk("SELECT * FROM accounts ORDER BY id");

    $results = array();
    while ($row = $Walk->next()) {
      $results[] = $row;
    }

    $this->assert(count($results) == 8);
    $this->assert($results[7]['username'] == 'vookoo');
  }
  
  function test_2_walk_empty() {
    $Walk = DB::queryWalk("SELECT * FROM accounts WHERE id>100");

    $results = array();
    while ($row = $Walk->next()) {
      $results[] = $row;
    }

    $this->assert(count($results) == 0);
  }

  function test_3_walk_insert() {
    $Walk = DB::queryWalk("INSERT INTO profile (id) VALUES (100)");

    $results = array();
    while ($row = $Walk->next()) {
      $results[] = $row;
    }

    $this->assert(count($results) == 0);

    DB::query("DELETE FROM profile WHERE id=100");
  }

  function test_4_walk_incomplete() {
    $Walk = DB::queryWalk("SELECT * FROM accounts");
    $Walk->next();
    unset($Walk);

    // if $Walk hasn't been properly freed, this will produce an out of sync error
    DB::query("SELECT * FROM accounts");
  }

  function test_5_walk_error() {
    // drivers other than mysql seem to buffer results whenever they want to
    if ($this->db_type != 'mysql') return;

    $exception_was_caught = 0;
    $Walk = DB::queryWalk("SELECT * FROM accounts");
    $Walk->next();
    
    try {
      // this will produce an out of sync error
      DB::query("SELECT * FROM accounts");
    } catch (MeekroDBException $e) {
      if ($this->match_set($e->getMessage(), array('out of sync', 'unbuffered queries'))) {
        $exception_was_caught = 1;
      }
    }

    $this->assert($exception_was_caught === 1);
  }
}
