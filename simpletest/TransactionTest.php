<?
class TransactionTest extends SimpleTest {
  function test_1_transactions() {
    DB::$nested_transactions = false;
    
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
    $this->assert($age == 700);
  }
  
}
?>
