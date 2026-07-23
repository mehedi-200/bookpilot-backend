<?php

namespace App\Support;

/**
 * THE single place phone numbers are normalized (CustomerService, booking
 * forms, agent tools all pass through here). Stored form: local digits.
 */
class PhoneNumber
{
    public static function normalize(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone);

        if ($digits === '') {
            return null;
        }

        // International prefix variants collapse to the local form:
        // 00880 1712… / 880 1712… / +880 1712… → 01712…
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }
        if (str_starts_with($digits, '880')) {
            $digits = '0'.substr($digits, 3);
        }

        return $digits;
    }
}
