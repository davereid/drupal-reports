<?php

declare(strict_types=1);

namespace Drupal\reports\Hook;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\reports\ReportManager;
use Drupal\reports\ReportStorage;

/**
 * Hook implementations for the Reports module.
 */
class ReportsHooks {

  public function __construct(
    protected readonly ReportStorage $reportStorage,
    protected readonly ModuleHandlerInterface $moduleHandler,
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
