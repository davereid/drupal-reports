<?php

declare(strict_types=1);

namespace Drupal\reports\Hook;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\reports\ReportStorage;

/**
 * Hook implementations for the Reports module.
 */
class ReportsHooks {

  public function __construct(
    protected readonly ReportStorage $reportStorage,
    protected readonly ModuleHandlerInterface $moduleHandler,
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

  /**
   * Implements hook_library_info_alter().
   *
   * Report table filters use the markup of Views' exposed forms. Admin themes
   * add their exposed form styles to Views' library (Claro does it with
   * libraries-extend), so depend on it when Views is installed.
   */
  #[Hook('library_info_alter')]
  public function libraryInfoAlter(array &$libraries, string $extension): void {
    if ($extension === 'reports' && isset($libraries['report']) && $this->moduleHandler->moduleExists('views')) {
      $libraries['report']['dependencies'][] = 'views/views.module';
    }
  }

}
