<?php

namespace App\Exceptions;

use App\Models\Customer;
use Exception;

class DuplicatePhoneException extends Exception
{
    public function __construct(public readonly Customer $existing)
    {
        parent::__construct('This phone number already belongs to a customer.');
    }
}
