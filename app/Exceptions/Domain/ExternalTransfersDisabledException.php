<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use App\Enums\ErrorCode;

/**
 * ONE generic failure covering every external-transfer lookup problem: no such
 * account number, inactive, soft-deleted, the owner has transfers switched off,
 * or it is the sender's own account.
 *
 * They are deliberately indistinguishable. Any difference in response — status,
 * message, even timing — lets someone walk the 887-million-key account-number
 * space and map which numbers belong to real, active users. `meta()` stays
 * empty for the same reason.
 */
final class ExternalTransfersDisabledException extends DomainException
{
    public function __construct()
    {
        parent::__construct((string) __('errors.external_transfers_disabled'));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::ExternalTransfersDisabled;
    }
}
