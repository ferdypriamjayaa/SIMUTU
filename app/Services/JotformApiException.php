<?php

namespace App\Services;

use RuntimeException;

/**
 * A Jotform API request failed. The message is for SIMUTU administrators
 * (Indonesian) and never contains the API key.
 */
class JotformApiException extends RuntimeException {}
