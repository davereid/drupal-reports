<?php

declare(strict_types=1);

namespace Drupal\reports\Plugin\Report;

use Drupal\Core\Access\AccessManagerInterface;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\Url;
use Drupal\reports\ReportManager;
use Drupal\user\Access\PermissionAccessCheck;
use Drupal\user\Access\RoleAccessCheck;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Routing\Route;

/**
 * Base class for reports.
 *
 * Reports have one sheet by default: override getSheets() for more, and
 * build each sheet's columns in getHeader($sheet).
 */
abstract class ReportBase extends PluginBase implements ReportInterface, ContainerFactoryPluginInterface {

  /**
   * The report plugin manager.
   */
  protected ReportManager $reportManager;

  /**
   * The access manager.
   */
  protected AccessManagerInterface $accessManager;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->setConfiguration($configuration);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->reportManager = $container->get(ReportManager::class);
    $instance->accessManager = $container->get('access_manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  abstract public function getHeader(string $sheet = self::SHEET_DEFAULT): array;

  /**
   * {@inheritdoc}
   *
   * One sheet, labeled with the report's label.
   */
  public function getSheets(): array {
    return [self::SHEET_DEFAULT => $this->pluginDefinition['label']];
  }

  /**
   * {@inheritdoc}
   *
   * The definition's 'configuration', e.g. set by
   * hook_report_info_alter(). Subclasses add their own defaults after it,
   * with parent::defaultConfiguration() + [...], so the definition's values
   * replace them.
   */
  public function defaultConfiguration(): array {
    return $this->pluginDefinition['configuration'] ?? [];
  }

  /**
   * Normalizes a list option to a comma-separated string.
   *
   * List options are stored as strings, because the query string that
   * carries report options drops empty arrays: an emptied list would fall
   * back to its default.
   *
   * @param mixed $value
   *   A list, checkbox values (unchecked ones are 0), or a string separated
   *   by commas or whitespace, as in the query string and Drush options.
   * @param string[]|null $allowed
   *   (optional) The values allowed. Defaults to any.
   *
   * @return string
   *   The values, without duplicates, sorted so equivalent options get the
   *   same storage key, and separated by commas.
   */
  protected static function normalizeList(mixed $value, ?array $allowed = NULL): string {
    $values = is_array($value) ? $value : preg_split('/[\s,]+/', (string) $value);
    $values = array_unique(array_filter(array_map(fn ($item) => trim((string) $item), $values), fn ($item) => $item !== '' && $item !== '0'));
    if ($allowed !== NULL) {
      $values = array_intersect($values, $allowed);
    }
    sort($values);
    return implode(',', $values);
  }

  /**
   * Gets a list option's values.
   *
   * @param string $key
   *   The option, stored by normalizeList().
   *
   * @return string[]
   *   The values, or an empty array.
   */
  protected function getListOption(string $key): array {
    $value = (string) ($this->configuration[$key] ?? '');
    return $value === '' ? [] : explode(',', $value);
  }

  /**
   * {@inheritdoc}
   */
  public function getConfiguration(): array {
    return $this->configuration;
  }

  /**
   * {@inheritdoc}
   *
   * Keys that aren't in the default configuration are ignored.
   */
  public function setConfiguration(array $configuration): void {
    $defaults = $this->defaultConfiguration();
    $this->configuration = array_intersect_key($configuration, $defaults) + $defaults;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags(): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts(): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge(): int {
    return Cache::PERMANENT;
  }

  /**
   * {@inheritdoc}
   *
   * The report's description, if it has one.
   */
  public function getSummary(): array {
    if (empty($this->pluginDefinition['description'])) {
      return [];
    }
    return [
      'description' => [
        '#markup' => $this->pluginDefinition['description'],
        '#prefix' => '<p>',
        '#suffix' => '</p>',
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function isApplicable(array $plugin_definition): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function getRequirements(): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function toUrl(string $rel = 'canonical', array $options = []): Url {
    $routes = [
      'canonical' => 'reports.report',
      'csv' => 'reports.report.csv',
      'xlsx' => 'reports.report.xlsx',
      'generate' => 'reports.report.generate',
    ];
    if (!isset($routes[$rel])) {
      throw new \InvalidArgumentException("Reports have no '$rel' URL.");
    }
    if ($this->configuration) {
      $options['query'] = ($options['query'] ?? []) + ['config' => $this->configuration];
    }
    return Url::fromRoute($routes[$rel], ['report' => $this->getPluginId()], $options);
  }

  /**
   * {@inheritdoc}
   */
  public function getOperations(): array {
    return [];
  }

  /**
   * Gets the number of items generated reports process per batch pass.
   *
   * @return int
   *   $settings['reports_batch_size'], or core's
   *   $settings['entity_update_batch_size'], or 50. At least 1.
   */
  protected function getBatchSize(): int {
    return max(1, (int) Settings::get('reports_batch_size', Settings::get('entity_update_batch_size', 50)));
  }

  /**
   * {@inheritdoc}
   *
   * Reports that replace routes (see \Drupal\reports\Attribute\Report::$routes)
   * use the access requirements of the route that shows their default sheet,
   * or their first route. Others need the attribute's permissions.
   */
  public function access(AccountInterface $account): AccessResultInterface {
    if ($routes = $this->reportManager->getReplacedRoutes($this->getPluginId())) {
      $route_name = array_search(self::SHEET_DEFAULT, $routes, TRUE) ?: array_key_first($routes);
      $result = $this->accessManager->checkNamedRoute($route_name, [], $account, TRUE);
      assert($result instanceof AccessResultInterface);
      return $result;
    }
    // The same checks as routes' _permission and _role requirements.
    $route = new Route('', requirements: array_filter([
      '_permission' => $this->pluginDefinition['permission'] ?? NULL,
      '_role' => $this->pluginDefinition['roles'] ?? NULL,
    ]));
    $result = (new PermissionAccessCheck())->access($route, $account);
    if ($route->hasRequirement('_role')) {
      $result = $result->orIf((new RoleAccessCheck())->access($route, $account));
    }
    return $result;
  }

}
