<?php

declare(strict_types=1);

namespace Drupal\reports\Plugin\Report;

use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldTypePluginManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\helper\ArrayHelper;
use Drupal\reports\Attribute\Report;
use Drupal\reports\ReportWriter;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lists links that use absolute URLs to the site's own domains.
 *
 * These links break when the site's domain or the linked page's path
 * changes.
 */
#[Report(
  id: 'absolute_urls',
  label: new TranslatableMarkup('Absolute URLs'),
  description: new TranslatableMarkup('Links in content that use absolute URLs to this site, which break when the domain or page path changes.'),
)]
class AbsoluteUrls extends EntityReportBase implements GeneratedReportInterface, PluginFormInterface {

  /**
   * The text field types checked for <a> elements.
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
   * The current site's host name.
   */
  protected string $currentHost;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, [], $plugin_id, $plugin_definition);
    $instance->entityFieldManager = $container->get('entity_field.manager');
    $instance->fieldTypeManager = $container->get('plugin.manager.field.field_type');
    $instance->customFieldTypeManager = $container->has('plugin.manager.custom_field_type') ? $container->get('plugin.manager.custom_field_type') : NULL;
    $instance->currentHost = $container->get('request_stack')->getMainRequest()?->getHost() ?? 'localhost';
    // The default domain depends on the request, so set it after injection.
    $instance->setConfiguration($configuration);
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'domains' => [$this->currentHost ?? 'localhost'],
      'text_source' => 'filtered',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   *
   * Domains can be given as a list, or a string separated by commas or new
   * lines, as in the query string and Drush options.
   */
  public function setConfiguration(array $configuration): void {
    parent::setConfiguration($configuration);
    $domains = $this->configuration['domains'];
    $domains = is_array($domains) ? $domains : preg_split('/[\s,]+/', (string) $domains);
    $domains = array_unique(array_filter(array_map(fn ($domain) => strtolower(trim((string) $domain)), $domains)));
    // Sorted, so the same domains are stored as the same report.
    sort($domains);
    $this->configuration['domains'] = $domains;
    if (!in_array($this->configuration['text_source'], ['filtered', 'raw'], TRUE)) {
      $this->configuration['text_source'] = 'filtered';
    }
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form['domains'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Domains'),
      '#description' => $this->t('The domains of this site, one per line. Links to them are listed.'),
      '#default_value' => implode("\n", $this->configuration['domains']),
      '#required' => TRUE,
      '#rows' => 3,
    ];
    $form = $this->buildEntityConfigurationForm($form, $form_state);
    $form['text_source'] = [
      '#type' => 'radios',
      '#title' => $this->t('Check text fields'),
      '#options' => [
        'filtered' => $this->t('As displayed, after text format filters (slower; finds links added or changed by filters)'),
        'raw' => $this->t('As entered by authors (faster)'),
      ],
      '#default_value' => $this->configuration['text_source'],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state): void {}

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $this->setConfiguration($form_state->getValues());
  }

  /**
   * {@inheritdoc}
   *
   * The list tags of the bundles with link fields, and of field and text
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
   */
  public function getHeader(): array {
    $header = $this->getEntityHeader();
    ArrayHelper::insertAfterKey($header, 'id', [
      'field' => ['label' => $this->t('Field'), 'filter' => 'list'],
      'link_text' => $this->t('Link text'),
      'link_url' => $this->t('Link URL'),
    ]);
    return $header;
  }

  /**
   * {@inheritdoc}
   *
   * Warns when there are no link, text or custom fields to check.
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
   * A table of the fields checked, with their entity type and bundles.
   */
  public function getSummary(): array {
    $build['intro'] = [
      '#markup' => $this->t('Links that contain absolute URLs with the selected domains, in the following fields:'),
      '#prefix' => '<p>',
      '#suffix' => '</p>',
    ];
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
          $rows[] = [$entity_type_label, $subfield, $this->getFieldTypeLabel($field['type'], $info['subfield_type']), implode(', ', $bundles)];
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
   * Batch operation: lists the absolute URLs in a field.
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
    if (!$field || !$this->configuration['domains']) {
      return;
    }
    $filtered = in_array(TRUE, array_column($field['columns'], 'text'), TRUE) && $this->configuration['text_source'] === 'filtered';
    // Filtering text is slow, so check fewer entities per pass.
    $limit = $filtered ? max(1, intdiv($this->getBatchSize(), 4)) : $this->getBatchSize();
    $entities = $this->loadEntityBatch($entity_type_id, fn () => $this->getQuery($entity_type_id, $field_name, $field, $filtered), $context, $limit);

    foreach ($entities as $entity) {
      $entity_row = NULL;
      foreach ($this->getEntityLinks($entity, $field_name, $field, $filtered) as [$text, $url]) {
        $writer->addRow([
          'field' => $field['label'],
          'link_text' => trim($text),
          'link_url' => $url,
        ] + ($entity_row ??= $this->getEntityRow($entity)));
      }
    }
    $context['message'] = $this->t('Checking @field on @entity_type.', ['@field' => $field['label'], '@entity_type' => $entity_type_id]);
  }

  /**
   * Gets the fields that can contain links, and their columns to check.
   *
   * @return array<string, array<string, array{type: string, label: string, bundles: string[], columns: array<string, array{text: bool, title: ?string}>}>>
   *   Fields keyed by entity type ID and field name, with the field type,
   *   label, bundles, and the columns to check. Each column says whether it's
   *   formatted text, checked for <a> elements, or a URL, and the column with
   *   a URL's link text, if any.
   *
   * @todo Use a Helper method to merge field maps of several field types.
   */
  protected function getFieldColumns(): array {
    $fields = [];
    $storage_definitions = [];
    $field_map = $this->entityFieldManager->getFieldMap();
    foreach ($field_map as $entity_type_id => $entity_fields) {
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
   * Gets the subfields of a custom field that can contain links.
   *
   * Link and URI subfields, and Text (long) subfields, which are only checked
   * on bundles where they're formatted (see getTextFormat()).
   *
   * @param \Drupal\Core\Field\FieldStorageDefinitionInterface[] $storage_definitions
   *   The entity type's field storage definitions.
   * @param string $field_name
   *   The custom field's name.
   *
   * @return array<string, array{text: bool, title: ?string}>
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
      return $item->format;
    }
    $settings = $entity->getFieldDefinition($field_name)->getSetting('field_settings')[$column] ?? [];
    return empty($settings['formatted']) ? FALSE : (($settings['default_format'] ?? '') ?: NULL);
  }

  /**
   * Builds the query for entities whose field may contain absolute URLs.
   */
  protected function getQuery(string $entity_type_id, string $field_name, array $field, bool $filtered): QueryInterface {
    $query = $this->getEntityQuery($entity_type_id, $field['bundles']);
    // Filters can add links that aren't in the stored text, so filtered text
    // fields check every entity with a value.
    if ($filtered) {
      $query->exists($field_name);
      return $query;
    }
    $or = $query->orConditionGroup();
    foreach (array_keys($field['columns']) as $column) {
      foreach ($this->configuration['domains'] as $domain) {
        $or->condition("$field_name.$column", "//$domain", 'CONTAINS');
      }
    }
    return $query->condition($or);
  }

  /**
   * Gets an entity's links to the configured domains in a field.
   *
   * @return iterable<array{0: string, 1: string}>
   *   The link text and URL of each link.
   */
  protected function getEntityLinks(EntityInterface $entity, string $field_name, array $field, bool $filtered): iterable {
    /** @var \Drupal\Core\Entity\FieldableEntityInterface $entity */
    foreach ($entity->get($field_name) as $item) {
      foreach ($field['columns'] as $column => $info) {
        $value = (string) $item->{$column};
        if ($value === '') {
          continue;
        }
        if (!$info['text']) {
          if ($this->isAbsoluteUrl($value)) {
            yield [$info['title'] ? (string) $item->{$info['title']} : '', $value];
          }
          continue;
        }
        $format = $this->getTextFormat($entity, $field_name, $item, $column);
        if ($format === FALSE) {
          continue;
        }
        $html = $filtered ? (string) check_markup($value, $format, $entity->language()->getId()) : $value;
        foreach ($this->getHtmlLinks($html) as [$text, $href]) {
          if ($this->isAbsoluteUrl($href)) {
            yield [$text, $href];
          }
        }
      }
    }
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

  /**
   * Checks whether a URL is an absolute URL to one of the configured domains.
   */
  protected function isAbsoluteUrl(string $url): bool {
    $parts = parse_url(trim($url));
    return isset($parts['host'])
      && in_array($parts['scheme'] ?? 'http', ['http', 'https'], TRUE)
      && in_array(strtolower($parts['host']), $this->configuration['domains'], TRUE);
  }

}
