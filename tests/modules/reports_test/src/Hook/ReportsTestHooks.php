<?php

declare(strict_types=1);

namespace Drupal\reports_test\Hook;

use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Hook implementations for the Reports test module.
 */
class ReportsTestHooks {

  /**
   * Implements hook_reports_text_field_columns_alter().
   *
   * Checks plain text (string_long) fields as URLs, and skips URI fields.
   */
  #[Hook('reports_text_field_columns_alter')]
  public function reportsTextFieldColumnsAlter(array &$columns, string $field_type): void {
    if ($field_type === 'string_long') {
      $columns['value'] = ['text' => FALSE, 'title' => NULL];
    }
    elseif ($field_type === 'uri') {
      $columns = [];
    }
  }

  /**
   * Implements hook_reports_text_field_custom_columns_alter().
   *
   * Checks plain text (string) subfields as URLs.
   */
  #[Hook('reports_text_field_custom_columns_alter')]
  public function reportsTextFieldCustomColumnsAlter(array &$columns, FieldStorageDefinitionInterface $storage_definition): void {
    foreach ($storage_definition->getSetting('columns') as $name => $column) {
      if ($column['type'] === 'string') {
        $columns[$name] = ['text' => FALSE, 'title' => NULL, 'subfield_type' => 'string'];
      }
    }
  }

}
