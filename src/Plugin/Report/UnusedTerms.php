<?php

declare(strict_types=1);

namespace Drupal\reports\Plugin\Report;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\reports\Attribute\Report;
use Drupal\reports\ReportWriter;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lists taxonomy terms that no entity references.
 *
 * Terms are used when an entity reference field of any content entity type
 * references them. A term's parent field doesn't count: a term with child
 * terms but no content is unused. Links to terms in text or menus aren't
 * counted either.
 */
#[Report(
  id: 'unused_terms',
  label: new TranslatableMarkup('Unused taxonomy terms'),
  description: new TranslatableMarkup('Taxonomy terms that no content or other entity references.'),
  permission: 'access site reports,access taxonomy overview',
)]
class UnusedTerms extends EntityReportBase implements GeneratedReportInterface {

  /**
   * The entity field manager.
   */
  protected EntityFieldManagerInterface $entityFieldManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->entityFieldManager = $container->get('entity_field.manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   *
   * Only when the Taxonomy module is installed.
   */
  public static function isApplicable(array $plugin_definition): bool {
    return \Drupal::moduleHandler()->moduleExists('taxonomy');
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return ['vocabularies' => []] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   *
   * Vocabularies can be given as a list, or a string separated by commas, as
   * in Drush options. None means all.
   */
  public function setConfiguration(array $configuration): void {
    parent::setConfiguration($configuration);
    $vocabularies = $this->configuration['vocabularies'];
    $vocabularies = is_array($vocabularies) ? array_filter($vocabularies) : preg_split('/[\s,]+/', (string) $vocabularies, -1, PREG_SPLIT_NO_EMPTY);
    $vocabularies = array_values(array_unique(array_map('strval', $vocabularies)));
    sort($vocabularies);
    $this->configuration['vocabularies'] = $vocabularies;
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form['vocabularies'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Vocabularies'),
      '#description' => $this->t('Leave empty to check every vocabulary.'),
      '#options' => static::isApplicable($this->pluginDefinition) ? array_map(fn ($vocabulary) => $vocabulary->label(), $this->entityTypeManager->getStorage('taxonomy_vocabulary')->loadMultiple()) : [],
      '#access' => static::isApplicable($this->pluginDefinition),
      '#default_value' => $this->configuration['vocabularies'],
    ];
    return parent::buildConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function getHeader(string $sheet = self::SHEET_DEFAULT): array {
    $header = parent::getHeader($sheet);
    $header['bundle']['label'] = $this->t('Vocabulary');
    return $header;
  }

  /**
   * {@inheritdoc}
   *
   * Warns when no field references taxonomy terms.
   */
  public function getRequirements(): array {
    if ($this->getReferenceFields()) {
      return [];
    }
    return [
      'fields' => [
        'title' => $this->t('Term reference fields'),
        'description' => $this->t('No entity reference field references taxonomy terms, so every term is listed.'),
        'severity' => RequirementSeverity::Warning,
      ],
    ];
  }

  /**
   * {@inheritdoc}
   *
   * The term list tags, and the list tags of the bundles with term reference
   * fields, so saving content that references terms counts as a change.
   */
  public function getCacheTags(): array {
    $tags = ['entity_field_info', 'config:field_config_list', 'config:taxonomy_vocabulary_list'];
    if (!static::isApplicable($this->pluginDefinition)) {
      return $tags;
    }
    $tags = array_merge($tags, $this->getEntityCacheTags('taxonomy_term', $this->getVocabularies()));
    foreach ($this->getReferenceFields() as $entity_type_id => $fields) {
      $tags = array_merge($tags, $this->getEntityCacheTags($entity_type_id, array_unique(array_merge(...array_values($fields)))));
    }
    return array_values(array_unique($tags));
  }

  /**
   * {@inheritdoc}
   */
  public function getBatchOperations(): array {
    return array_map(fn ($vocabulary) => ['processVocabulary', [$vocabulary]], $this->getVocabularies());
  }

  /**
   * Batch operation: lists the next unused terms of a vocabulary.
   *
   * @param string $vocabulary
   *   The vocabulary ID.
   * @param \Drupal\reports\ReportWriter $writer
   *   The report writer.
   * @param array|\ArrayAccess $context
   *   The batch context.
   */
  public function processVocabulary(string $vocabulary, ReportWriter $writer, &$context): void {
    $terms = $this->loadEntityBatch('taxonomy_term', fn () => $this->getEntityQuery('taxonomy_term', [$vocabulary]), $context);
    $used = $this->getUsedTermIds(array_keys($terms));
    foreach ($terms as $id => $term) {
      if (!isset($used[$id])) {
        $writer->addRow($this->getEntityRow($term));
      }
    }
    $context['message'] = $this->t('Checking @vocabulary terms (@count of @total).', [
      '@vocabulary' => $this->entityTypeHelper->getBundleLabel('taxonomy_term', $vocabulary),
      '@count' => $context['sandbox']['done'],
      '@total' => $context['sandbox']['total'],
    ]);
  }

  /**
   * Gets which of the given terms are referenced.
   *
   * @param array $ids
   *   The term IDs.
   *
   * @return array<int|string, true>
   *   The referenced term IDs, as keys.
   */
  protected function getUsedTermIds(array $ids): array {
    $used = [];
    if (!$ids) {
      return $used;
    }
    foreach ($this->getReferenceFields() as $entity_type_id => $fields) {
      foreach ($fields as $field_name => $bundles) {
        // References from unpublished content count too; trashed content
        // doesn't, since Trash hides it from queries.
        $rows = $this->entityTypeManager->getStorage($entity_type_id)->getAggregateQuery()
          ->accessCheck(FALSE)
          ->condition("$field_name.target_id", $ids, 'IN')
          ->groupBy("$field_name.target_id")
          ->execute();
        foreach ($rows as $row) {
          // Each row has the one grouped value.
          $used[reset($row)] = TRUE;
        }
      }
    }
    return $used;
  }

  /**
   * Gets the entity reference fields that reference taxonomy terms.
   *
   * @return array<string, array<string, string[]>>
   *   The bundles that have each field, keyed by entity type ID and field
   *   name. Terms' own parent field is left out.
   */
  protected function getReferenceFields(): array {
    $fields = [];
    foreach ($this->entityFieldManager->getFieldMapByFieldType('entity_reference') as $entity_type_id => $entity_fields) {
      $storage_definitions = $this->entityFieldManager->getFieldStorageDefinitions($entity_type_id);
      foreach ($entity_fields as $field_name => $info) {
        if ($entity_type_id === 'taxonomy_term' && $field_name === 'parent') {
          continue;
        }
        if (($storage_definitions[$field_name] ?? NULL)?->getSetting('target_type') === 'taxonomy_term') {
          $fields[$entity_type_id][$field_name] = array_values($info['bundles']);
        }
      }
    }
    return $fields;
  }

  /**
   * Gets the vocabularies to check.
   *
   * @return string[]
   *   The configured vocabularies that exist, or every vocabulary.
   */
  protected function getVocabularies(): array {
    $all = static::isApplicable($this->pluginDefinition) ? $this->entityTypeHelper->getBundles('taxonomy_term') : [];
    return $this->configuration['vocabularies'] ? array_values(array_intersect($this->configuration['vocabularies'], $all)) : $all;
  }

}
