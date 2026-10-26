<?php

declare(strict_types=1);

namespace Drupal\reports\Plugin\Report;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\helper\ArrayHelper;
use Drupal\reports\Attribute\Report;
use Drupal\reports\ReportWriter;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lists entities that share the same value in a field, such as a title.
 */
#[Report(
  id: 'duplicate_values',
  label: new TranslatableMarkup('Duplicate values'),
  description: new TranslatableMarkup('Content that shares the same value in a field, such as the same title or summary.'),
)]
class DuplicateValues extends EntityReportBase implements GeneratedReportInterface {

  /**
   * The field types that can be checked: their main property is 'value'.
   */
  const FIELD_TYPES = ['string', 'string_long', 'email', 'text', 'text_long', 'text_with_summary'];

  /**
   * The number of duplicate values handled per batch pass.
   */
  const VALUES_PER_PASS = 50;

  /**
   * The entity field manager.
   */
  protected EntityFieldManagerInterface $entityFieldManager;

  /**
   * The fields that can be checked, once built.
   *
   * @var array<string, string>|null
   */
  protected ?array $fieldOptions = NULL;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, [], $plugin_id, $plugin_definition);
    $instance->entityFieldManager = $container->get('entity_field.manager');
    // The default field depends on the entity types, so set it after
    // injection.
    $instance->setConfiguration($configuration);
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    $options = isset($this->entityFieldManager) ? $this->getFieldOptions() : [];
    $default = isset($options['node.title']) ? 'node.title' : (string) array_key_first($options);
    return parent::defaultConfiguration() + ['field' => $default];
  }

  /**
   * {@inheritdoc}
   */
  public function setConfiguration(array $configuration): void {
    parent::setConfiguration($configuration);
    $this->configuration['field'] = (string) $this->configuration['field'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form['field'] = [
      '#type' => 'select',
      '#title' => $this->t('Field'),
      '#options' => $this->getFieldOptions(),
      '#default_value' => $this->configuration['field'],
      '#required' => TRUE,
    ];
    return parent::buildConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function getHeader(string $sheet = self::SHEET_DEFAULT): array {
    $header = parent::getHeader($sheet);
    ArrayHelper::insertAfterKey($header, 'id', [
      'value' => $this->t('Value'),
      'count' => ['label' => $this->t('Count'), 'filter' => 'list'],
    ]);
    return $header;
  }

  /**
   * {@inheritdoc}
   *
   * An error when the configured field can't be checked.
   */
  public function getRequirements(): array {
    if ($this->getField()) {
      return [];
    }
    return [
      'field' => [
        'title' => $this->t('Field'),
        'description' => $this->t('The field %field does not exist or cannot be checked. Choose another one in the options.', ['%field' => $this->configuration['field']]),
        'severity' => RequirementSeverity::Error,
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags(): array {
    $field = $this->getField();
    return array_merge(['entity_field_info'], $field ? $this->getEntityCacheTags($field[0]) : []);
  }

  /**
   * {@inheritdoc}
   */
  public function getBatchOperations(): array {
    return $this->getField() ? [['processDuplicates']] : [];
  }

  /**
   * Batch operation: lists the entities with each duplicate value.
   *
   * The first pass finds the duplicate values; the next ones list their
   * entities, VALUES_PER_PASS values at a time.
   *
   * @param \Drupal\reports\ReportWriter $writer
   *   The report writer.
   * @param array|\ArrayAccess $context
   *   The batch context.
   */
  public function processDuplicates(ReportWriter $writer, &$context): void {
    [$entity_type_id, $field_name] = $this->getField();
    if (!isset($context['sandbox']['values'])) {
      $context['sandbox']['values'] = $this->findDuplicates($entity_type_id, $field_name);
      $context['sandbox']['offset'] = 0;
      $context['finished'] = $context['sandbox']['values'] ? 0 : 1;
      $context['message'] = $this->t('Found @count duplicate values.', ['@count' => count($context['sandbox']['values'])]);
      return;
    }

    $counts = array_slice($context['sandbox']['values'], $context['sandbox']['offset'], static::VALUES_PER_PASS, TRUE);
    $ids = $this->executeIgnoringTrash(fn () => $this->getEntityQuery($entity_type_id)
      ->condition($field_name, array_map('strval', array_keys($counts)), 'IN')
      ->execute());
    // The database may compare values ignoring case.
    $counts_by_value = array_change_key_case($counts, CASE_LOWER);
    foreach ($this->entityTypeManager->getStorage($entity_type_id)->loadMultiple($ids) as $entity) {
      /** @var \Drupal\Core\Entity\FieldableEntityInterface $entity */
      $value = (string) $entity->get($field_name)->value;
      $writer->addRow([
        'value' => $value,
        'count' => $counts[$value] ?? $counts_by_value[mb_strtolower($value)] ?? '',
      ] + $this->getEntityRow($entity));
    }

    $context['sandbox']['offset'] += static::VALUES_PER_PASS;
    $total = count($context['sandbox']['values']);
    $context['finished'] = min(1, $context['sandbox']['offset'] / $total);
    $context['message'] = $this->t('Listed @count of @total duplicate values.', [
      '@count' => min($context['sandbox']['offset'], $total),
      '@total' => $total,
    ]);
  }

  /**
   * Finds the values more than one entity has.
   *
   * Values are compared as the database compares them: on MySQL, that ignores
   * case and trailing spaces.
   *
   * @return array<string, int>
   *   The number of entities with each duplicate value, keyed by value.
   */
  protected function findDuplicates(string $entity_type_id, string $field_name): array {
    $entity_type = $this->entityTypeManager->getDefinition($entity_type_id);
    $id_key = $entity_type->getKey('id');
    $rows = $this->executeIgnoringTrash(function () use ($entity_type, $entity_type_id, $field_name, $id_key) {
      $query = $this->entityTypeManager->getStorage($entity_type_id)->getAggregateQuery()
        ->accessCheck(FALSE)
        ->condition($field_name, '', '<>')
        ->groupBy($field_name)
        ->aggregate($id_key, 'COUNT')
        ->conditionAggregate($id_key, 'COUNT', 1, '>');
      if (!$this->isIncluded('unpublished') && ($published_key = $entity_type->getKey('published'))) {
        $query->condition($published_key, 1);
      }
      return $query->execute();
    });
    $values = [];
    foreach ($rows as $row) {
      // Each row has the grouped value, then the count.
      $values[(string) reset($row)] = (int) end($row);
    }
    return $values;
  }

  /**
   * Gets the configured entity type and field.
   *
   * @return array{0: string, 1: string}|null
   *   The entity type ID and field name, or NULL if the field can't be
   *   checked.
   */
  protected function getField(): ?array {
    return isset($this->getFieldOptions()[$this->configuration['field']])
      ? explode('.', $this->configuration['field'], 2)
      : NULL;
  }

  /**
   * Gets the fields that can be checked.
   *
   * @return array<string, string>
   *   Labels like "Content: Title", keyed by entity type ID and field name,
   *   e.g. 'node.title'.
   */
  protected function getFieldOptions(): array {
    if ($this->fieldOptions !== NULL) {
      return $this->fieldOptions;
    }
    $options = [];
    foreach ($this->entityFieldManager->getFieldMap() as $entity_type_id => $fields) {
      $entity_type = $this->entityTypeManager->getDefinition($entity_type_id, FALSE);
      if (!$entity_type?->entityClassImplements(ContentEntityInterface::class) || !$entity_type->getBaseTable()) {
        continue;
      }
      $storage_definitions = $this->entityFieldManager->getFieldStorageDefinitions($entity_type_id);
      foreach ($fields as $field_name => $info) {
        // Single-value fields only: grouping multi-value fields would count
        // each entity once per value.
        $storage = $storage_definitions[$field_name] ?? NULL;
        if (in_array($info['type'], static::FIELD_TYPES, TRUE) && $storage && $storage->getCardinality() === 1) {
          // Configurable field storages have no label, so use the field's
          // label on its first bundle.
          $definition = $this->entityFieldManager->getFieldDefinitions($entity_type_id, (string) reset($info['bundles']))[$field_name] ?? NULL;
          $options["$entity_type_id.$field_name"] = $entity_type->getLabel() . ': ' . ((string) $definition?->getLabel() ?: $field_name);
        }
      }
    }
    asort($options);
    return $this->fieldOptions = $options;
  }

}
