<?php

class WalkTest extends SimpleTest {
  function test_1_walk() {
    $Walk = DB::queryWalk("SELECT * FROM accounts");

    $results = array();
    while ($row = $Walk->next()) {
      $results[] = $row;
    }

    $this->assert(count($results) == 8);
    $this->assert($results[7]['username'] == 'vookoo');
  }

  function test_2_walk_stop() {
    $Walk = DB::queryWalk("SELECT * FROM accounts");
    $Walk->next();
    unset($Walk);

    // if $Walk hasn't been properly freed, this will produce an out of sync error
    DB::query("SELECT * FROM accounts");
  }
}