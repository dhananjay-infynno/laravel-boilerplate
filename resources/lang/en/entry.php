<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Entry messages
|--------------------------------------------------------------------------
|
| Plus the labels for App\Enums\EntryType, EntryDirection and EntryStatus.
|
*/

return [

    'created' => 'Entry recorded successfully.',
    'updated' => 'Entry updated successfully.',
    'deleted' => 'Entry deleted successfully.',

    'type' => [
        'credit_entry' => 'Credit',
        'debit_entry' => 'Debit',
        'account_to_account' => 'Transfer',
        'external_transfer' => 'External transfer',
    ],

    'direction' => [
        'in' => 'In',
        'out' => 'Out',
    ],

    'status' => [
        'completed' => 'Completed',
        'pending' => 'Pending',
        'accepted' => 'Accepted',
        'rejected' => 'Rejected',
        'cancelled' => 'Cancelled',
        'expired' => 'Expired',
    ],

];
