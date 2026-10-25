<?php

declare(strict_types=1);

namespace Drupal\reports\Plugin\Report;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\helper\EntityHelper;
use Drupal\helper\EntityType;
use Drupal\helper\TrashHelperTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base class for reports that list entities.
 *
 * Provides:
 * - The 'include' option: a list that can contain 'unpublished' (include
 *   unpublished entities) and 'trashed' (include entities in the Trash
 *   module's trash). Empty by default. buildEntityConfigurationForm() adds
 *   its checkboxes.
 * - getEntityQuery() and loadEntityBatch(), which apply those options.
 * - getEntityHeader() and getEntityRow(): title linked to the entity, type,
 *   ID, page and edit URLs, and state (trashed, moderation state, published
 *   or unpublished; see \Drupal\helper\EntityHelper::getState()).
 * - getEntityCacheTags(), with bundle list cache tags.
 *
 * @internal
 *   Experimental until more reports use it: it may still change.
 */
abstract class EntityReportBase extends ReportBase {

  use TrashHelperTrait;

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The entity type helper.
   */
  protected EntityType $entityTypeHelper;

  /**
   * The module handler.
   */
  protected ModuleHandlerInterface $moduleHandler;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->entityTypeHelper = $container->get(EntityType::class);
    $instance->moduleHandler = $container->get('module_handler');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'include' => [],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function setConfiguration(array $configuration): void {
    parent::setConfiguration($configuration);
    // Accepts a list, checkbox values (unchecked ones are 0), or a string
    // separated by commas, as in Drush options.
    $include = $this->configuration['include'];
    $include = is_array($include) ? array_values(array_filter($include)) : preg_split('/[\s,]+/', (string) $include, -1, PREG_SPLIT_NO_EMPTY);
    $include = array_values(array_intersect(['trashed', 'unpublished'], array_map('strval', $include)));
    $this->configuration['include'] = $include;
    $this->includeTrashedEntities($this->isIncluded('trashed'));
  }

  /**
   * Builds the form elements of the entity options.
   *
   * @param array $form
   *   The report's configuration form, to add the elements to.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   The form.
   */
  protected function buildEntityConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form['include'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Include'),
      '#options' => [
        'unpublished' => $this->t('Unpublished'),
        'trashed' => $this->t('Trashed'),
      ],
      '#default_value' => $this->configuration['include'],
    ];
    if (!$this->moduleHandler->moduleExists('trash')) {
      unset($form['include']['#options']['trashed']);
    }
    return $form;
  }

  /**
   * Checks whether the 'include' option has a value.
   *
   * @param string $value
   *   The value: 'unpublished' or 'trashed'.
   */
  protected function isIncluded(string $value): bool {
    return in_array($value, $this->configuration['include'], TRUE);
  }

  /**
   * Gets the entity columns.
   *
   * @return array
   *   The title (linked to the page URL), type, ID, page URL (hidden on the
   *   page), edit URL and state columns, as for getHeader().
   */
  protected function getEntityHeader(): array {
    return [
      'label' => ['label' => $this->t('Title'), 'url' => 'url'],
      'bundle' => ['label' => $this->t('Type'), 'filter' => 'list'],
      'id' => $this->t('ID'),
      'url' => ['label' => $this->t('Page URL'), 'hidden' => TRUE],
      'edit-url' => $this->t('Edit URL'),
      'state' => ['label' => $this->t('State'), 'filter' => 'list'],
    ];
  }

  /**
   * Gets the entity columns of a row.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   *
   * @return array
   *   The entity's data from \Drupal\helper\EntityHelper::toArray(), with
   *   the bundle label instead of its machine name. Keys without a column,
   *   such as 'uuid', are ignored by the report writer. It looks up the
   *   entity's usage and menu link, so build it once per entity.
   */
  protected function getEntityRow(EntityInterface $entity): array {
    return ['bundle' => $this->entityTypeHelper->getBundleLabel($entity->getEntityTypeId(), $entity->bundle())] + EntityHelper::toArray($entity);
  }

  /**
   * Builds an entity query with the entity options.
   *
   * Run it with loadEntityBatch(), or inside executeIgnoringTrash(), so the
   * 'trashed' value of the 'include' option applies.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   * @param string[] $bundles
   *   (optional) The bundles to include. Defaults to all.
   *
   * @return \Drupal\Core\Entity\Query\QueryInterface
   *   The query, without access checks, sorted by ID.
   */
  protected function getEntityQuery(string $entity_type_id, array $bundles = []): QueryInterface {
    $entity_type = $this->entityTypeManager->getDefinition($entity_type_id);
    $query = $this->entityTypeManager->getStorage($entity_type_id)->getQuery()
      ->accessCheck(FALSE)
      ->sort($entity_type->getKey('id'));
    if ($bundles && ($bundle_key = $entity_type->getKey('bundle'))) {
      $query->condition($bundle_key, $bundles, 'IN');
    }
    if (!$this->isIncluded('unpublished') && ($published_key = $entity_type->getKey('published'))) {
      $query->condition($published_key, 1);
    }
    return $query;
  }

  /**
   * Loads the next batch of entities of a query, for a batch operation.
   *
   * Keeps the offset in the batch sandbox, and sets the progress. For
   * progress messages, $context['sandbox']['done'] and
   * $context['sandbox']['total'] are the number of entities loaded so far,
   * including this pass, and in all.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   * @param callable(): \Drupal\Core\Entity\Query\QueryInterface $build_query
   *   Builds the query, e.g. from getEntityQuery(). It's called for each
   *   pass, inside the trash context of the 'include' option.
   * @param array|\ArrayAccess $context
   *   The batch context.
   * @param int|null $limit
   *   (optional) The number of entities per pass. Defaults to the batch
   *   size.
   *
   * @return \Drupal\Core\Entity\EntityInterface[]
   *   The entities of this pass.
   */
  protected function loadEntityBatch(string $entity_type_id, callable $build_query, &$context, ?int $limit = NULL): array {
    $limit ??= $this->getBatchSize();
    return $this->executeIgnoringTrash(function () use ($entity_type_id, $build_query, &$context, $limit): array {
      if (!isset($context['sandbox']['offset'])) {
        $context['sandbox']['offset'] = 0;
        $context['sandbox']['total'] = (int) $build_query()->count()->execute();
      }
      $ids = $build_query()->range($context['sandbox']['offset'], $limit)->execute();
      $context['sandbox']['offset'] += $limit;
      $context['sandbox']['done'] = min($context['sandbox']['offset'], $context['sandbox']['total']);
      $context['finished'] = count($ids) < $limit || $context['sandbox']['offset'] >= $context['sandbox']['total'] ? 1 : $context['sandbox']['offset'] / $context['sandbox']['total'];
      return $this->entityTypeManager->getStorage($entity_type_id)->loadMultiple($ids);
    });
  }

  /**
   * Gets the bundle list cache tags of entities.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   * @param string[] $bundles
   *   (optional) The bundles. Defaults to the entity type's list tag.
   *
   * @return string[]
   *   The cache tags.
   */
  protected function getEntityCacheTags(string $entity_type_id, array $bundles = []): array {
    $entity_type = $this->entityTypeManager->getDefinition($entity_type_id);
    if (!$bundles) {
      return $entity_type->getListCacheTags();
    }
    return array_merge(...array_map(fn ($bundle) => $entity_type->getBundleListCacheTags((string) $bundle), array_values($bundles)));
  }

}
