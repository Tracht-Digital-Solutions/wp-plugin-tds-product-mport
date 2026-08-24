<?php
/**
 * Optimistic locking conflict.
 *
 * @package TDS\ProductImporter
 */

namespace TDS\ProductImporter\Infrastructure;

use RuntimeException;

/**
 * Raised when a wizard draft was changed by another browser session.
 */
final class ConflictException extends RuntimeException {}
