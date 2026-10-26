<?php

declare(strict_types=1);

namespace Drupal\reports\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Drupal\reports\ReportManager;
use Symfony\Component\Routing\RouteCollection;

/**
 * Makes the routes that reports replace show the report.
 *
 * @see \Drupal\reports\Attribute\Report::$routes
 */
class ReportRouteSubscriber extends RouteSubscriberBase {

  public function __construct(
    protected readonly ReportManager $reportManager,
  ) {}

  /**
   * {@inheritdoc}
   *
   * Each replaced route gets the report page's controller, title and
   * parameter conversion, with the report and sheet as defaults. It keeps its
   * own access requirements, which the report's access() also uses.
   */
  protected function alterRoutes(RouteCollection $collection): void {
    $report_route = $collection->get('reports.report');
    if (!$report_route) {
      return;
    }
    foreach ($this->reportManager->getDefinitions() as $id => $definition) {
      foreach ($definition['routes'] ?? [] as $name => $sheet) {
        if ($route = $collection->get($name)) {
          $route->setDefaults(['report' => $id, 'sheet' => $sheet] + $report_route->getDefaults());
          $route->addOptions(['parameters' => $report_route->getOption('parameters')]);
        }
      }
    }
  }

}
