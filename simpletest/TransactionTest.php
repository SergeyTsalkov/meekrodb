<?php
class TransactionTest extends SimpleTest {
  function test_1_transactions() {
    DB::$nested_transactions = false;
    
    DB::query("UPDATE accounts SET age=%i WHERE username=%s", 600, 'Abe');
    
    $depth = DB::startTransaction();
    $this->assert($depth === 1);
    
    DB::query("UPDATE accounts SET age=%i WHERE username=%s", 700, 'Abe');
    DB::query("UPDATE accounts SET age=%i WHERE username=%s", 800, 'Abe');
    
    $depth = DB::rollback();
    $this->assert($depth === 0);
    
    $age = DB::queryFirstField("SELECT age FROM accounts WHERE username=%s", 'Abe');
    $this->assert($age == 600);
  }

  function test_2_transactions() {
    DB::$nested_transactions = true;
    
    $depth = DB::startTransaction();
      $this->assert($depth === 1);
      DB::query("UPDATE accounts SET age=%i WHERE username=%s", 700, 'Abe');
      
      $depth = DB::startTransaction();
        $this->assert($depth === 2);
        DB::query("UPDATE accounts SET age=%i WHERE username=%s", 800, 'Abe');
        
        $depth = DB::startTransaction();
          $this->assert($depth === 3);
          $this->assert(DB::transactionDepth() === 3);
          DB::query("UPDATE accounts SET age=%i WHERE username=%s", 500, 'Abe');
        $depth = DB::commit();
        
        $this->assert($depth === 2);
        
        $age = DB::queryFirstField("SELECT age FROM accounts WHERE username=%s", 'Abe');
        $this->assert($age == 500);
        
      $depth = DB::rollback();
      $this->assert($depth === 1);
      
      $age = DB::queryFirstField("SELECT age FROM accounts WHERE username=%s", 'Abe');
      $this->assert($age == 700);
    
    $depth = DB::commit();
    $this->assert($depth === 0);
    
    $age = DB::queryFirstField("SELECT age FROM accounts WHERE username=%s", 'Abe');
    $this->assert($age == 700);
    
    
    DB::$nested_transactions = false;
  }
  
  function test_3_transactions() {
    DB::$nested_transactions = true;
    
    DB::query("UPDATE accounts SET age=%i WHERE username=%s", 600, 'Abe');
    
    DB::startTransaction();
    DB::query("UPDATE accounts SET age=%i WHERE username=%s", 700, 'Abe');
    DB::startTransaction();
    DB::query("UPDATE accounts SET age=%i WHERE username=%s", 800, 'Abe');
    DB::rollback();
    
    $age = DB::queryFirstField("SELECT age FROM accounts WHERE username=%s", 'Abe');
    $this->assert($age == 700);
    
    DB::rollback();
    
    $age = DB::queryFirstField("SELECT age FROM accounts WHERE username=%s", 'Abe');
    $this->assert($age == 600);
    
    DB::$nested_transactions = false;
  }
  
  function test_4_transaction_rollback_all() {
    DB::$nested_transactions = true;
    
    DB::query("UPDATE accounts SET age=%i WHERE username=%s", 200, 'Abe');
    
    $depth = DB::startTransaction();
    $this->assert($depth === 1);
    DB::query("UPDATE accounts SET age=%i WHERE username=%s", 300, 'Abe');
    $depth = DB::startTransaction();
    $this->assert($depth === 2);
    
    DB::query("UPDATE accounts SET age=%i WHERE username=%s", 400, 'Abe');
    $depth = DB::rollback(true);
    $this->assert($depth === 0);
    
    $age = DB::queryFirstField("SELECT age FROM accounts WHERE username=%s", 'Abe');
    $this->assert($age == 200);
    
    DB::$nested_transactions = false;
  }

  // * disconnect() ends any open transaction, so the next startTransaction() is a real one (not a SAVEPOINT)
  // (uses a separate connection, since disconnecting the main one would wipe an in-memory sqlite database)
  function test_5_disconnect_resets_depth() {
    $mdb = new MeekroDB();
    $mdb->nested_transactions = true;

    $this->assert($mdb->startTransaction() === 1);
    $this->assert($mdb->startTransaction() === 2);
    $mdb->disconnect();
    $this->assert($mdb->transactionDepth() === 0);

    $this->assert($mdb->startTransaction() === 1);
    $this->assert($mdb->rollback() === 0);
  }

}
