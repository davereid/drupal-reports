<?php

declare(strict_types=1);

namespace Drupal\reports\Plugin\Report;

use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Field\FieldTypePluginManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\custom_field\Plugin\Field\FieldType\CustomItem;
use Drupal\helper\ArrayHelper;
use Drupal\reports\ReportWriter;
use Drupal\text\Plugin\Field\FieldType\TextItemBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base class for reports that check formatted text in content.
 *
 * Checks formatted text fields, including field types that extend them, and
 * custom field (custom_field module) formatted Text (long) subfields, in every
 * content entity type, one batch operation per field. Modules can check other
 * field types and subfield types with
 * hook_reports_formatted_text_columns_alter() and
 * hook_reports_formatted_text_custom_columns_alter(). Subclasses list what
 * they find with getHtmlRows(), and can narrow the entity query with
 * alterFieldQuery(). LinkReportBase also checks URLs.
 *
 * Adds the 'text_source' option: check formatted text as displayed
 * ('filtered', after text format filters) or as entered ('raw').
 *
 * @internal
 *   Experimental until more reports use it: it may still change.
 */
abstract class FormattedTextReportBase extends EntityReportBase implements GeneratedReportInterface {

  /**
   * The entity field manager.
   */
  protected EntityFieldManagerInterface $entityFieldManager;

  /**
   * The field type plugin manager.
   */
  protected FieldTypePluginManagerInterface $fieldTypeManager;

  /**
   * The custom_field subfield type plugin manager, if it's installed.
   */
  protected ?PluginManagerInterface $customFieldTypeManager;

  /**
   * The renderer.
   */
  protected RendererInterface $renderer;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->entityFieldManager = $container->get('entity_field.manager');
    $instance->fieldTypeManager = $container->get('plugin.manager.field.field_type');
    $instance->customFieldTypeManager = $container->get('plugin.manager.custom_field_type', ContainerInterface::NULL_ON_INVALID_REFERENCE);
    $instance->renderer = $container->get('renderer');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return ['text_source' => 'filtered'] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function setConfiguration(array $configuration): void {
    parent::setConfiguration($configuration);
    if (!in_array($this->configuration['text_source'], ['filtered', 'raw'], TRUE)) {
      $this->configuration['text_source'] = 'filtered';
    }
  }

  /**
   * {@inheritdoc}
   *
   * The entity and text source options. Subclasses add their own options to
   * the form before calling this.
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildConfigurationForm($form, $form_state);
    $form['text_source'] = [
      '#type' => 'radios',
      '#title' => $this->t('Check text fields'),
      '#options' => [
        'filtered' => $this->t('As displayed, after text format filters (slower; includes what filters add or change)'),
        'raw' => $this->t('As entered by authors (faster)'),
      ],
      '#default_value' => $this->configuration['text_source'],
    ];
    return $form;
  }

  /**
   * Gets the columns of what the report lists, after the entity and field.
   *
   * @return array
   *   Columns, as for getHeader().
   */
  abstract protected function getValueHeader(): array;

  /**
   * Lists what the report finds in formatted text.
   *
   * @param string $html
   *   The text: as displayed or as entered, depending on 'text_source'.
   *
   * @return iterable<array<string, mixed>>
   *   Rows for the getValueHeader() columns. None by default.
   */
  protected function getHtmlRows(string $html): iterable {
    return [];
  }

  /**
   * Narrows the query for entities to check in a field.
   *
   * By default, entities with a value in the field are checked.
   *
   * @param \Drupal\Core\Entity\Query\QueryInterface $query
   *   The query, from getEntityQuery().
   * @param string $field_name
   *   The field name.
   * @param array $field
   *   The field, as from getFieldColumns().
   * @param bool $filtered
   *   TRUE if formatted text is checked after text format filters, which can
   *   add content that isn't in the stored value.
   */
  protected function alterFieldQuery(QueryInterface $query, string $field_name, array $field, bool $filtered): void {
    $query->exists($field_name);
  }

  /**
   * {@inheritdoc}
   */
  public function getHeader(): array {
    $header = parent::getHeader();
    ArrayHelper::insertAfterKey($header, 'id', ['field' => ['label' => $this->t('Field'), 'filter' => 'list']] + $this->getValueHeader());
    return $header;
  }

