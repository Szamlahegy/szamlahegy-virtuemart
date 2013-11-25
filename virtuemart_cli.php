<?php

require_once('virtuemart_lib.php');

if (!isset($argv[1])) {
  echo "Please specify order id in parameter!\n";
  exit;
}

sendOrder($argv[1]);
