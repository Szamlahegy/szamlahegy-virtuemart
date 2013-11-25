<?php
/**
 * Számlahegy.hu plugin for Virtuemart
 *
 * @author Péter Képes
 * @version V1.0
 * @copyright Számlahegy.hu, 25 November, 2013
 **/
 
require_once('virtuemart_config.php');
require_once(API_PATH . '/classes.php');
require_once(API_PATH . '/api.php');
require_once(VIRTUEMART_CONFIG);

$jconfig = new JConfig();
define('MYSQL_USERNAME', $jconfig->user );
define('MYSQL_PASSWORD', $jconfig->password );
define('MYSQL_HOSTNAME', $jconfig->host );
define('MYSQL_DATABASE', $jconfig->db );
define('VMTABLE_PREFIX', TABLE_PREFIX . 'virtuemart_');
define('TABLE_NAME', TABLE_PREFIX . 'szamlahegy_sent');

function sendOrders($orders) {
  global $mysqli;
  
  if ($orders->num_rows == 0) {
    echo "There is no order for generate!\n";
    exit;
  }
  
  $szamlahegyApi = new SzamlahegyApi();
  $szamlahegyApi->openHTTPConnection();

  while ($order = $orders->fetch_object()){
    echo "--- Processing order #" . $order->virtuemart_order_id . "\n";

    $client = null;
    $client = query_one("SELECT * FROM " . VMTABLE_PREFIX . "vmusers u WHERE u.virtuemart_user_id = " . $order->virtuemart_user_id);
    
    $orderItems = query_all("SELECT * FROM " . VMTABLE_PREFIX . "order_items i WHERE i.virtuemart_order_id = " . $order->virtuemart_order_id);
    
    $orderUserinfo = query_one("SELECT * FROM " . VMTABLE_PREFIX . "order_userinfos u WHERE u.virtuemart_order_id = " . $order->virtuemart_order_id . " and u.address_type = 'BT'");

    $orderCountry = query_one("SELECT * FROM " . VMTABLE_PREFIX . "countries c WHERE c.virtuemart_country_id = " . $orderUserinfo->virtuemart_country_id);
    
    $i = new Invoice();
    
    if (is_null($orderUserinfo->company) || $orderUserinfo->company === "") {
      $i->customer_name = $orderUserinfo->last_name . ' ' . $orderUserinfo->first_name;
    } else {
      
      if ($orderCountry->country_2_code != 'HU' || checkCompanyName($orderUserinfo->company)) {
        $i->customer_name = $orderUserinfo->company;
      } else {
        echo "Invalid company name: " . $orderUserinfo->company . "\n";
        echo "Order id #" . $order->virtuemart_order_id . "\n";
        continue;
      }
    }

    $i->customer_city = $orderUserinfo->city;
    $i->customer_address = $orderUserinfo->address_1 . ' ' . $orderUserinfo->address_2;
    $i->payment_method = PAYMENT_METHOD;   
    $i->payment_date = time();
    $i->perform_date = $i->payment_date;

    $i->footer = 'Megrendelés száma: #' . $order->order_number . "</br>\n";

    $i->customer_zip = $orderUserinfo->zip;
    $i->kind = 'T';
    $i->tag = $order->order_number;
    $i->foreign_id = $order->order_number;
    $i->paid_at = $i->payment_date;
    $i->customer_email = $orderUserinfo->email;
    $i->customer_contact_name = $orderUserinfo->last_name . ' ' . $orderUserinfo->first_name;
    $i->customer_country = $orderCountry->country_2_code;
    
    $rows = array();
    $price = 0;
    
    while ($orterItem = $orderItems->fetch_object()){
      $row = new InvoiceRow();
      $row->productnr = $orterItem->order_item_sku;
      $row->name = $orterItem->order_item_name;
      $row->quantity = $orterItem->product_quantity;
      $row->quantity_type = QUANTITY_TYPE;
      $row->price_slab = intval($orterItem->product_item_price);
      $row->tax = intval($orterItem->product_tax);
      $rows[] = $row;
      $price += $row->quantity * $row->price_slab;
    }
    
    $i->invoice_rows_attributes = $rows;
    print_r($i);
    
    if ($price > 0) {
      if ($szamlahegyApi->sendNewInvoice($i)) {
        $mysqli->query("INSERT into " . TABLE_NAME . " (order_id,created_at) values (". $order->virtuemart_order_id . ",now())");
        echo "Invoice generation successed: #" . $order->virtuemart_order_id . "\n\n";
      } else {
        echo "Error during invoice generation #" . $order->virtuemart_order_id . "\n";
      }
    } else {
      echo "Invoice price is zero! #" . $order->virtuemart_order_id . "\n";
      $mysqli->query("INSERT into " . TABLE_NAME . " (order_id,created_at) values (". $order->virtuemart_order_id . ",now())");
    }
  }

  $szamlahegyApi->closeHTTPConnection();
}

