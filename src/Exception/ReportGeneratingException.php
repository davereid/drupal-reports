<?php

declare(strict_types=1);

namespace Drupal\reports\Exception;

/**
 * Thrown when a report is already being generated with the same options.
 */
class ReportGeneratingException extends \RuntimeException {}
