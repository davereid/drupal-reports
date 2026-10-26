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
 * Alters the formatted text columns of a field type that reports check.
 *
 * Reports built on FormattedTextReportBase, such as HTML elements and
 * Absolute URLs, check formatted text fields, including field types that
 * extend them. Use this hook to skip a field type. Formatted text columns
 * are only checked when FormattedTextReportBase::getTextFormat() finds their
 * text format, i.e. the 'value' column of text field items.
 *
 * @param array<string, array{text: bool, title: ?string}> $columns
 *   The columns to check, keyed by column name: empty if the field type isn't
 *   checked. Each column has:
 *   - text: TRUE: formatted text (HTML), with the item's 'format' property as
 *     its text format.
 *   - title: NULL.
 * @param string $field_type
 *   The field type ID.
 *
 * @see \Drupal\reports\Plugin\Report\FormattedTextReportBase::getDefaultFieldTypeColumns()
 */
function hook_reports_formatted_text_columns_alter(array &$columns, string $field_type): void {
  // Skip short formatted text fields.
  if ($field_type === 'text') {
    $columns = [];
  }
}

/**
 * Alters the URL columns of a field type that link reports check.
 *
 * Reports built on LinkReportBase, such as Absolute URLs, also check link and
 * URI fields, including field types that extend them. Use this hook to check
 * other field types as URLs, or to skip one.
 *
 * @param array<string, array{text: bool, title: ?string}> $columns
 *   The columns to check, keyed by column name: empty if the field type isn't
 *   checked. Each column has:
 *   - text: FALSE: a URL.
 *   - title: The column with the URL's link text, or NULL.
 * @param string $field_type
 *   The field type ID.
 *
 * @see \Drupal\reports\Plugin\Report\LinkReportBase::getDefaultFieldTypeColumns()
 */
function hook_reports_link_columns_alter(array &$columns, string $field_type): void {
  // The iframe module's field stores a URL in its 'url' column.
  if ($field_type === 'iframe') {
    $columns['url'] = ['text' => FALSE, 'title' => 'title'];
  }
}

/**
 * Alters the custom field formatted text subfields that reports check.
 *
 * Reports built on FormattedTextReportBase check custom field (custom_field
 * module) Text (long) subfields on bundles where they're formatted.
 *
 * @param array<string, array{text: bool, title: ?string, subfield_type: string}> $columns
 *   The subfields to check, keyed by subfield name, as in
 *   hook_reports_formatted_text_columns_alter(), plus:
 *   - subfield_type: The custom_field subfield type ID, for its label.
 *   Formatted text subfields use the subfield's default format, and are
 *   skipped on bundles where the subfield isn't formatted.
 * @param \Drupal\Core\Field\FieldStorageDefinitionInterface $storage_definition
 *   The custom field's storage definition. Its 'columns' setting lists every
 *   subfield, with its 'type'.
 *
 * @see \Drupal\reports\Plugin\Report\FormattedTextReportBase::getCustomFieldColumns()
 */
function hook_reports_formatted_text_custom_columns_alter(array &$columns, FieldStorageDefinitionInterface $storage_definition): void {
  // Skip Text (long) subfields named 'notes'.
  unset($columns['notes']);
}

/**
 * Alters the custom field URL subfields that link reports check.
 *
 * Reports built on LinkReportBase also check custom field link and URI
 * subfields.
 *
 * @param array<string, array{text: bool, title: ?string, subfield_type: string}> $columns
 *   The subfields to check, keyed by subfield name, as in
 *   hook_reports_link_columns_alter(), plus:
 *   - subfield_type: The custom_field subfield type ID, for its label.
 * @param \Drupal\Core\Field\FieldStorageDefinitionInterface $storage_definition
 *   The custom field's storage definition. Its 'columns' setting lists every
 *   subfield, with its 'type'.
 *
 * @see \Drupal\reports\Plugin\Report\LinkReportBase::getCustomFieldColumns()
 */
function hook_reports_link_custom_columns_alter(array &$columns, FieldStorageDefinitionInterface $storage_definition): void {
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
