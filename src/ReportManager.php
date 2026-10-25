<?php

declare(strict_types=1);

namespace Drupal\reports;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\reports\Attribute\Report;
use Drupal\reports\Plugin\Report\ReportInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Manages report plugins.
 *
 * @method \Drupal\reports\Plugin\Report\ReportInterface createInstance($plugin_id, array $configuration = [])
 */
class ReportManager extends DefaultPluginManager {

  public function __construct(
    #[Autowire(service: 'container.namespaces')]
    \Traversable $namespaces,
    #[Autowire(service: 'cache.discovery')]
    CacheBackendInterface $cache_backend,
    ModuleHandlerInterface $module_handler,
  ) {
    parent::__construct('Plugin/Report', $namespaces, $module_handler, ReportInterface::class, Report::class);
    $this->alterInfo('report_info');
    $this->setCacheBackend($cache_backend, 'report_plugins');
  }

}
