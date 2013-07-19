<?php
// This should be okay
define("BASEPATH", "./");

// Our security check
define("SECURITY", 1);

// Include our configuration (holding defines for the requires)
if (!include_once(BASEPATH . 'include/config/global.inc.php')) die('Unable to load site configuration');

// Load Classes, they name defines the $ variable used
// We include all needed files here, even though our templates could load them themself
require_once(INCLUDE_DIR . '/autoloader.inc.php');

// Connect to old DB
$dbo_hostname = 'localhost';
$dbo_username = 'root';
$dbo_password = 'changeme!';
$dbo_database = 'mmcfe_ltc';

/* -- Connect to the new mmcfe-ng database -- */
$dbn_hostname = 'localhost';
$dbn_username = 'root';
$dbn_password = 'changeme!';
$dbn_database = 'mmcfe_ng_ltc';

// Connect to original database
$mysqlo = new mysqli($dbo_hostname, $dbo_username, $dbo_password, $dbo_database);
if ($mysqlo->connect_error) {
   die("[ERROR] Unable to connect to original mmcfe database: " . $mysqlo->connect_error."\n");
}
else {
   echo "[OKAY] Connected to original mmcfe database.\n";
}

// Connect to new database
$mysqln = new mysqli($dbn_hostname, $dbn_username, $dbn_password, $dbn_database);
if ($mysqln->connect_error) {
   die("[ERROR] Unable to connect to new mmcfe-ng database: " . $mysqln->connect_error."\n");
}
else {
   echo "[OKAY] Connected to new mmcfe-ng database.\n";
}

$total = 0;

echo "<table><thead><tr><th>Username</th><th>Balance</th><th>New Balance</th><td>Query</td></tr></thead><tbody>";
$query = "SELECT id, username FROM accounts ORDER BY id ASC";
if ($result = $mysqln->query($query)) {
	while ($row = $result->fetch_object()) {
		$query = "SELECT u.id, a.balance FROM webUsers u LEFT JOIN accountBalance a ON a.userId = u.id WHERE u.username = '".$row->username."'";
		if ($result2 = $mysqlo->query($query)) {
			while ($row2 = $result2->fetch_object()) {
				$balance = $row2->balance;
			}
		}
		$new_balance = $transaction->getBalance($row->id)['confirmed'];
		$diff = ($balance - $new_balance);
		$diff = round($diff, 12);
		if ($diff != 0) {
			if ($diff > 0) {
				$type = "Credit";
				$go = 1;
			} elseif ($diff < 0) {
				$type = "Debit_AP";
				$go = 1;
			} else {
				$go = 0;
			}
			$query = "INSERT INTO transactions (`id`, `account_id`, `type`, `coin_address`, `amount`, `block_id`, `timestamp`) VALUES (NULL, '".$row->id."', '$type', '', '$diff', '1', '2013-07-14 21:00:00')";
				if ($result3 = $mysqln->query($query)) {
					$corrected = $diff;
				}
		}
		echo "<tr><td>*****</td><td>$balance</td><td>".$transaction->getBalance($row->id)['confirmed']."</td><td>$query</td></tr>";
	}
}
echo "<tr><td>TOTAL</td><td>$total</td><td></td></tr>";
echo "</tbody></table>";

//$aTransactions = $transaction->getBalance("1741");
//print_r($aTransactions);
