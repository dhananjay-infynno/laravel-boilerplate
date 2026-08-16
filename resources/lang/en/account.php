<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Account messages
|--------------------------------------------------------------------------
|
| Success messages and the labels for App\Enums\AccountStatus. For humans, and
| they WILL change — clients switch on `status` and `error_code`, never on
| these strings.
|
*/

return [

    'created' => 'Account created successfully.',
    'updated' => 'Account updated successfully.',
    'deleted' => 'Account deleted successfully.',
    'main_set' => 'Main account updated successfully.',
    'reordered' => 'Accounts reordered successfully.',

    'status' => [
        'active' => 'Active',
        'inactive' => 'Inactive',
    ],

];
