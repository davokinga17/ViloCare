<?php

namespace App\Support;

use Illuminate\Validation\Rules\Password;

/** The one password rule used by every form that sets a password. */
class PasswordRules
{
    public static function rule(): Password
    {
        return Password::min(8)->letters()->numbers();
    }
}
