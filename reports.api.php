<?php

/**
 * @file
 * Hooks provided by the Reports module.
 */

declare(strict_types=1);

use Drupal\Core\Field\FieldStorageDefinitionInterface;

/**
 * @addtogroup hooks
 * @{
 */

/**
 * Alters the columns of a field type that text field reports check.
 *
 * Reports built on TextFieldReportBase, such as Absolute URLs, check link,
 * URI and formatted text fields, including field types that extend them. Use
 * this hook to check other field types, or to skip one.
 *
 * @param array<string, array{text: bool, title: ?string}> $columns
 *   The columns to check, keyed by column name: empty if the field type isn't
 *   checked. Each column has:
 *   - text: TRUE for formatted text (HTML), with the item's 'format'
 *     property as its text format, or FALSE for a URL.
 *   - title: The column with a URL's link text, or NULL.
 * @param string $field_type
 *   The field type ID.
 *
 * @see \Drupal\reports\Plugin\Report\TextFieldReportBase::getFieldTypeColumns()
 */
function hook_reports_text_field_columns_alter(array &$columns, string $field_type): void {
  // The iframe module's field stores a URL in its 'url' column.
  if ($field_type === 'iframe') {
    $columns['url'] = ['text' => FALSE, 'title' => 'title'];
  }
}

/**
 * Alters the custom field subfields that text field reports check.
 *
 * Reports built on TextFieldReportBase check custom field (custom_field
 * module) link and URI subfields, and Text (long) subfields on bundles where
 * they're formatted.
 *
 * @param array<string, array{text: bool, title: ?string, subfield_type: string}> $columns
 *   The subfields to check, keyed by subfield name, as in
 *   hook_reports_text_field_columns_alter(), plus:
 *   - subfield_type: The custom_field subfield type ID, for its label.
 *   Formatted text subfields use the subfield's default format, and are
 *   skipped on bundles where the subfield isn't formatted.
 * @param \Drupal\Core\Field\FieldStorageDefinitionInterface $storage_definition
 *   The custom field's storage definition. Its 'columns' setting lists every
 *   subfield, with its 'type'.
 *
 * @see \Drupal\reports\Plugin\Report\TextFieldReportBase::getCustomFieldColumns()
 */
function hook_reports_text_field_custom_columns_alter(array &$columns, FieldStorageDefinitionInterface $storage_definition): void {
  // Check plain text subfields named 'url'.
  foreach ($storage_definition->getSetting('columns') as $name => $column) {
    if ($name === 'url' && $column['type'] === 'string') {
      $columns[$name] = ['text' => FALSE, 'title' => NULL, 'subfield_type' => 'string'];
    }
  }
}

/**
 * @} End of "addtogroup hooks".
 */
