<?php

declare(strict_types=1);

namespace Drupal\reports\Plugin\Report;

use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldTypePluginManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\field\FieldStorageConfigInterface;
use Drupal\field_permissions\FieldPermissionsServiceInterface;
use Drupal\field_permissions\Plugin\FieldPermissionType\Manager as FieldPermissionTypeManager;
use Drupal\field_permissions\Plugin\FieldPermissionTypeInterface;
use Drupal\reports\Attribute\Report;
use Drupal\views\ViewExecutable;
use Drupal\views\ViewsData;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lists fields, like Field UI's Field list, which it replaces.
 *
 * Sheets match the Field list's tabs: the fields (self::SHEET_DEFAULT), the
 * fields used in views (self::SHEET_VIEWS, with Views UI) and field
 * permissions (self::SHEET_PERMISSIONS, with Field Permissions). Those
 * sheets are only included for users with the permission their tab requires,
 * since downloads include every sheet.
 */
#[Report(
  id: 'fields',
  label: new TranslatableMarkup('Field list'),
  description: new TranslatableMarkup('Overview of fields on all entity types.'),
  permission: 'administer content types',
  routes: [
    'entity.field_storage_config.collection' => self::SHEET_DEFAULT,
    'views_ui.reports_fields' => self::SHEET_VIEWS,
    'field_permissions.reports' => self::SHEET_PERMISSIONS,
  ],
)]
class FieldList extends ReportBase implements RealtimeReportInterface {

  /**
   * The sheet of the fields used in views.
   */
  public const SHEET_VIEWS = 'views';

  /**
   * The sheet of the fields' permissions.
   */
  public const SHEET_PERMISSIONS = 'permissions';

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The entity type bundle info.
   */
  protected EntityTypeBundleInfoInterface $bundleInfo;

  /**
   * The field type plugin manager.
   */
  protected FieldTypePluginManagerInterface $fieldTypeManager;

  /**
   * The current user.
   */
  protected AccountInterface $currentUser;

  /**
   * The Views data, if Views is installed.
   */
  protected ?ViewsData $viewsData;

  /**
   * The field permissions service, if Field Permissions is installed.
   */
  protected ?FieldPermissionsServiceInterface $fieldPermissions;

  /**
   * The field permission type plugin manager, if it's installed.
   */
  protected ?FieldPermissionTypeManager $permissionTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->bundleInfo = $container->get('entity_type.bundle.info');
    $instance->fieldTypeManager = $container->get('plugin.manager.field.field_type');
    $instance->currentUser = $container->get('current_user');
    $instance->viewsData = $container->get('views.views_data', ContainerInterface::NULL_ON_INVALID_REFERENCE);
    $instance->fieldPermissions = $container->get('field_permissions.permissions_service', ContainerInterface::NULL_ON_INVALID_REFERENCE);
    $instance->permissionTypeManager = $container->get('plugin.field_permissions.types.manager', ContainerInterface::NULL_ON_INVALID_REFERENCE);
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public static function isApplicable(array $plugin_definition): bool {
    return \Drupal::moduleHandler()->moduleExists('field');
  }

  /**
   * {@inheritdoc}
   */
  public function getHeader(string $sheet = self::SHEET_DEFAULT): array {
    return match ($sheet) {
      self::SHEET_VIEWS => [
        'id' => $this->t('Field name'),
        'views' => $this->t('Used in'),
      ],
      self::SHEET_PERMISSIONS => [
        'id' => $this->t('Field name'),
        'type' => ['label' => $this->t('Field type'), 'filter' => 'list'],
        'entity_type' => ['label' => $this->t('Entity type'), 'filter' => 'list'],
        'usage' => $this->t('Used in'),
        'permissions' => ['label' => $this->t('Permissions'), 'filter' => 'list'],
      ],
      default => [
        'id' => $this->t('Field name'),
        'entity_type' => ['label' => $this->t('Entity type'), 'filter' => 'list'],
        'type' => ['label' => $this->t('Field type'), 'filter' => 'list'],
        'usage' => $this->t('Used in'),
        'settings_summary' => $this->t('Summary'),
      ],
    };
  }

