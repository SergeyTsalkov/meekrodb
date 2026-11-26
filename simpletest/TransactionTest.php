<?php
class TransactionTest extends SimpleTest {
  function __construct() {
    $this->resetDatabase();
  }

  function age() {
    return DB::queryFirstField("SELECT age FROM users WHERE user_id=1");
  }

  function setAge($age) {
    DB::update('users', ['age' => $age], ['user_id' => 1]);
  }

  // * without nested_transactions, startTransaction() and rollback() go between depth 0 and 1
  function test_1_rollback() {
    $this->setAge(600);
    $this->assert(DB::startTransaction() === 1);
    $this->setAge(700);
    $this->setAge(800);
    $this->assert(DB::rollback() === 0);
    $this->assert($this->age() === '600');
  }

  // * with nested_transactions, each level can be committed or rolled back on its own
  function test_2_nested() {
    DB::$nested_transactions = true;

    $this->assert(DB::startTransaction() === 1);
      $this->setAge(700);
      $this->assert(DB::startTransaction() === 2);
        $this->setAge(800);
        $this->assert(DB::startTransaction() === 3);
          $this->assert(DB::transactionDepth() === 3);
          $this->setAge(500);
        $this->assert(DB::commit() === 2);
        $this->assert($this->age() === '500');
      $this->assert(DB::rollback() === 1);
      $this->assert($this->age() === '700');
    $this->assert(DB::commit() === 0);
    $this->assert($this->age() === '700');

    $this->setAge(600);
    DB::startTransaction();
      $this->setAge(700);
      DB::startTransaction();
        $this->setAge(800);
      DB::rollback();
      $this->assert($this->age() === '700');
    DB::rollback();
    $this->assert($this->age() === '600');

    DB::$nested_transactions = false;
  }

  // * rollback(true) rolls back every level at once
  function test_3_rollback_all() {
    DB::$nested_transactions = true;

    $this->setAge(200);
    $this->assert(DB::startTransaction() === 1);
    $this->setAge(300);
    $this->assert(DB::startTransaction() === 2);
    $this->setAge(400);
    $this->assert(DB::rollback(true) === 0);
    $this->assert($this->age() === '200');

    DB::$nested_transactions = false;
  }

  // * DBTransaction commits with commit(), and rolls back if it goes away without one
  function test_4_dbtransaction() {
    $this->setAge(100);
    $Transaction = new DBTransaction();
    $this->setAge(200);
    $Transaction->commit();
    $this->assert($this->age() === '200');

    $Transaction = new DBTransaction();
    $this->setAge(300);
    unset($Transaction);
    $this->assert($this->age() === '200');
    $this->assert(DB::transactionDepth() === 0);
  }
}
