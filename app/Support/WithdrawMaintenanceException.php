<?php

namespace App\Support;

/** Thrown by WithdrawalService::createRequest while "ปิดปรับปรุงถอนเงิน" is on. */
class WithdrawMaintenanceException extends \Exception
{
    public function __construct(public readonly string $title, string $message)
    {
        parent::__construct($message);
    }
}
