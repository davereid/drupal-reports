<?php

declare(strict_types=1);

namespace Drupal\reports\Plugin\Report;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Site\Settings;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base class for reports with a single sheet.
 */
abstract class ReportBase extends PluginBase implements ReportInterface, ContainerFactoryPluginInterface {

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
    return new static($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * Gets the columns of the default sheet.
   *
   * @return array<string, string|\Stringable|array{label: string|\Stringable, filter?: string|false}>
   *   Columns keyed by column key, as in ReportInterface::getSheets().
   */
  abstract public function getHeader(): array;

  /**
   * {@inheritdoc}
   */
  public function getSheets(): array {
    return [
      'default' => [
        'label' => $this->pluginDefinition['label'],
        'header' => $this->getHeader(),
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [];
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
  public function isApplicable(): bool {
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
   */
  public function access(AccountInterface $account): AccessResultInterface {
    return AccessResult::allowedIfHasPermission($account, $this->pluginDefinition['permission']);
  }

}
