<?php

/*
    !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!
    !! BEGIN USER CONFIGURATION !!
    !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!
*/

/* -- Connect to the original mmcfe database -- */
$dbo_hostname = 'localhost';
$dbo_username = 'root';
$dbo_password = 'changeme!';
$dbo_database = 'mmcfe';

/* -- Connect to the new mmcfe-ng database -- */
$dbn_hostname = 'localhost';
$dbn_username = 'root';
$dbn_password = 'changeme!';
$dbn_database = 'mmcfe_ng';

/* -- Define how we should lookup block hash from block height --
  litecoind: execute litecoind on the command line for the block hash [fast]
             requires up to date blockchain. this script must be run as the same user that runs litecoind.
  curl:      look up block-explorer.com for the block hash [slow]
             takes a lot longer to gather data than litecoind but can be used without a full synced blockchain.
*/
$get_hash_method = 'litecoind';

/* -- Configuration for litecoind -- */
$litecoind_path = '/usr/local/bin/litecoind';

/* -- Offset for transactions -- */
/* If you don't want to import all past transactions then offset with ID here. */
$transactions_offset = 0;

/*
    !!!!!!!!!!!!!!!!!!!!!!!!!!!!
    !! END USER CONFIGURATION !!
    !!!!!!!!!!!!!!!!!!!!!!!!!!!!
*/


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

// Function to time execution
function microtime_float()
{
    list($usec, $sec) = explode(" ", microtime());
    return ((float)$usec + (float)$sec);
}

// Function to get hash from height at block-explorer.com
function get_hash($height) {
	global $get_hash_method;
	if ($get_hash_method == "curl") {
		$url = "http://block-explorer.com/search?search=".$height;
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(
		    'Connection: Keep-Alive',
		    'Keep-Alive: 300'
		));
		$content = curl_exec($ch);
		preg_match("/<table class='nav'><tr><td>Hash:<\/td><td>(.*)<\/td><\/tr><tr><td>Previous Block:<\/td>/", $content, $matches);
		return $matches[1];
	} elseif ($get_hash_method == "litecoind") {
		global $litecoind_path;
		$cmd = "$litecoind_path getblockhash $height";
		$result = trim(`$cmd`);
		return $result;
	}
}

// Log start time
$time_start = microtime_float();

// Setup array for mmcfe-ng users and define some counters
$account_id = 0;
$worker_id = 0;
$block_id = 0;
$transaction_id = 0;
$round_share_id = 0;
$round_share_history_id = 0;

// Get webUsers
echo "[BEGIN] Building array of users...\n";
$query = "SELECT u.id, u.admin, u.username, u.pass, u.email, u.loggedIp, u.accountLocked, u.accountFailedAttempts, u.pin, u.api_key, u.donate_percent, b.sendAddress, b.threshold FROM webUsers u LEFT JOIN accountBalance b ON u.id = b.userId ORDER BY u.id ASC";
if ($result = $mysqlo->query($query)) {
	while ($row = $result->fetch_object()) {
		$account_id++;
		$accounts[$account_id] = array();
		$accounts[$account_id]['id'] = $account_id;
		$oAccounts[$row->id]['id'] = $account_id;
		$accounts[$account_id]['is_admin'] = $row->admin;
		$accounts[$account_id]['username'] = $row->username;
		$accounts[$account_id]['pass'] = $row->pass;
		if ($row->email !== "") {
			$accounts[$account_id]['email'] = $row->email;
		} else {
			if (!isset($null_email_count)) {
				$null_email_count = 1;
			} else {
				$null_email_count++;
			}
			$accounts[$account_id]['email'] = "NULLEMAIL-$null_email_count";
		}
		$accounts[$account_id]['loggedIp'] = $row->loggedIp;
		$accounts[$account_id]['is_locked'] = $row->accountLocked;
		$accounts[$account_id]['failed_logins'] = $row->accountFailedAttempts;
		$accounts[$account_id]['sessionTimeoutStamp'] = null;
		$accounts[$account_id]['pin'] = $row->pin;
		$accounts[$account_id]['api_key'] = $row->api_key;
		$accounts[$account_id]['token'] = null;
		$accounts[$account_id]['donate_percent'] = $row->donate_percent;
		$accounts[$account_id]['ap_threshold'] = $row->threshold;
		$accounts[$account_id]['coin_address'] = $row->sendAddress;
		// We need to associate workers to the new users
		$query3 = "SELECT `id`, `associatedUserId`, `username`, `password` FROM pool_worker WHERE `associatedUserId` = '".$row->id."'";
		if ($result3 = $mysqlo->query($query3)) {
			while ($row3 = $result3->fetch_object()) {
				$worker_id++;
				$workers[$worker_id]['id'] = $worker_id;
				$workers[$worker_id]['account_id'] = $account_id;
				$workers[$worker_id]['username'] = $row3->username;
				$workers[$worker_id]['password'] = $row3->password;
				$workers[$worker_id]['monitor'] = 0;
			}
		} else {
			die("[ERROR] Unable to fetch table 'pool_worker' from original mmcfe database: ".$mysqlo->error."\n");
		}
	}
} else {
	die("[ERROR] Unable to fetch table 'webUsers' from original mmcfe database: ".$mysqlo->error."\n");
}

