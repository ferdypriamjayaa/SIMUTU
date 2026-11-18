<?php

namespace App\Services;

use RuntimeException;

/**
 * The HRIS employee list could not be obtained. The message is written for
 * SIMUTU users (Indonesian) and never contains credentials or employee data.
 */
class HrisException extends RuntimeException {}