function connectToMySql() {
  global $mysqli;
  $mysqli = new mysqli(MYSQL_HOSTNAME, MYSQL_USERNAME, MYSQL_PASSWORD, MYSQL_DATABASE);
  if ($mysqli->connect_errno) {
      echo "Failed to connect to MySQL: (" . $mysqli->connect_errno . ") " . $mysqli->connect_error . "\n";
      return false;
  }
  return $mysqli;
}

function sendAllNewOrder() {
  global $mysqli;
  if (!$mysqli = connectToMySql()) {
    exit;
  }
   
  $orders = query_all("SELECT * FROM " . VMTABLE_PREFIX . "orders o WHERE o.virtuemart_order_id not in (select order_id from " . TABLE_NAME . ") and o.created_on >= '" . START_DATE . "' order by o.created_on limit 0," . PROCESS_LIMIT);

  sendOrders($orders);
}

function sendOrder($id) {
  global $mysqli;
  if (!$mysqli = connectToMySql()) {
    exit;
  }
   
  $generated = query_all("SELECT * FROM " . TABLE_NAME . " t WHERE t.order_id = " . $id);
  if ($generated->num_rows != 0) {
    echo "Invoice already generated!\n";
    exit;
  }
  
  $order = query_all("SELECT * FROM " . VMTABLE_PREFIX . "orders o WHERE o.virtuemart_order_id = " . $id);
  sendOrders($order);
}

function checkCompanyName($name) {
  // A rövidítések előtt szándékosan van SPACE!
  $companyFormats = array(' kft', ' bt', ' zrt', ' nyrt', ' ev', ' e.v.', 'egyesület', 'mozgalom', 'Önkormányzat', 'iskola', ' khe', 'intézet', 'Ügyvédi Iroda', 'szövetség', 'alapítvány', 'Óvoda', 'ügyvéd', 'szakszervezet', 'szövetkezet', 'Football Club', 'egyéni vállalkozó', 'plébánia', ' szervezet', 'klebelsberg', 'alapítvány', 'polgárőrség', 'közösség', "kamara", "református", "klub", "club", "gyülekezet", "konzulátus", "társaság", " ec", "egyéni cég", "szolgáltató központ", " kkt", "közkereseti társaság", "ifjúsági otthon", " kik", " se.", "polgármesteri hivatal", "egyetem", " kha", "kereskedelmi képviselet", "gimnázium", "kollégium", "Lelkigyakorlatos Ház", "Nemzetőrség", "csapat", "felügyelőség", "oktatási központ", "színház", "KLIK ", "könyvtár", "múzeum", "község");
  
  for ($i = 0; $i<count($companyFormats); $i++) {
    if (stripos($name, $companyFormats[$i]) !== false) {
      return true;
    }
  }
  return false;
}

function query_one($query) {
  return query_all($query)->fetch_object();
}

function query_all($query) {
  global $mysqli;
  return $mysqli->query($query);
}