  /**
   * {@inheritdoc}
   *
   * The list tags of the bundles with fields to check, and of field and text
   * format configuration.
   */
  public function getCacheTags(): array {
    $tags = ['entity_field_info', 'config:field_config_list', 'config:field_storage_config_list'];
    if ($this->configuration['text_source'] === 'filtered') {
      $tags[] = 'config:filter_format_list';
    }
    foreach ($this->getFieldColumns() as $entity_type_id => $fields) {
      $tags = array_merge($tags, $this->getEntityCacheTags($entity_type_id, array_unique(array_merge(...array_column($fields, 'bundles')))));
    }
    return array_values(array_unique($tags));
  }

  /**
   * {@inheritdoc}
   *
   * Warns when there are no fields to check.
   */
  public function getRequirements(): array {
    if ($this->getFieldColumns()) {
      return [];
    }
    return [
      'fields' => [
        'title' => $this->t('Fields to check'),
        'description' => $this->t('There are no field types that can be scanned, so this report would always be empty.'),
        'severity' => RequirementSeverity::Warning,
      ],
    ];
  }

  /**
   * {@inheritdoc}
   *
   * The report's description, and a table of the fields checked.
   */
  public function getSummary(): array {
    $build = parent::getSummary();
    $rows = [];
    foreach ($this->getFieldColumns() as $entity_type_id => $fields) {
      $entity_type_label = (string) $this->entityTypeManager->getDefinition($entity_type_id)->getLabel();
      foreach ($fields as $field_name => $field) {
        $bundles = array_map(fn ($bundle) => $this->entityTypeHelper->getBundleLabel($entity_type_id, (string) $bundle), $field['bundles']);
        sort($bundles);
        $label = $field['label'] === $field_name ? $field_name : "{$field['label']} ($field_name)";
        if ($field['type'] !== 'custom') {
          $rows[] = [$entity_type_label, $label, $this->getFieldTypeLabel($field['type']), implode(', ', $bundles)];
          continue;
        }
        // Custom fields: one row per subfield checked.
        foreach ($field['columns'] as $column => $info) {
          $subfield = $field['label'] === $field_name ? "$field_name.$column" : "{$field['label']}: $column ($field_name.$column)";
          $rows[] = [
            $entity_type_label,
            $subfield,
            $this->getFieldTypeLabel($field['type'], $info['subfield_type']),
            implode(', ', $bundles),
          ];
        }
      }
    }
    usort($rows, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
    $build['details'] = [
      '#type' => 'details',
      '#title' => $this->t('Fields checked (@count)', ['@count' => count($rows)]),
    ];
    $build['details']['fields'] = [
      '#type' => 'table',
      '#header' => [$this->t('Entity type'), $this->t('Field'), $this->t('Field type'), $this->t('Bundles')],
      '#rows' => $rows,
      '#empty' => $this->t('There are no fields to check.'),
    ];
    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public function getBatchOperations(): array {
    $operations = [];
    foreach ($this->getFieldColumns() as $entity_type_id => $fields) {
      foreach (array_keys($fields) as $field_name) {
        $operations[] = ['processField', [$entity_type_id, $field_name]];
      }
    }
    return $operations;
  }

  /**
   * Batch operation: checks a field.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   * @param string $field_name
   *   The field name.
   * @param \Drupal\reports\ReportWriter $writer
   *   The report writer.
   * @param array|\ArrayAccess $context
   *   The batch context.
   */
  public function processField(string $entity_type_id, string $field_name, ReportWriter $writer, &$context): void {
    $field = $this->getFieldColumns()[$entity_type_id][$field_name] ?? NULL;
    if (!$field) {
      return;
    }
    $filtered = in_array(TRUE, array_column($field['columns'], 'text'), TRUE) && $this->configuration['text_source'] === 'filtered';
    // Filtering text is slow, so check fewer entities per pass.
    $limit = $filtered ? max(1, intdiv($this->getBatchSize(), 4)) : $this->getBatchSize();
    $build_query = function () use ($entity_type_id, $field_name, $field, $filtered): QueryInterface {
      $query = $this->getEntityQuery($entity_type_id, $field['bundles']);
      $this->alterFieldQuery($query, $field_name, $field, $filtered);
      return $query;
    };

    foreach ($this->loadEntityBatch($entity_type_id, $build_query, $context, $limit) as $entity) {
      $rows = iterator_to_array($this->getFieldRows($entity, $field_name, $field, $filtered), FALSE);
      // The entity's own columns are only built when it has rows.
      $entity_row = $rows ? $this->getEntityRow($entity) : [];
      foreach ($rows as $row) {
        $writer->addRow(['field' => $field['label']] + $row + $entity_row);
      }
    }
    // With the machine name, since several fields can share a label.
    // Nothing to report on fields without entities to scan.
    if (!$context['sandbox']['total']) {
      return;
    }
    $context['message'] = $this->t('Scanning @entity_types with @field (@name) field values (@count of @total).', [
      '@entity_types' => $this->entityTypeManager->getDefinition($entity_type_id)->getPluralLabel(),
      '@field' => $field['label'],
      '@name' => $field_name,
      '@count' => $context['sandbox']['done'],
      '@total' => $context['sandbox']['total'],
    ]);
  }

  /**
   * Lists what the report finds in an entity's field.
   *
   * @return iterable<array<string, mixed>>
   *   Rows from getColumnRows().
   */
  protected function getFieldRows(FieldableEntityInterface $entity, string $field_name, array $field, bool $filtered): iterable {
    foreach ($entity->get($field_name) as $item) {
      foreach ($field['columns'] as $column => $info) {
        $value = (string) $item->{$column};
        if ($value !== '') {
          yield from $this->getColumnRows($entity, $field_name, $item, $column, $info, $value, $filtered);
        }
      }
    }
  }

  /**
   * Lists what the report finds in a field item's column.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity.
   * @param string $field_name
   *   The field name.
   * @param \Drupal\Core\Field\FieldItemInterface $item
   *   The field item.
   * @param string $column
   *   The column.
   * @param array{text: bool, title: ?string} $info
   *   The column, as in getFieldColumns().
   * @param string $value
   *   The column's value, not empty.
   * @param bool $filtered
   *   TRUE to check formatted text after text format filters.
   *
   * @return iterable<array<string, mixed>>
   *   Rows from getHtmlRows() for formatted text columns.
   */
  protected function getColumnRows(FieldableEntityInterface $entity, string $field_name, FieldItemInterface $item, string $column, array $info, string $value, bool $filtered): iterable {
    $format = $info['text'] ? $this->getTextFormat($entity, $field_name, $item, $column) : FALSE;
    if ($format !== FALSE) {
      yield from $this->getHtmlRows($filtered ? $this->filterText($value, $format, $entity->language()->getId()) : $value);
    }
  }

  /**
   * Gets the fields to check, and their columns.
   *
   * @return array<string, array<string, array{type: string, label: string, bundles: string[], columns: array<string, array{text: bool, title: ?string, subfield_type?: string}>}>>
   *   Fields keyed by entity type ID and field name, with the field type,
   *   label, bundles, and the columns to check. Each column says whether it's
   *   formatted text or a URL, and the column with a URL's link text, if any.
   *
   * @todo Use a Helper method to merge field maps of several field types.
   */
  protected function getFieldColumns(): array {
    $fields = [];
    $storage_definitions = [];
    foreach ($this->entityFieldManager->getFieldMap() as $entity_type_id => $entity_fields) {
      if (!$this->entityTypeManager->getDefinition($entity_type_id)->entityClassImplements(ContentEntityInterface::class)) {
        continue;
      }
      foreach ($entity_fields as $field_name => $info) {
        $columns = $this->getDefaultFieldTypeColumns($info['type']);
        // custom_field's field type.
        if ($info['type'] === 'custom') {
          $storage_definition = ($storage_definitions[$entity_type_id] ??= $this->entityFieldManager->getFieldStorageDefinitions($entity_type_id))[$field_name] ?? NULL;
          $columns = $storage_definition ? $this->getCustomFieldColumns($storage_definition) : [];
        }
        if ($columns) {
          $fields[$entity_type_id][$field_name] = [
            'type' => $info['type'],
            'label' => $this->getFieldLabel($entity_type_id, $field_name, $info['bundles']),
            'bundles' => array_values($info['bundles']),
            'columns' => $columns,
          ];
        }
      }
    }
    return $fields;
  }

  /**
   * Gets the columns to check of a field type.
   *
   * Field types are matched by their item class, so field types that extend
   * core's formatted text field types are checked too. Modules can change the
   * columns with hook_reports_formatted_text_columns_alter().
   *
   * @param string $type
   *   The field type ID.
   *
   * @return array<string, array{text: bool, title: ?string}>
   *   The columns, as in getFieldColumns(): 'value' for formatted text fields
   *   (TextItemBase), or an empty array.
   */
  protected function getDefaultFieldTypeColumns(string $type): array {
    $class = $this->fieldTypeManager->getDefinition($type, FALSE)['class'] ?? NULL;
    $columns = $class && is_a($class, TextItemBase::class, TRUE) ? ['value' => ['text' => TRUE, 'title' => NULL]] : [];
    $this->moduleHandler->alter('reports_formatted_text_columns', $columns, $type);
    return $columns;
  }

  /**
   * Gets the label of a field type, or a custom field subfield type.
   *
   * @param string $type
   *   The field type ID.
   * @param string|null $subfield_type
   *   (optional) The custom_field subfield type ID.
   *
   * @return string
   *   The label, e.g. "Custom Field: Text (long)", or the ID when the plugin
   *   has no label.
   */
  protected function getFieldTypeLabel(string $type, ?string $subfield_type = NULL): string {
    $label = (string) ($this->fieldTypeManager->getDefinition($type, FALSE)['label'] ?? $type);
    if ($subfield_type === NULL) {
      return $label;
    }
    $subfield_label = (string) ($this->customFieldTypeManager?->getDefinition($subfield_type, FALSE)['label'] ?? $subfield_type);
    return "$label: $subfield_label";
  }

  /**
   * Gets a field's label.
   *
   * Configurable field storages have no label (getLabel() returns their ID),
   * so this uses the field's label on its first bundle.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   * @param string $field_name
   *   The field name.
   * @param string[] $bundles
   *   The bundles that have the field.
   *
   * @return string
   *   The label, or the field name if there is none.
   */
  protected function getFieldLabel(string $entity_type_id, string $field_name, array $bundles): string {
    $bundle = (string) reset($bundles);
    $definition = $this->entityFieldManager->getFieldDefinitions($entity_type_id, $bundle)[$field_name] ?? NULL;
    return (string) $definition?->getLabel() ?: $field_name;
  }

  /**
   * Gets the subfields of a custom field to check.
   *
   * Text (long) subfields, which are only checked on bundles where they're
   * formatted (see getTextFormat()). Modules can change the subfields with
   * hook_reports_formatted_text_custom_columns_alter().
   *
   * @param \Drupal\Core\Field\FieldStorageDefinitionInterface $storage_definition
   *   The custom field's storage definition.
   *
   * @return array<string, array{text: bool, title: ?string, subfield_type: string}>
   *   The subfield columns, as in getFieldColumns().
   *
   * @todo Use a Helper method to find custom field subfields by type.
   */
  protected function getCustomFieldColumns(FieldStorageDefinitionInterface $storage_definition): array {
    $columns = [];
    foreach ($storage_definition->getSetting('columns') ?? [] as $name => $column) {
      if ($column['type'] === 'string_long') {
        $columns[$name] = ['text' => TRUE, 'title' => NULL, 'subfield_type' => 'string_long'];
      }
    }
    $this->moduleHandler->alter('reports_formatted_text_custom_columns', $columns, $storage_definition);
    return $columns;
  }

  /**
   * Gets the text format of a formatted text column.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity.
   * @param string $field_name
   *   The field name.
   * @param \Drupal\Core\Field\FieldItemInterface $item
   *   The field item.
   * @param string $column
   *   The column.
   *
   * @return string|false|null
   *   The item's format: for text fields their own; for custom field Text
   *   (long) subfields the subfield's default format, NULL for its fallback
   *   format, or FALSE if the subfield isn't formatted on the entity's
   *   bundle, so it's plain text.
   */
  protected function getTextFormat(FieldableEntityInterface $entity, string $field_name, FieldItemInterface $item, string $column): string|false|null {
    if ($column === 'value' && $item instanceof TextItemBase) {
      return $item->format;
    }
    if ($item instanceof CustomItem) {
      $settings = $item->getFieldDefinition()->getSetting('field_settings')[$column] ?? [];
      if (!empty($settings['formatted'])) {
        return ($settings['default_format'] ?? '') ?: NULL;
      }
    }
    return FALSE;
  }

  /**
   * Runs text through its text format's filters.
   *
   * Renders a 'processed_text' element, which replaces check_markup()
   * (deprecated in Drupal 11.4).
   *
   * @param string $text
   *   The text.
   * @param string|null $format
   *   The text format, or NULL for the fallback format.
   * @param string $langcode
   *   The text's language.
   *
   * @return string
   *   The filtered text.
   *
   * @todo Use a Helper method, e.g. TextFormat::processText(), once Helper
   *   has one.
   */
  protected function filterText(string $text, ?string $format, string $langcode): string {
    $element = ['#type' => 'processed_text', '#text' => $text, '#langcode' => $langcode];
    if ($format !== NULL) {
      $element['#format'] = $format;
    }
    return (string) $this->renderer->renderInIsolation($element);
  }

}