echo "[END] Found $account_id accounts and $worker_id workers.\n";

// Gather blocks
echo "[BEGIN] Building array of blocks...\n";
$query = "SELECT n.blockNumber, n.timestamp, n.confirms, n.difficulty, p.associatedUserId, w.username, w.shareCount FROM networkBlocks n RIGHT JOIN winning_shares w ON n.blockNumber = w.blockNumber LEFT JOIN pool_worker p ON w.username = p.username WHERE n.confirms > 0 ORDER BY n.blockNumber ASC;";
if ($result = $mysqlo->query($query)) {
	while ($row = $result->fetch_object()) {
		$block_id++;
		// blocks
		$blocks[$block_id]['id'] = $block_id;
		$oBlocks[$row->blockNumber]['id'] = $block_id;
		$blocks[$block_id]['height'] = $row->blockNumber;
		$blocks[$block_id]['blockhash'] = get_hash($row->blockNumber);
		echo "Getting blockhash for height: [".$row->blockNumber."] => hash: [".$blocks[$block_id]['blockhash']."]\n";
		$blocks[$block_id]['confirmations'] = $row->confirms;
		$blocks[$block_id]['amount'] = 50;
		$blocks[$block_id]['difficulty'] = $row->difficulty;
		$blocks[$block_id]['time'] = $row->timestamp;
		$blocks[$block_id]['accounted'] = 1;
		if (isset($oAccounts[$row->associatedUserId])) {
			$blocks[$block_id]['account_id'] = $oAccounts[$row->associatedUserId]['id'];
		} else {
			$blocks[$block_id]['account_id'] = 0;
		}
		$blocks[$block_id]['shares'] = $row->shareCount;
		$blocks[$block_id]['share_id'] = $block_id;
		// shares
		$shares[$block_id]['id'] = $block_id;
		$shares[$block_id]['share_id'] = $block_id;
		$shares[$block_id]['username'] = $row->username;
		$shares[$block_id]['our_result'] = 'Y';
		$shares[$block_id]['upstream_result'] = 'Y';
		$shares[$block_id]['block_id'] = $block_id;
		$shares[$block_id]['time'] = $row->timestamp;
	}
} else {
	die("[ERROR] Unable to fetch table 'networkBlocks' from original mmcfe database: ".$mysqlo->error."\n");
}
echo "[END] Found $block_id blocks.\n";
// End gather blocks

// Gather transactions
echo "[BEGIN] Building array of transactions...\n";
$query = "SELECT l.id, l.userId, l.transType, l.sendAddress, l.amount, l.feeAmount, l.assocBlock, l.timestamp FROM ledger l RIGHT JOIN webUsers u ON l.userId = u.id WHERE `userId` != 0 AND l.id > $transactions_offset ORDER BY id ASC";
if ($result = $mysqlo->query($query)) {
	while ($row = $result->fetch_object()) {
		$transaction_id++;
		$transactions[$transaction_id]['id'] = $transaction_id;
		if (isset($oAccounts[$row->userId])) {
			$transactions[$transaction_id]['account_id'] = $oAccounts[$row->userId]['id'];
		} else {
			die("transaction id:".$row->id." userId: ".$row->userId." FAIL!\n");
			$transactions[$transaction_id]['account_id'] = 0;
		}
		if ($row->transType == "Debit_ATP") { $row->transType = "Debit_AP"; }
		$transactions[$transaction_id]['type'] = $row->transType;
		$transactions[$transaction_id]['coin_address'] = $row->sendAddress;
		$transactions[$transaction_id]['amount'] = $row->amount;
		$transactions[$transaction_id]['fee_amount'] = $row->feeAmount;
		if (isset($oBlocks[$row->assocBlock])) {
			$transactions[$transaction_id]['block_id'] = $oBlocks[$row->assocBlock]['id'];
		} else {
			$transactions[$transaction_id]['block_id'] = 0;
		}
		$transactions[$transaction_id]['timestamp'] = $row->timestamp;
	}
} else {
	die("[ERROR] Unable to fetch table 'ledger' from original mmcfe database: ".$mysqlo->error."\n");
}
echo "[END] Found $transaction_id transactions.\n";
// End gather transactions

