<?php

declare(strict_types=1);

namespace Drupal\reports\Plugin\Report;

use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldTypePluginManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\helper\ArrayHelper;
use Drupal\reports\ReportWriter;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base class for reports that check URLs and formatted text in content.
 *
 * Checks link fields, formatted text fields, and custom field (custom_field
 * module) link, URI and formatted Text (long) subfields, in every content
 * entity type, one batch operation per field. Subclasses list what they find
 * with getUrlRows() and getHtmlRows(), and can narrow the entity query with
 * alterFieldQuery().
 *
 * Adds the 'text_source' option: check formatted text as displayed
 * ('filtered', after text format filters) or as entered ('raw').
 *
 * @internal
 *   Experimental until more reports use it: it may still change.
 */
abstract class TextFieldReportBase extends EntityReportBase implements GeneratedReportInterface {

  /**
   * The text field types checked as formatted text.
   */
  const TEXT_FIELD_TYPES = ['text', 'text_long', 'text_with_summary'];

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
   * Builds the form elements of the entity and text source options.
   *
   * @param array $form
   *   The report's configuration form, to add the elements to.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   The form.
   */
  protected function buildTextFieldConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form = $this->buildEntityConfigurationForm($form, $form_state);
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
   * Lists what the report finds in a URL value.
   *
   * @param string $url
   *   The URL, from a link field or a link or URI subfield.
   * @param string $title
   *   The link text, if the field has one.
   *
   * @return iterable<array<string, mixed>>
   *   Rows for the getValueHeader() columns. None by default.
   */
  protected function getUrlRows(string $url, string $title): iterable {
    return [];
  }

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
    $header = $this->getEntityHeader();
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
        'description' => $this->t('There are no link, formatted text or custom fields with link, URI or formatted text subfields, so this report would always be empty.'),
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
    $context['message'] = $this->t('Checking @field on @entity_type.', [
      '@field' => $field['label'],
      '@entity_type' => $this->entityTypeManager->getDefinition($entity_type_id)->getLabel(),
    ]);
  }

  /**
   * Lists what the report finds in an entity's field.
   *
   * @return iterable<array<string, mixed>>
   *   Rows from getUrlRows() and getHtmlRows().
   */
  protected function getFieldRows(FieldableEntityInterface $entity, string $field_name, array $field, bool $filtered): iterable {
    foreach ($entity->get($field_name) as $item) {
      foreach ($field['columns'] as $column => $info) {
        $value = (string) $item->{$column};
        if ($value === '') {
          continue;
        }
        if (!$info['text']) {
          yield from $this->getUrlRows($value, $info['title'] ? (string) $item->{$info['title']} : '');
          continue;
        }
        $format = $this->getTextFormat($entity, $field_name, $item, $column);
        if ($format !== FALSE) {
          yield from $this->getHtmlRows($filtered ? $this->filterText($value, $format, $entity->language()->getId()) : $value);
        }
      }
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
        $columns = match (TRUE) {
          $info['type'] === 'link' => ['uri' => ['text' => FALSE, 'title' => 'title']],
          in_array($info['type'], static::TEXT_FIELD_TYPES, TRUE) => ['value' => ['text' => TRUE, 'title' => NULL]],
          // custom_field's field type.
          $info['type'] === 'custom' => $this->getCustomFieldColumns($storage_definitions[$entity_type_id] ??= $this->entityFieldManager->getFieldStorageDefinitions($entity_type_id), $field_name),
          default => [],
        };
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
   * Link and URI subfields, and Text (long) subfields, which are only checked
   * on bundles where they're formatted (see getTextFormat()).
   *
   * @param \Drupal\Core\Field\FieldStorageDefinitionInterface[] $storage_definitions
   *   The entity type's field storage definitions.
   * @param string $field_name
   *   The custom field's name.
   *
   * @return array<string, array{text: bool, title: ?string, subfield_type: string}>
   *   The subfield columns, as in getFieldColumns().
   *
   * @todo Use a Helper method to find custom field subfields by type.
   */
  protected function getCustomFieldColumns(array $storage_definitions, string $field_name): array {
    $columns = [];
    foreach ($storage_definitions[$field_name]?->getSetting('columns') ?? [] as $name => $column) {
      $columns[$name] = match ($column['type']) {
        'link' => ['text' => FALSE, 'title' => $name . '__title', 'subfield_type' => 'link'],
        'uri' => ['text' => FALSE, 'title' => NULL, 'subfield_type' => 'uri'],
        'string_long' => ['text' => TRUE, 'title' => NULL, 'subfield_type' => 'string_long'],
        default => NULL,
      };
    }
    return array_filter($columns);
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
    if ($column === 'value' && in_array($item->getFieldDefinition()->getType(), static::TEXT_FIELD_TYPES, TRUE)) {
      return $item->get('format')->getValue();
    }
    $settings = $entity->getFieldDefinition($field_name)->getSetting('field_settings')[$column] ?? [];
    return empty($settings['formatted']) ? FALSE : (($settings['default_format'] ?? '') ?: NULL);
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

  /**
   * Gets the links in HTML.
   *
   * @return iterable<array{0: string, 1: string}>
   *   The text and href of each <a> element.
   *
   * @todo Use a Helper method to get the links in HTML.
   */
  protected function getHtmlLinks(string $html): iterable {
    if (!str_contains($html, '<a')) {
      return;
    }
    foreach (Html::load($html)->getElementsByTagName('a') as $link) {
      if ($link->hasAttribute('href')) {
        yield [$link->textContent, $link->getAttribute('href')];
      }
    }
  }

}
