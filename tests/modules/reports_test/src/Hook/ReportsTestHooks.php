<?php

declare(strict_types=1);

namespace Drupal\reports_test\Hook;

use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\State\StateInterface;

/**
 * Hook implementations for the Reports test module.
 */
class ReportsTestHooks {

  public function __construct(
    protected readonly StateInterface $state,
  ) {}

  /**
   * Implements hook_reports_link_columns_alter().
   *
   * Checks plain text (string_long) fields as URLs, and skips URI fields.
   */
  #[Hook('reports_link_columns_alter')]
  public function reportsLinkColumnsAlter(array &$columns, string $field_type): void {
    if ($field_type === 'string_long') {
      $columns['value'] = ['text' => FALSE, 'title' => NULL];
    }
    elseif ($field_type === 'uri') {
      $columns = [];
    }
  }

  /**
   * Implements hook_reports_link_custom_columns_alter().
   *
   * Checks plain text (string) subfields as URLs.
   */
  #[Hook('reports_link_custom_columns_alter')]
  public function reportsLinkCustomColumnsAlter(array &$columns, FieldStorageDefinitionInterface $storage_definition): void {
    foreach ($storage_definition->getSetting('columns') as $name => $column) {
      if ($column['type'] === 'string') {
        $columns[$name] = ['text' => FALSE, 'title' => NULL, 'subfield_type' => 'string'];
      }
    }
  }

  /**
   * Implements hook_report_info_alter().
   *
   * Sets the definitions' 'configuration' from the
   * reports_test.configuration state, keyed by report ID.
   */
  #[Hook('report_info_alter')]
  public function reportInfoAlter(array &$definitions): void {
    foreach ($this->state->get('reports_test.configuration', []) as $id => $configuration) {
      if (isset($definitions[$id])) {
        $definitions[$id]['configuration'] = $configuration;
      }
    }
  }

}
