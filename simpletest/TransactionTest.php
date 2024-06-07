<?php
class TransactionTest extends SimpleTest {
  function test_1_transactions() {
    DB::$nested_transactions = false;
    
    DB::query("UPDATE accounts SET `user.age`=%i WHERE username=%s", 600, 'Abe');
    
    $depth = DB::startTransaction();
    $this->assert($depth === 1);
    
    DB::query("UPDATE accounts SET `user.age`=%i WHERE username=%s", 700, 'Abe');
    $depth = DB::startTransaction();
    $this->assert($depth === 1);
    
    DB::query("UPDATE accounts SET `user.age`=%i WHERE username=%s", 800, 'Abe');
    $depth = DB::rollback();
    $this->assert($depth === 0);
    
    $age = DB::queryFirstField("SELECT `user.age` FROM accounts WHERE username=%s", 'Abe');
    $this->assert($age == 600);
    
    $depth = DB::rollback();
    $this->assert($depth === 0);
    
    $age = DB::queryFirstField("SELECT `user.age` FROM accounts WHERE username=%s", 'Abe');
    $this->assert($age == 600);
  }
  
}
?>
