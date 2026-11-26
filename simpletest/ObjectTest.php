<?php
// everything else goes through DB::, which hands each call to a shared MeekroDB instance,
// so this only checks what's particular to creating your own instances
class ObjectTest extends SimpleTest {
  function __construct() {
    $this->resetDatabase();
  }

  // * a new MeekroDB copies DB's settings when it's created, then keeps its own
  // * hooks belong to one instance
  function test_1_separate_instance() {
    $mdb = new MeekroDB();
    $mdb->param_char = '@';
    $this->assert(DB::$param_char === '%');
    $this->assert($mdb->queryFirstField("SELECT @i + 1", 1) === '2');
    $this->assert(DB::queryFirstField("SELECT %i + 1", 1) === '2');

    $funcs = [];
    $mdb->addHook('pre_run', function($hash) use (&$funcs) {
      $funcs[] = $hash['func_name'];
    });
    $mdb->queryFirstField("SELECT @i + 1", 2);
    DB::queryFirstField("SELECT %i + 1", 2);
    $this->assert($funcs === ['queryFirstField']);
  }

  // * constructor args override DB's settings
  function test_2_constructor_args() {
    $mdb = new MeekroDB('nonsense:host=nowhere');
    $this->assertThrows(function() use ($mdb) { $mdb->get(); }, 'Invalid DSN');
  }
}
