<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use App\Enums\ErrorCode;

/**
 * The transfer is not PENDING, so this state transition is illegal.
 *
 * Also used when someone who is not the receiver tries to accept or reject:
 * a 404-shaped failure rather than a 403, because confirming the transfer
 * exists would leak that money was sent to a given account number.
 */
final class TransferNotPendingException extends DomainException
{
    public function __construct(private readonly string $currentStatus = '')
    {
        parent::__construct((string) __('errors.transfer_not_pending'));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::TransferNotPending;
    }

    public function meta(): array
    {
        return $this->currentStatus !== '' ? ['status' => $this->currentStatus] : [];
    }
}