  /**
   * {@inheritdoc}
   *
   * The views and permissions sheets need their modules, and the permission
   * their tabs require.
   */
  public function getSheets(): array {
    $sheets = parent::getSheets();
    if ($this->viewsData && $this->currentUser->hasPermission('administer views')) {
      $sheets[self::SHEET_VIEWS] = $this->t('Used in views');
    }
    if ($this->fieldPermissions && $this->currentUser->hasPermission('administer field permissions')) {
      $sheets[self::SHEET_PERMISSIONS] = $this->t('Permissions');
    }
    return $sheets;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags(): array {
    $tags = ['config:field_storage_config_list', 'entity_bundles'];
    if (isset($this->getSheets()[self::SHEET_VIEWS])) {
      $tags[] = 'config:view_list';
    }
    return $tags;
  }

  /**
   * {@inheritdoc}
   *
   * The sheets depend on the user's permissions.
   */
  public function getCacheContexts(): array {
    return ['user.permissions'];
  }

  /**
   * {@inheritdoc}
   */
  public function getRows(string $sheet = self::SHEET_DEFAULT): iterable {
    if ($sheet === self::SHEET_VIEWS) {
      yield from $this->getViewsRows();
      return;
    }
    $storages = $this->entityTypeManager->getStorage('field_storage_config')->loadMultiple();
    ksort($storages);
    foreach ($storages as $storage) {
      assert($storage instanceof FieldStorageConfigInterface);
      $row = [
        'id' => $storage->isLocked() ? (string) $this->t('@field_name (Locked)', ['@field_name' => $storage->getName()]) : $storage->getName(),
        'entity_type' => $storage->getTargetEntityTypeId(),
        'usage' => $this->getBundleLabels($storage),
      ];
      if ($sheet === self::SHEET_PERMISSIONS) {
        yield $row + ['type' => $storage->getType(), 'permissions' => $this->getPermissionType($storage)];
        continue;
      }
      $type = $this->fieldTypeManager->getDefinition($storage->getType(), FALSE);
      $type_label = $type ? $this->t('@type (module: @module)', [
        '@type' => $type['label'],
        '@module' => $type['provider'],
      ]) : $storage->getType();
      yield $row + [
        'type' => (string) $type_label,
        'settings_summary' => array_map('strval', $this->fieldTypeManager->getStorageSettingsSummary($storage)),
      ];
    }
  }

  /**
   * Gets the labels of the bundles that have a field.
   *
   * @return string[]
   *   The labels, or machine names of bundles without info.
   */
  protected function getBundleLabels(FieldStorageConfigInterface $storage): array {
    $bundles = $this->bundleInfo->getBundleInfo($storage->getTargetEntityTypeId());
    return array_map(fn ($bundle) => (string) ($bundles[$bundle]['label'] ?? $bundle), array_values($storage->getBundles()));
  }

  /**
   * Gets a field's permission type, as Field Permissions' report shows it.
   */
  protected function getPermissionType(FieldStorageConfigInterface $storage): string {
    $type = $this->fieldPermissions?->fieldGetPermissionType($storage);
    if ($type === NULL || $type === FieldPermissionTypeInterface::ACCESS_PUBLIC || !$this->permissionTypeManager) {
      return (string) $this->t('Not set (Field inherits content permissions.)');
    }
    $plugin = $this->permissionTypeManager->createInstance($type, [], $storage);
    assert($plugin instanceof FieldPermissionTypeInterface);
    return (string) $plugin->getLabel();
  }

  /**
   * Lists the fields used in views, as Views UI's report does.
   *
   * @return iterable<array<string, string>>
   *   Rows with the field name and the IDs of the views that use it.
   *
   * @see \Drupal\views_ui\Controller\ViewsUIController::reportFields()
   */
  protected function getViewsRows(): iterable {
    if (!$this->viewsData) {
      return;
    }
    $fields = [];
    $handler_types = ViewExecutable::getHandlerTypes();
    foreach ($this->entityTypeManager->getStorage('view')->loadMultiple() as $view) {
      $executable = $view->getExecutable();
      $executable->initDisplay();
      foreach ($executable->displayHandlers as $display_id => $display) {
        if (!$executable->setDisplay($display_id)) {
          continue;
        }
        foreach (array_keys($handler_types) as $type) {
          foreach ($executable->getHandlers($type, $display_id) as $item) {
            $field_name = $this->viewsData->get($item['table'])[$item['field']][$type]['field_name'] ?? NULL;
            if ($field_name !== NULL) {
              $fields[$field_name][$view->id()] = $view->id();
            }
          }
        }
      }
    }
    ksort($fields);
    foreach ($fields as $field_name => $views) {
      yield ['id' => $field_name, 'views' => array_values($views)];
    }
  }

}
