<?php

declare(strict_types=1);

namespace Drupal\reports\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\reports\ReportStorage;

/**
 * Hook implementations for the Reports module.
 */
class ReportsHooks {

  public function __construct(
    protected readonly ReportStorage $reportStorage,
  ) {}

  /**
   * Implements hook_cron().
   *
   * Deletes old and abandoned generated report files.
   */
  #[Hook('cron')]
  public function cron(): void {
    $this->reportStorage->deleteExpired();
  }

}
