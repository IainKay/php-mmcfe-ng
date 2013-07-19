These are the scripts that I used to successfully migrate from mmcfe to mmcfe-ng.

> Step 1: The migration

In order to migrate from mmcfe to mmcfe-ng properly a number of fields must be extracted
from the original mmcfe database and inserted into the new mmcfe-ng database.

The networkBlocks table requires some work. We must strip out all blocks that we did not
mine and add blockhashes to each block. The latter can be performed via cURL or litecoind.

Winning shares are migrated and are the first shares inserted into the shares_archive table.

We copy transactions across, but have found that in some circumstances mmcfe may not keep an
accurate enough record of transactions to tally up correctly in mmcfe-ng - where everything
is accounted for.

This is where the latter script comes in, if required!

> Step 2: Sync balances

When I migrated our pool from mmcfe to mmcfe-ng the balances didn't add up for all of our
users. Whilst most were close, they were not close enough. To fix this I whipped up the
sync balances script that will quickly compare between mmcfe and mmcfe-ng and correct
for any inconsistencies.

To fix this the script will apply a Credit or Debit_AP to each account - bringing the users
balance into line. This Credit or Debit_AP is applied to the first block mined, id 1, as id 0
doesn't insert.

--------------------------------------------------------------------------------
NOTE: DO NOT UNDER ANY CIRCUMSTANCES RUN 002_sync_balance.php AFTER MIGRATION!
--------------------------------------------------------------------------------

WARNING:

Whilst these scripts worked for me, I make no guarantees that they will for you.
They are still messy code but should do the job.
Report any issues to @IainKay on GitHub.
