<?php

require_once('virtuemart_lib.php');

if (!isset($argv[1])) {
  echo "Kérlek add meg a megrendelés ID-t!\n";
  exit;
}

sendOrder($argv[1]);