// Gather round shares
echo "[BEGIN] Building array of round shares...\n";
$query = "SELECT `id`, `rem_host`, `username`, `our_result`, `upstream_result`, `reason`, `solution`, `time` FROM shares ORDER BY id ASC";
if ($result = $mysqlo->query($query)) {
	while ($row = $result->fetch_object()) {
		$round_share_id++;
		$round_shares[$round_share_id]['id'] = $row->id;
		$round_shares[$round_share_id]['rem_host'] = $row->rem_host;
		$round_shares[$round_share_id]['username'] = $row->username;
		$round_shares[$round_share_id]['our_result'] = $row->our_result;
		$round_shares[$round_share_id]['upstream_result'] = $row->upstream_result;
		$round_shares[$round_share_id]['reason'] = $row->reason;
		$round_shares[$round_share_id]['solution'] = $row->solution;
		$round_shares[$round_share_id]['time'] = $row->time;
	}
} else {
	die("[ERROR] Unable to fetch table 'shares' from original mmcfe database: ".$mysqlo->error."\n");
}
echo "[END] Found $round_shares round shares.\n";
echo "[BEGIN] Building array of round shares_history...\n";
$query = "SELECT `id`, `blockNumber`, `username`, `our_result`, `upstream_result`, `time` FROM shares_history ORDER BY id ASC";
if ($result = $mysqlo->query($query)) {
	while ($row = $result->fetch_object()) {
		$round_share_history_id++;
		$round_shares_history[$round_share_history_id]['id'] = $row->id;
		$round_shares_history[$round_share_history_id]['username'] = $row->username;
		$round_shares_history[$round_share_history_id]['our_result'] = $row->our_result;
		$round_shares_history[$round_share_history_id]['upstream_result'] = $row->upstream_result;
		$round_shares_history[$round_share_history_id]['block_id'] = $oBlocks[$row->blockNumber]['id'];
		$round_shares_history[$round_share_history_id]['time'] = $row->time;
	}
} else {
	die("[ERROR] Unable to fetch table 'shares' from original mmcfe database: ".$mysqlo->error."\n");
}
echo "[END] Found $round_shares round shares.\n";

// End gather round shares

// Insert 'accounts'
echo "[BEGIN] Inserting accounts into database...\n";
function build_account_query() {
	global $account;
	global $mysqln;
	$query = "INSERT INTO accounts (`id`, `is_admin`, `username`, `pass`, `email`, `loggedIp`, `is_locked`, `failed_logins`, `sessionTimeoutStamp`, `pin`, `api_key`, `token`, `donate_percent`, `ap_threshold`, `coin_address`) VALUES (";
	$query .= "'".$mysqln->real_escape_string($account['id'])."', ";
	$query .= "'".$mysqln->real_escape_string($account['is_admin'])."', ";
	$query .= "'".$mysqln->real_escape_string($account['username'])."', ";
	$query .= "'".$mysqln->real_escape_string($account['pass'])."', ";
	$query .= "'".$mysqln->real_escape_string($account['email'])."', ";
	$query .= "'".$mysqln->real_escape_string($account['loggedIp'])."', ";
	$query .= "'".$mysqln->real_escape_string($account['is_locked'])."', ";
	$query .= "'".$mysqln->real_escape_string($account['failed_logins'])."', ";
	$query .= "'".$mysqln->real_escape_string($account['sessionTimeoutStamp'])."', ";
	$query .= "'".$mysqln->real_escape_string($account['pin'])."', ";
	$query .= "'".$mysqln->real_escape_string($account['api_key'])."', ";
	$query .= "'".$mysqln->real_escape_string($account['token'])."', ";
	$query .= "'".$mysqln->real_escape_string($account['donate_percent'])."', ";
	$query .= "'".$mysqln->real_escape_string($account['ap_threshold'])."', ";
	$query .= "'".$mysqln->real_escape_string($account['coin_address'])."')";
	return $query;
}
foreach ($accounts as $account) {
	$query = build_account_query();
	if ($result = $mysqln->query($query)) {
		//echo "[OKAY] Inserted user ".$account['username']."\n";
	} else {
		if (preg_match("/Duplicate(.*)email/", $mysqln->error)) {
			$null_email_count++;
			$account['email'] = $account['email']."-DUPEEMAIL$null_email_count";
			$query = build_account_query();
			if ($result = $mysqln->query($query)) {
				//echo "[OKAY] Inserted user ".$account['username']."\n";
			} else {
				die("[ERROR] Failed inserting user ".$account['username'].": ".$mysqln->error."\n");
			}
		} else {
			die("[ERROR] Failed inserting user ".$account['username'].": ".$mysqln->error."\n");
		}
	}
}
echo "[END] Inserted all users!\n";
// End 'accounts'

