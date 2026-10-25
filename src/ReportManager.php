<?php

declare(strict_types=1);

namespace Drupal\reports;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\Core\Routing\RouteProviderInterface;
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
    protected readonly RouteProviderInterface $routeProvider,
  ) {
    parent::__construct('Plugin/Report', $namespaces, $module_handler, ReportInterface::class, Report::class);
    $this->alterInfo('report_info');
    $this->setCacheBackend($cache_backend, 'report_plugins');
  }

  /**
   * Checks whether a report applies to the site.
   *
   * @param string $id
   *   The report plugin ID.
   *
   * @return bool
   *   TRUE if the report exists and its isApplicable() returns TRUE.
   */
  public function isApplicable(string $id): bool {
    $definition = $this->getDefinition($id, FALSE);
    return $definition && $definition['class']::isApplicable($definition);
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
   *   storage has a problem, and 'reports_route_conflict:ID' for each
   *   applicable report whose page is taken by another route.
   */
  public function getGlobalRequirements(): array {
    $requirements = [];
    $storageChecked = FALSE;
    foreach ($this->getDefinitions() as $id => $definition) {
      if (!$this->isApplicable($id)) {
        continue;
      }
      if (!$storageChecked && $this->createInstance($id) instanceof GeneratedReportInterface) {
        $storageChecked = TRUE;
        if ($storage = $this->getStorageRequirement()) {
          $requirements['reports_storage'] = $storage;
        }
      }
      if ($route = $this->getConflictingRoute($id)) {
        $requirements["reports_route_conflict:$id"] = [
          'title' => $this->t('Report: @label', ['@label' => $definition['label']]),
          'value' => $this->t('Not available'),
          'description' => $this->t("The report can't be shown, because its page (/admin/reports/@id) is used by the route %route. Rename one of them.", [
            '@id' => $id,
            '%route' => $route,
          ]),
          'severity' => RequirementSeverity::Warning,
        ];
      }
    }
    return $requirements;
  }

  /**
   * Finds another route that takes a report's page.
   *
   * The router prefers routes with more fixed path parts, so a route such as
   * core's /admin/reports/status always wins over the report's
   * /admin/reports/{report}, including routes added by modules installed
   * after the report.
   *
   * @param string $id
   *   The report plugin ID.
   *
   * @return string|null
   *   The name of the route that matches the report's page instead of
   *   reports.report, or NULL if there's none.
   */
  public function getConflictingRoute(string $id): ?string {
    // Routes are sorted the way the router matches them, best first.
    $routes = $this->routeProvider->getRoutesByPattern("/admin/reports/$id");
    foreach ($routes->getIterator() as $name => $route) {
      return $name === 'reports.report' ? NULL : $name;
    }
    return NULL;
  }

  /**
   * Gets the requirement for storing generated reports.
   *
   * @return array|null
   *   An error if they can't be stored, a warning if they're in the public or
   *   temporary directory, or NULL if there's no problem.
   */
  protected function getStorageRequirement(): ?array {
    $reason = $this->reportStorage->getUnavailableReason();
    $description = match (TRUE) {
      $reason !== NULL => $reason,
      $this->reportStorage->isPublic() => $this->t('Generated reports are stored in the public file system, where anyone who has the URL of a file can download it. Reports can contain private information, such as unpublished content and site details. Set the reports_directory setting in settings.php to a private directory.'),
      $this->reportStorage->isTemporary() => $this->t('Generated reports are stored in the temporary directory, which can be cleared at any time and is separate on each web server. Set the reports_directory setting in settings.php to a private directory.'),
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
