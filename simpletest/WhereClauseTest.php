<?php
class WhereClauseTest extends SimpleTest {
  function __construct() {
    $this->resetDatabase();
  }

  // names of the users matching $where, in user_id order
  function names(WhereClause $where) {
    return DB::queryFirstColumn("SELECT name FROM users WHERE %l ORDER BY user_id", $where);
  }

  // * clauses are joined with AND; the WhereClause can be used with %l or %?
  function test_01_basic() {
    $where = new WhereClause('and');
    $where->add('employer_id=%i', 1);
    $where->add('age=%i', 15);
    $this->assert($this->names($where) === ['Bart']);
    $this->assert(DB::queryFirstColumn("SELECT name FROM users WHERE %?", $where) === ['Bart']);
  }

  // * addClause() adds a group, here joined with OR
  function test_02_grouping() {
    $where = new WhereClause('and');
    $where->add('employer_id=%i', 1);
    $subclause = $where->addClause('or');
    $subclause->add('age=%i', 15);
    $subclause->add('age=%i', 99);
    $this->assert($this->names($where) === ['Bart']);
  }

  // * negateLast() negates the last clause added, whether it's a plain clause or a group
  // * negate() negates a whole group
  function test_03_negate() {
    $where = new WhereClause('and');
    $where->add('employer_id=%i', 1);
    $subclause = $where->addClause('or');
    $subclause->add('name=%s', 'Bart');
    $subclause->negateLast();
    $this->assert($this->names($where) === ['Abe']);

    $where = new WhereClause('and');
    $where->add('employer_id=%i', 1);
    $subclause = $where->addClause('or');
    $subclause->add('name=%s', 'Bart');
    $where->negateLast();
    $this->assert($this->names($where) === ['Abe']);

    $where = new WhereClause('and');
    $where->add('employer_id=%i', 1);
    $subclause = $where->addClause('or');
    $subclause->add('name=%s', 'Bart');
    $subclause->negate();
    $this->assert($this->names($where) === ['Abe']);

    $where = new WhereClause('and');
    $where->add('age=%i', 15);
    $where->add('name=%s', 'Bart');
    $where->negate();
    $this->assert($this->names($where) === ['Abe', 'Cleo', "Dana O'Neil"]);
  }

  // * a WhereClause with no clauses matches everything
  function test_04_empty() {
    $this->assert($this->names(new WhereClause('and')) === ['Abe', 'Bart', 'Cleo', "Dana O'Neil"]);
  }

  // * you can add() a parse() output to a WhereClause
  // * parse() output can also be used directly, with %l or %?
  function test_05_parsed() {
    $where = new WhereClause('or');
    $where->add('name=%s', 'Bart');
    $where->add(DB::parse('name=%s', 'Abe'));
    $this->assert($this->names($where) === ['Abe', 'Bart']);

    $part = DB::parse('WHERE name=%s', 'Bart');
    $this->assert(DB::queryFirstColumn("SELECT name FROM users %l", $part) === ['Bart']);
    $this->assert(DB::queryFirstColumn("SELECT name FROM users %?", $part) === ['Bart']);
  }

  // * parse() output in a WhereClause works with a different param_char,
  //   including negated, and inside a nested group
  function test_06_parsed_alt_param_char() {
    DB::$param_char = '@';
    try {
      $where = new WhereClause('or');
      $where->add('name=@s', 'Bart');
      $where->add(DB::parse('name=@s', 'Abe'));
      $this->assert(DB::queryFirstColumn("SELECT name FROM users WHERE @l ORDER BY user_id", $where) === ['Abe', 'Bart']);

      $where = new WhereClause('and');
      $subclause = $where->addClause('or');
      $subclause->add(DB::parse('name=@s', 'Bart'));
      $subclause->add(DB::parse('name=@s', 'Abe'));
      $where->add(DB::parse('name=@s', 'Abe'));
      $where->negateLast();
      $this->assert(DB::queryFirstColumn("SELECT name FROM users WHERE @l", $where) === ['Bart']);
    } finally {
      DB::$param_char = '%';
    }
  }
}
