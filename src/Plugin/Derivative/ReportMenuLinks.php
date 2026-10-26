<?php

declare(strict_types=1);

namespace Drupal\reports\Plugin\Derivative;

use Drupal\Component\Plugin\Derivative\DeriverBase;
use Drupal\Core\Plugin\Discovery\ContainerDeriverInterface;
use Drupal\reports\ReportManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Adds a menu link under Reports for each report.
 *
 * Core's Reports page (/admin/reports) lists them, hiding the ones the user
 * can't access. Reports that don't apply to the site (isApplicable()), or
 * whose page is taken by another route (ReportManager::getConflictingRoute()),
 * have no link.
 */
class ReportMenuLinks extends DeriverBase implements ContainerDeriverInterface {

  public function __construct(
    protected readonly ReportManager $reportManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, $base_plugin_id): static {
    return new static($container->get(ReportManager::class));
  }

  /**
   * {@inheritdoc}
   */
  public function getDerivativeDefinitions($base_plugin_definition): array {
    $this->derivatives = [];
    foreach ($this->reportManager->getDefinitions() as $id => $definition) {
      // Reports that replace routes use those routes' menu links.
      if (!$this->reportManager->isApplicable($id) || $this->reportManager->getConflictingRoute($id) || $this->reportManager->getReplacedRoutes($id)) {
        continue;
      }
      $this->derivatives[$id] = [
        'title' => $definition['label'],
        'description' => $definition['description'] ?? '',
        'route_name' => 'reports.report',
        'route_parameters' => ['report' => $id],
        'parent' => 'system.admin_reports',
      ] + $base_plugin_definition;
    }
    return $this->derivatives;
  }

}
