<?php

declare(strict_types=1);

namespace Drupal\reports;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\reports\Attribute\Report;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\reports\Plugin\Report\GeneratedReportInterface;
use Drupal\reports\Plugin\Report\ReportInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Manages report plugins.
 *
 * @method \Drupal\reports\Plugin\Report\ReportInterface createInstance($plugin_id, array $configuration = [])
 */
class ReportManager extends DefaultPluginManager {

  use StringTranslationTrait;

  public function __construct(
    #[Autowire(service: 'container.namespaces')]
    \Traversable $namespaces,
    #[Autowire(service: 'cache.discovery')]
    CacheBackendInterface $cache_backend,
    ModuleHandlerInterface $module_handler,
    protected readonly ReportStorage $reportStorage,
  ) {
    parent::__construct('Plugin/Report', $namespaces, $module_handler, ReportInterface::class, Report::class);
    $this->alterInfo('report_info');
    $this->setCacheBackend($cache_backend, 'report_plugins');
  }

  /**
   * Gets a report's requirements, including storage for generated reports.
   *
   * @param \Drupal\reports\Plugin\Report\ReportInterface $report
   *   The report.
   *
   * @return array
   *   The report's own requirements (ReportInterface::getRequirements()), and
   *   for generated reports, 'reports_storage': an error if they can't be
   *   stored, or a warning if they're in the public or temporary directory.
   */
  public function getRequirements(ReportInterface $report): array {
    $requirements = $report->getRequirements();
    if ($report instanceof GeneratedReportInterface && ($storage = $this->getStorageRequirement())) {
      $requirements['reports_storage'] = $storage;
    }
    return $requirements;
  }

  /**
   * Gets the requirements of the module, shown on the status report.
   *
   * Only what applies to every report, such as storage for generated
   * reports. Each report's own requirements are shown on its page.
   *
   * @return array
   *   'reports_storage', if any generated report applies to the site and its
   *   storage has a problem.
   */
  public function getGlobalRequirements(): array {
    foreach (array_keys($this->getDefinitions()) as $id) {
      $report = $this->createInstance($id);
      if ($report instanceof GeneratedReportInterface && $report->isApplicable()) {
        $storage = $this->getStorageRequirement();
        return $storage ? ['reports_storage' => $storage] : [];
      }
    }
    return [];
  }

  /**
   * Gets the requirement for storing generated reports.
   *
   * @return array|null
   *   An error if they can't be stored, a warning if they're in the public or
   *   temporary directory, or NULL if there's no problem.
   */
  protected function getStorageRequirement(): ?array {
    $description = match (TRUE) {
      (bool) ($reason = $this->reportStorage->getUnavailableReason()) => $reason,
      $this->reportStorage->isPublic() => $this->t('Generated reports are stored in the public file system, where anyone with a file\'s URL can download it. Reports can contain private information, such as unpublished content and site details. Set $settings[\'reports_directory\'] in settings.php to a private directory.'),
      $this->reportStorage->isTemporary() => $this->t('Generated reports are stored in the temporary directory, which can be cleared at any time and is separate on each web server. Set $settings[\'reports_directory\'] in settings.php to a private directory.'),
      default => NULL,
    };
    if (!$description) {
      return NULL;
    }
    return [
      'title' => $this->t('Generated report storage'),
      'value' => $this->reportStorage->getDirectory(),
      'description' => $description,
      'severity' => $reason ? RequirementSeverity::Error : RequirementSeverity::Warning,
    ];
  }

  /**
   * Checks whether a report can be viewed and generated.
   *
   * @return bool
   *   FALSE if any of its requirements is an error.
   */
  public function isUsable(ReportInterface $report): bool {
    foreach ($this->getRequirements($report) as $requirement) {
      if ($requirement['severity'] === RequirementSeverity::Error) {
        return FALSE;
      }
    }
    return TRUE;
  }

}
