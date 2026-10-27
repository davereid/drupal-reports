<?php

declare(strict_types=1);

namespace Drupal\reports\Hook;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Hook implementations for the DataTables libraries.
 *
 * Kept apart from the reports hooks, to move to a DataTables module later.
 */
class DataTablesHooks {

  public function __construct(
    protected readonly ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * Implements hook_library_info_alter().
   *
   * The DataTables exposed form uses the markup of Views' exposed forms.
   * Admin themes add their exposed form styles to Views' library (Claro does
   * it with libraries-extend), so depend on it when Views is installed.
   */
  #[Hook('library_info_alter')]
  public function libraryInfoAlter(array &$libraries, string $extension): void {
    if ($extension === 'reports' && isset($libraries['drupal.datatables.exposedForm']) && $this->moduleHandler->moduleExists('views')) {
      $libraries['drupal.datatables.exposedForm']['dependencies'][] = 'views/views.module';
    }
  }

}
