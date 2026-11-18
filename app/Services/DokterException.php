<?php

namespace App\Services;

use RuntimeException;

/**
 * The doctor list could not be obtained from the Doctor API. The message is
 * written for SIMUTU users (Indonesian) and never contains credentials.
 */
class DokterException extends RuntimeException {}
