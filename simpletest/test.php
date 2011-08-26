#!/usr/bin/php
<?php
class SimpleTest {
  protected function assert($boolean) {
    if (! $boolean) $this->fail();
  }

  protected function fail($msg = '') {
    echo "FAILURE! $msg\n";
    debug_print_backtrace();
    die;
  }
  
  public static function __listfiles($dir, $regex, $type='files', $rec = false) {
    $A = array();
    
    if (! $dir_handler = @opendir($dir)) return $A;
    
    while (false !== ($filename = @readdir($dir_handler))) {
      if ($filename == '.' || $filename == '..') continue;
      if ($rec && is_dir("$dir/$filename")) $A = array_merge($A, File::listfiles("$dir/$filename", $regex, $type, true));
      
      if (! preg_match($regex, $filename)) continue;
      if ($type == 'files' && ! is_file("$dir/$filename")) continue;
      if ($type == 'dirs' && ! is_dir("$dir/$filename")) continue;
      if ($type == 'symlinks' && ! is_link("$dir/$filename")) continue;
      
      $A[] = $dir . DIRECTORY_SEPARATOR . $filename;
    }
    return $A;
  }

  

}

$files = SimpleTest::__listfiles(dirname(__FILE__), '/^.*php$/i');

$classes_to_test = array();
foreach ($files as $fullpath) {
  $filename = basename($fullpath);
  if ($fullpath == __FILE__) continue;
  if ($filename == 'test_setup.php') continue;
  require_once($fullpath);
  $classes_to_test[] = str_replace('.php', '', $filename);
}

foreach ($classes_to_test as $class) {
  $object = new $class();
  
  foreach (get_class_methods($object) as $method) {
    if (substr($method, 0, 4) != 'test') continue;
    echo "Running $class::$method..\n";
    $object->$method();
  }
}



?>
