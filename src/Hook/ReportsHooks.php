<?php

declare(strict_types=1);

namespace Drupal\reports\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\reports\ReportManager;
use Drupal\reports\ReportStorage;

/**
 * Hook implementations for the Reports module.
 */
class ReportsHooks {

  public function __construct(
    protected readonly ReportStorage $reportStorage,
    protected readonly ReportManager $reportManager,
  ) {}

  /**
   * Implements hook_runtime_requirements().
   *
   * Only the module's own requirements, such as storage for generated
   * reports. Each report's requirements are shown on its page.
   */
  #[Hook('runtime_requirements')]
  public function runtimeRequirements(): array {
    return $this->reportManager->getGlobalRequirements();
  }

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