// Insert 'workers'
echo "[BEGIN] Inserting workers into database...\n";
foreach ($workers as $worker) {
	$query = "INSERT INTO pool_worker (`id`, `account_id`, `username`, `password`, `monitor`) VALUES (";
	$query .= "'".$mysqln->real_escape_string($worker['id'])."', ";
	$query .= "'".$mysqln->real_escape_string($worker['account_id'])."', ";
	$query .= "'".$mysqln->real_escape_string($worker['username'])."', ";
	$query .= "'".$mysqln->real_escape_string($worker['password'])."', ";
	$query .= "'".$mysqln->real_escape_string($worker['monitor'])."')";
	if ($result = $mysqln->query($query)) {
		//echo "[OKAY] Inserted worker ".$worker['username']."\n";
	} else {
		die("[ERROR] Failed inserting worker ".$worker['username'].": ".$mysqln->error."\n");
	}
}
echo "[END] Inserted all workers!\n";
// End 'workers'

// Insert 'blocks'
echo "[BEGIN] Inserting blocks into database...\n";
foreach ($blocks as $block) {
	$query = "INSERT INTO blocks (`id`, `height`, `blockhash`, `confirmations`, `amount`, `difficulty`, `time`, `accounted`, `account_id`, `shares`, `share_id`) VALUES (";
	$query .= "'".$mysqln->real_escape_string($block['id'])."', ";
	$query .= "'".$mysqln->real_escape_string($block['height'])."', ";
	$query .= "'".$mysqln->real_escape_string($block['blockhash'])."', ";
	$query .= "'".$mysqln->real_escape_string($block['confirmations'])."', ";
	$query .= "'".$mysqln->real_escape_string($block['amount'])."', ";
	$query .= "'".$mysqln->real_escape_string($block['difficulty'])."', ";
	$query .= "'".$mysqln->real_escape_string($block['time'])."', ";
	$query .= "'".$mysqln->real_escape_string($block['accounted'])."', ";
	$query .= "'".$mysqln->real_escape_string($block['account_id'])."', ";
	$query .= "'".$mysqln->real_escape_string($block['shares'])."', ";
	$query .= "'".$mysqln->real_escape_string($block['share_id'])."')";
	if ($result = $mysqln->query($query)) {
		//echo "[OKAY] Inserted block ".$block['height']."\n";
	} else {
		die("[ERROR] Failed inserting block ".$block['height'].": ".$mysqln->error."\n");
	}
}
echo "[END] Inserted all blocks!\n";
// End 'blocks'

// Insert 'shares'
echo "[BEGIN] Inserting shares into database...\n";
foreach ($shares as $share) {
	$query = "INSERT INTO shares_archive (`id`, `share_id`, `username`, `our_result`, `upstream_result`, `block_id`, `time`) VALUES (";
	$query .= "'".$mysqln->real_escape_string($share['id'])."', ";
	$query .= "'".$mysqln->real_escape_string($share['share_id'])."', ";
	$query .= "'".$mysqln->real_escape_string($share['username'])."', ";
	$query .= "'".$mysqln->real_escape_string($share['our_result'])."', ";
	$query .= "'".$mysqln->real_escape_string($share['upstream_result'])."', ";
	$query .= "'".$mysqln->real_escape_string($share['block_id'])."', ";
	$query .= "'".$mysqln->real_escape_string($share['time'])."')";
	if ($result = $mysqln->query($query)) {
		//echo "[OKAY] Inserted share ".$share['id']."\n";
	} else {
		die("[ERROR] Failed inserting share ".$share['id'].": ".$mysqln->error."\n");
	}
}
echo "[END] Inserted all shares!\n";
echo "[BEGIN] Inserting round shares into database...\n";
foreach ($round_shares as $share) {
	$query = "INSERT INTO shares (`id`, `rem_host`, `username`, `our_result`, `upstream_result`, `reason`, `solution`, `time`) VALUES (";
	$query .= "'".$mysqln->real_escape_string($share['id'])."', ";
	$query .= "'".$mysqln->real_escape_string($share['rem_host'])."', ";
	$query .= "'".$mysqln->real_escape_string($share['username'])."', ";
	$query .= "'".$mysqln->real_escape_string($share['our_result'])."', ";
	$query .= "'".$mysqln->real_escape_string($share['upstream_result'])."', ";
	$query .= "'".$mysqln->real_escape_string($share['reason'])."', ";
	$query .= "'".$mysqln->real_escape_string($share['solution'])."', ";
	$query .= "'".$mysqln->real_escape_string($share['time'])."')";
	if ($result = $mysqln->query($query)) {
		//echo "[OKAY] Inserted round share ".$share['id']."\n";
	} else {
		die("[ERROR] Failed inserting round share ".$share['id'].": ".$mysqln->error."\n");
	}
}
echo "[END] Inserted all round shares!\n";
// End 'shares'

