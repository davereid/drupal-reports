<?php

declare(strict_types=1);

namespace Drupal\reports\Plugin\Report;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\reports\Attribute\Report;
use Drupal\reports\ReportWriter;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Counts the items of each bundle of each content entity type.
 *
 * Useful to find unused bundles. It has no options.
 */
#[Report(
  id: 'content_counts',
  label: new TranslatableMarkup('Content counts'),
  description: new TranslatableMarkup('The number of items of each type of content, media, taxonomy term and other content entities, and how many are published.'),
)]
class ContentCounts extends ReportBase implements GeneratedReportInterface {

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The entity type bundle info.
   */
  protected EntityTypeBundleInfoInterface $bundleInfo;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->bundleInfo = $container->get('entity_type.bundle.info');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getHeader(string $sheet = self::SHEET_DEFAULT): array {
    return [
      'entity_type' => ['label' => $this->t('Entity type'), 'filter' => 'list'],
      'bundle' => $this->t('Bundle'),
      'name' => $this->t('Machine name'),
      'total' => $this->t('Total'),
      'published' => $this->t('Published'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags(): array {
    $tags = ['entity_bundles'];
    foreach ($this->getEntityTypeIds() as $entity_type_id) {
      $tags = array_merge($tags, $this->entityTypeManager->getDefinition($entity_type_id)->getListCacheTags());
    }
    return $tags;
  }

  /**
   * {@inheritdoc}
   */
  public function getBatchOperations(): array {
    return array_map(fn ($entity_type_id) => ['processEntityType', [$entity_type_id]], $this->getEntityTypeIds());
  }

  /**
   * Batch operation: counts the items of each bundle of an entity type.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   * @param \Drupal\reports\ReportWriter $writer
   *   The report writer.
   * @param array|\ArrayAccess $context
   *   The batch context.
   */
  public function processEntityType(string $entity_type_id, ReportWriter $writer, &$context): void {
    $entity_type = $this->entityTypeManager->getDefinition($entity_type_id);
    $bundle_key = $entity_type->getKey('bundle');
    $published_key = $entity_type->getKey('published');
    $storage = $this->entityTypeManager->getStorage($entity_type_id);
    foreach ($this->bundleInfo->getBundleInfo($entity_type_id) as $bundle => $info) {
      $query = fn () => $bundle_key ? $storage->getQuery()->accessCheck(FALSE)->condition($bundle_key, $bundle) : $storage->getQuery()->accessCheck(FALSE);
      $writer->addRow([
        'entity_type' => $entity_type->getLabel(),
        'bundle' => $info['label'],
        'name' => $bundle,
        'total' => $query()->count()->execute(),
        'published' => $published_key ? $query()->condition($published_key, 1)->count()->execute() : '',
      ]);
    }
    $context['message'] = $this->t('Counting @entity_type.', ['@entity_type' => $entity_type->getLabel()]);
  }

  /**
   * Gets the content entity types with SQL storage.
   *
   * @return string[]
   *   The entity type IDs.
   */
  protected function getEntityTypeIds(): array {
    $ids = [];
    foreach ($this->entityTypeManager->getDefinitions() as $id => $entity_type) {
      // Entity types without a base table, such as computed ones, can't be
      // queried.
      if ($entity_type->entityClassImplements(ContentEntityInterface::class) && $entity_type->getBaseTable()) {
        $ids[] = $id;
      }
    }
    return $ids;
  }

}
