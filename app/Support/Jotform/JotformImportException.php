<?php

namespace App\Support\Jotform;

use RuntimeException;

/**
 * An import or mapping that was refused. The message is written for the
 * Admin and shown as is.
 */
class JotformImportException extends RuntimeException {}