// Insert 'transactions'
echo "[BEGIN] Inserting transactions into database...\n";
foreach ($transactions as $transaction) {
	$query = "INSERT INTO transactions (`id`, `account_id`, `type`, `coin_address`, `amount`, `block_id`, `timestamp`) VALUES (";
	$query .= "NULL, ";
	$query .= "'".$mysqln->real_escape_string($transaction['account_id'])."', ";
	$query .= "'".$mysqln->real_escape_string($transaction['type'])."', ";
	$query .= "'".$mysqln->real_escape_string($transaction['coin_address'])."', ";
	$query .= "'".$mysqln->real_escape_string($transaction['amount'])."', ";
	$query .= "'".$mysqln->real_escape_string($transaction['block_id'])."', ";
	$query .= "'".$mysqln->real_escape_string($transaction['timestamp'])."')";
	if ($result = $mysqln->query($query)) {
		//echo "[OKAY] Inserted transaction ".$transaction['id']."\n";
	} else {
		die("[ERROR] Failed inserting transaction ".$transaction['id'].": ".$mysqln->error."\n");
	}
	// If there's a donation fee we want to add this
	if ($transaction['fee_amount'] > 0) {
		$query = "INSERT INTO transactions (`id`, `account_id`, `type`, `coin_address`, `amount`, `block_id`, `timestamp`) VALUES (";
		$query .= "NULL, ";
		$query .= "'".$mysqln->real_escape_string($transaction['account_id'])."', ";
		$query .= "'Donation', ";
		$query .= "NULL, ";
		$query .= "'".$transaction['fee_amount']."', ";
		$query .= "'".$mysqln->real_escape_string($transaction['block_id'])."', ";
		$query .= "'".$mysqln->real_escape_string($transaction['timestamp'])."')";
		if ($result = $mysqln->query($query)) {
			//echo "[OKAY] Inserted transaction ".$transaction['id']."\n";
		} else {
			die("[ERROR] Failed inserting Donation fee for transaction ".$transaction['id'].": ".$mysqln->error."\n");
		}
	}
	// If it's a Debit then we want to add a TX Fee
	if ($transaction['type'] == "Debit_AP" || $transaction['type'] == "Debit_MP") {
		$query = "INSERT INTO transactions (`id`, `account_id`, `type`, `coin_address`, `amount`, `block_id`, `timestamp`) VALUES (";
		$query .= "NULL, ";
		$query .= "'".$mysqln->real_escape_string($transaction['account_id'])."', ";
		$query .= "'TXFee', ";
		$query .= "NULL, ";
		$query .= "'0.1', ";
		$query .= "'".$mysqln->real_escape_string($transaction['block_id'])."', ";
		$query .= "'".$mysqln->real_escape_string($transaction['timestamp'])."')";
		if ($result = $mysqln->query($query)) {
			//echo "[OKAY] Inserted transaction ".$transaction['id']."\n";
		} else {
			die("[ERROR] Failed inserting TX fee for transaction ".$transaction['id'].": ".$mysqln->error."\n");
		}
	}
}
echo "[END] Inserted all transactions!\n";
// End 'transactions'

// Compare user balance before and now.


// Log end time
$time_end = microtime_float();

// Calculate time
$time = $time_end - $time_start;
echo "\n\nMigrated mmcfe to mmcfe-ng in $time seconds\n";
