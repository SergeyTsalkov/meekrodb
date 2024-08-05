<?php
class WhereClauseTest extends SimpleTest {
  function test_1_basic_where() {
    $where = new WhereClause('and');
    $where->add('username=%s', 'Bart');
    $where->add('password=%s', 'hello');
    
    $result = DB::query("SELECT * FROM accounts WHERE %?", $where);
    $this->assert(count($result) === 1);
    $this->assert($result[0]['age'] === '15');
  }
  
  function test_2_simple_grouping() {
    $where = new WhereClause('and');
    $where->add('password=%s', 'hello');
    $subclause = $where->addClause('or');
    $subclause->add('%c=%i', 'age', 15);
    $subclause->add('%c=%i', 'age', 14);
    
    $result = DB::query("SELECT * FROM accounts WHERE %l", $where);
    $this->assert(count($result) === 1);
    $this->assert($result[0]['age'] === '15');
  }
  
  function test_3_negate_last() {
    $where = new WhereClause('and');
    $where->add('password=%s', 'hello');
    $subclause = $where->addClause('or');
    $subclause->add('username!=%s', 'Bart');
    $subclause->negateLast();
    
    $result = DB::query("SELECT * FROM accounts WHERE %l", $where);
    $this->assert(count($result) === 1);
    $this->assert($result[0]['age'] === '15');
  }
  
  function test_4_negate_last_query() {
    $where = new WhereClause('and');
    $where->add('password=%s', 'hello');
    $subclause = $where->addClause('or');
    $subclause->add('username!=%s', 'Bart');
    $where->negateLast();
    
    $result = DB::query("SELECT * FROM accounts WHERE %l", $where);
    $this->assert(count($result) === 1);
    $this->assert($result[0]['age'] === '15');
  }
  
  function test_5_negate() {
    $where = new WhereClause('and');
    $where->add('password=%s', 'hello');
    $subclause = $where->addClause('or');
    $subclause->add('username!=%s', 'Bart');
    $subclause->negate();
    
    $result = DB::query("SELECT * FROM accounts WHERE %l", $where);
    $this->assert(count($result) === 1);
    $this->assert($result[0]['age'] === '15');
  }

  function test_6_negate_two() {
    $where = new WhereClause('and');
    $where->add('password=%s', 'hello');
    $where->add('username=%s', 'Bart');
    $where->negate();
    
    $result = DB::query("SELECT * FROM accounts WHERE %l", $where);
    $this->assert(count($result) === 7);
  }

  // * WhereClause works with OR
  // * you can add() a parse() output to a WhereClause
  function test_7_or() {
    $where = new WhereClause('or');
    $where->add('username=%s', 'Bart');
    $where->add(DB::parse('username=%s', 'Abe'));
    $result = DB::query("SELECT * FROM accounts WHERE %l", $where);
    $this->assert(count($result) === 2);
  }

  // * parse() output can be used as part of a query with %l or %?
  function test_8_multipart() {
    $part = DB::parse('WHERE username=%s', 'Bart');
    $rows = DB::query("SELECT * FROM accounts %l", $part);
    $this->assert(count($rows) === 1);
    $this->assert($rows[0]['id'] === '2');

    $rows = DB::query("SELECT * FROM accounts %?", $part);
    $this->assert(count($rows) === 1);
    $this->assert($rows[0]['id'] === '2');
  }

  function test_9_empty() {
    $where = new WhereClause('and');
    $result = DB::query("SELECT * FROM accounts WHERE %l", $where);
    $this->assert(count($result) === 8);
  }
  

}
