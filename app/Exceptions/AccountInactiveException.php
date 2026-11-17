<?php

namespace App\Exceptions;

use RuntimeException;

/** Akun ada dan password benar, tetapi statusnya bukan "active". */
class AccountInactiveException extends RuntimeException {}
