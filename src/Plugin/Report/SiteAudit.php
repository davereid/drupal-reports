<?php

declare(strict_types=1);

namespace Drupal\reports\Plugin\Report;

use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityDescriptionInterface;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionableEntityBundleInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldTypePluginManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\filter\Entity\FilterFormat;
use Drupal\media\MediaTypeInterface;
use Drupal\node\NodeTypeInterface;
use Drupal\reports\Attribute\Report;
use Drupal\reports\ReportWriter;
use Drupal\user\RoleInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lists the fields of each bundle, with their settings and displays.
 *
 * Each bundle has its own sheet, so the report downloads as one Excel file.
 */
#[Report(
  id: 'site_audit',
  label: new TranslatableMarkup('Site audit'),
  description: new TranslatableMarkup('The fields of each content and media type, with their settings, form widget and display formatter.'),
  downloadOnly: TRUE,
)]
class SiteAudit extends ReportBase implements GeneratedReportInterface, PluginFormInterface {

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The entity field manager.
   */
  protected EntityFieldManagerInterface $entityFieldManager;

  /**
   * The entity type bundle info.
   */
  protected EntityTypeBundleInfoInterface $bundleInfo;

  /**
   * The entity display repository.
   */
  protected EntityDisplayRepositoryInterface $entityDisplayRepository;

  /**
   * The module handler.
   */
  protected ModuleHandlerInterface $moduleHandler;

  /**
   * The field type plugin manager.
   */
  protected FieldTypePluginManagerInterface $fieldTypeManager;

  /**
   * The field widget plugin manager.
   */
  protected PluginManagerInterface $widgetManager;

  /**
   * The field formatter plugin manager.
   */
  protected PluginManagerInterface $formatterManager;

  /**
   * Content Moderation's moderation information, if it's installed.
   *
   * @var \Drupal\content_moderation\ModerationInformationInterface|null
   */
  protected ?object $moderationInformation;

  /**
   * Content Translation's manager, if it's installed.
   *
   * @var \Drupal\content_translation\ContentTranslationManagerInterface|null
   */
  protected ?object $contentTranslationManager;

  /**
   * The custom_field subfield type plugin manager, if it's installed.
   */
  protected ?PluginManagerInterface $customFieldTypeManager;

  /**
   * The custom_field widget plugin manager, if it's installed.
   */
  protected ?PluginManagerInterface $customFieldWidgetManager;

  /**
   * The custom_field formatter plugin manager, if it's installed.
   */
  protected ?PluginManagerInterface $customFieldFormatterManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->entityFieldManager = $container->get('entity_field.manager');
    $instance->bundleInfo = $container->get('entity_type.bundle.info');
    $instance->entityDisplayRepository = $container->get('entity_display.repository');
    $instance->moduleHandler = $container->get('module_handler');
    $instance->fieldTypeManager = $container->get('plugin.manager.field.field_type');
    $instance->widgetManager = $container->get('plugin.manager.field.widget');
    $instance->formatterManager = $container->get('plugin.manager.field.formatter');
    $instance->moderationInformation = $container->get('content_moderation.moderation_information', ContainerInterface::NULL_ON_INVALID_REFERENCE);
    $instance->contentTranslationManager = $container->get('content_translation.manager', ContainerInterface::NULL_ON_INVALID_REFERENCE);
    $custom_field_managers = [
      'customFieldTypeManager' => 'type',
      'customFieldWidgetManager' => 'widget',
      'customFieldFormatterManager' => 'formatter',
    ];
    foreach ($custom_field_managers as $property => $type) {
      $instance->{$property} = $container->get("plugin.manager.custom_field_$type", ContainerInterface::NULL_ON_INVALID_REFERENCE);
    }
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return ['entity_types' => ['media', 'node', 'taxonomy_term']];
  }

  /**
   * {@inheritdoc}
   */
  public function setConfiguration(array $configuration): void {
    parent::setConfiguration($configuration);
    $types = $this->configuration['entity_types'];
    $types = is_array($types) ? array_values(array_filter($types)) : preg_split('/[\s,]+/', (string) $types, -1, PREG_SPLIT_NO_EMPTY);
    sort($types);
    $this->configuration['entity_types'] = $types;
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $options = [];
    foreach ($this->entityTypeManager->getDefinitions() as $id => $entity_type) {
      if ($entity_type->entityClassImplements(ContentEntityInterface::class) && $entity_type->getBundleEntityType()) {
        $options[$id] = $entity_type->getLabel();
      }
    }
    asort($options);
    $form['entity_types'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Entity types'),
      '#options' => $options,
      '#default_value' => $this->configuration['entity_types'],
      '#required' => TRUE,
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
   * The list tags of field, display and bundle configuration.
   */
  public function getCacheTags(): array {
    return [
      'entity_field_info',
      'entity_bundles',
      'config:field_config_list',
      'config:field_storage_config_list',
      'config:entity_form_display_list',
      'config:entity_view_display_list',
      'config:filter_format_list',
      'config:editor_list',
      'config:user_role_list',
      'config:menu_list',
      'config:pathauto_pattern_list',
      'config:workflow_list',
      'config:language_content_settings_list',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getHeader(): array {
    return [
      'label' => $this->t('Label'),
      'name' => $this->t('Machine name'),
      'type' => ['label' => $this->t('Field type'), 'filter' => 'list'],
      'values' => $this->t('Values'),
      'cardinality' => ['label' => $this->t('Cardinality'), 'filter' => 'list'],
      'required' => ['label' => $this->t('Required'), 'filter' => 'list'],
      'widget' => ['label' => $this->t('Widget'), 'filter' => 'list'],
      'formatter' => ['label' => $this->t('Formatter'), 'filter' => 'list'],
      'character_limit' => $this->t('Character limit'),
      'description' => $this->t('Help text'),
      'notes' => $this->t('Notes'),
      'translatable' => ['label' => $this->t('Translatable'), 'filter' => 'list'],
    ];
  }

  /**
   * {@inheritdoc}
   *
   * One sheet per bundle of the configured entity types.
   */
  public function getSheets(): array {
    $sheets = [];
    foreach ($this->getBundles() as [$entity_type_id, $bundle, $label]) {
      // A list of the entity type's bundles, before their sheets. Bundle
      // names can't contain '-', so this can't clash with a bundle sheet.
      $sheets["$entity_type_id--bundles"] ??= [
        'label' => $this->t('@entity_type types', ['@entity_type' => $this->entityTypeManager->getDefinition($entity_type_id)->getLabel()]),
        'header' => $this->getBundleHeader($entity_type_id),
      ];
      $sheets["{$entity_type_id}__{$bundle}"] = ['label' => $label, 'header' => $this->getHeader()];
    }
    if ($this->moduleHandler->moduleExists('filter')) {
      $sheets['text_formats'] = [
        'label' => $this->t('Text formats'),
        'header' => [
          'format' => $this->t('Machine name'),
          'name' => $this->t('Name'),
          'status' => ['label' => $this->t('Enabled'), 'filter' => 'list'],
          'roles' => $this->t('Roles'),
          'editor' => ['label' => $this->t('Text editor'), 'filter' => 'list'],
          'filters' => $this->t('Filters'),
          'allowed_html' => $this->t('Allowed HTML'),
        ],
      ];
    }
    return $sheets ?: parent::getSheets();
  }

  /**
   * {@inheritdoc}
   */
  public function getBatchOperations(): array {
    $operations = [];
    foreach ($this->getBundles() as [$entity_type_id, $bundle]) {
      $operations[] = ['processBundle', [$entity_type_id, $bundle]];
    }
    // List each entity type's bundles first.
    foreach (array_unique(array_column($this->getBundles(), 0)) as $entity_type_id) {
      array_unshift($operations, ['processBundleList', [$entity_type_id]]);
    }
    if ($this->moduleHandler->moduleExists('filter')) {
      $operations[] = ['processTextFormats'];
    }
    return $operations;
  }

  /**
   * Batch operation: lists the text formats.
   *
   * @param \Drupal\reports\ReportWriter $writer
   *   The report writer.
   * @param array|\ArrayAccess $context
   *   The batch context.
   */
  public function processTextFormats(ReportWriter $writer, &$context): void {
    $roles = $this->entityTypeManager->getStorage('user_role')->loadMultiple();
    $editors = $this->moduleHandler->moduleExists('editor') ? $this->entityTypeManager->getStorage('editor')->loadMultiple() : [];
    /** @var \Drupal\filter\FilterFormatInterface[] $formats */
    $formats = $this->entityTypeManager->getStorage('filter_format')->loadMultiple();
    uasort($formats, [FilterFormat::class, 'sort']);
    foreach ($formats as $id => $format) {
      // @todo Use $format->getRoles() once Drupal 11.4 is the minimum.
      $format_roles = array_filter($roles, fn (RoleInterface $role) => $format->isFallbackFormat() || $role->hasPermission((string) $format->getPermissionName()));
      $filters = [];
      foreach ($format->filters() as $filter_id => $filter) {
        if (!empty($filter->getConfiguration()['status'])) {
          $filters[] = "{$filter->getLabel()} ($filter_id)";
        }
      }
      $writer->addRow([
        'format' => $id,
        'name' => $format->label(),
        'status' => $format->status() ? $this->t('Yes') : $this->t('No'),
        'roles' => implode(', ', array_map(fn (RoleInterface $role) => $role->label(), $format_roles)),
        'editor' => isset($editors[$id]) ? $editors[$id]->getEditor() : '',
        'filters' => implode(', ', $filters),
        'allowed_html' => $format->filters()->has('filter_html') && !empty($format->filters('filter_html')->getConfiguration()['status']) ? $format->filters('filter_html')->getConfiguration()['settings']['allowed_html'] ?? '' : '',
      ], 'text_formats');
    }
    $context['message'] = $this->t('Checking text formats.');
  }

  /**
   * Batch operation: lists the fields of a bundle.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   * @param string $bundle
   *   The bundle.
   * @param \Drupal\reports\ReportWriter $writer
   *   The report writer.
   * @param array|\ArrayAccess $context
   *   The batch context.
   */
  public function processBundle(string $entity_type_id, string $bundle, ReportWriter $writer, &$context): void {
    $form_display = $this->entityDisplayRepository->getFormDisplay($entity_type_id, $bundle);
    $view_display = $this->entityDisplayRepository->getViewDisplay($entity_type_id, $bundle);
    foreach ($this->entityFieldManager->getFieldDefinitions($entity_type_id, $bundle) as $name => $field) {
      // Base fields without displays, such as IDs and revision metadata.
      if ($field->getFieldStorageDefinition()->isBaseField() && !$form_display->getComponent($name) && !$view_display->getComponent($name)) {
        continue;
      }
      $cardinality = $field->getFieldStorageDefinition()->getCardinality();
      $widget = $form_display->getComponent($name);
      $formatter = $view_display->getComponent($name);
      $writer->addRow([
        'label' => $field->getLabel(),
        'name' => $name,
        'type' => $this->fieldTypeManager->getDefinition($field->getType(), FALSE)['label'] ?? $field->getType(),
        'values' => $this->getAllowedValues($field),
        'cardinality' => $cardinality === -1 ? $this->t('Unlimited') : $cardinality,
        'required' => $field->isRequired() ? $this->t('Yes') : $this->t('No'),
        'widget' => $widget ? $this->widgetManager->getDefinition($widget['type'], FALSE)['label'] ?? $widget['type'] : $this->t('Hidden'),
        'formatter' => $formatter ? $this->formatterManager->getDefinition($formatter['type'], FALSE)['label'] ?? $formatter['type'] : $this->t('Hidden'),
        'character_limit' => $this->getCharacterLimit($field, $widget),
        'description' => $field->getDescription(),
        'translatable' => $field->isTranslatable() ? $this->t('Yes') : $this->t('No'),
      ], "{$entity_type_id}__{$bundle}");
      if ($field->getType() === 'custom') {
        $this->addCustomFieldRows($field, $widget, $formatter, $writer, "{$entity_type_id}__{$bundle}");
      }
    }
    $context['message'] = $this->t('Checking @bundle.', ['@bundle' => $bundle]);
  }

  /**
   * Gets the columns of an entity type's bundle list sheet.
   *
   * Columns that don't apply to the entity type, or need a module that isn't
   * installed, are left out.
   */
  protected function getBundleHeader(string $entity_type_id): array {
    $entity_type = $this->entityTypeManager->getDefinition($entity_type_id);
    $yes_no = fn ($label) => ['label' => $label, 'filter' => 'list'];
    $header = [
      'label' => $this->t('Label'),
      'name' => $this->t('Machine name'),
      'description' => $this->t('Description'),
    ];
    if ($entity_type_id === 'media') {
      $header['media_source'] = $yes_no($this->t('Media source'));
    }
    if ($entity_type_id === 'node') {
      $header['menus'] = $this->t('Available menus');
      $header['preview'] = $yes_no($this->t('Preview'));
      $header['submitted'] = $yes_no($this->t('Display author and date'));
      $header['published_default'] = $yes_no($this->t('Published by default'));
    }
    $header['url_pattern'] = $this->t('URL pattern');
    if ($entity_type->isRevisionable()) {
      $header['revisions'] = $yes_no($this->t('New revisions'));
    }
    if ($this->moduleHandler->moduleExists('content_moderation')) {
      $header['workflow'] = $yes_no($this->t('Workflow'));
    }
    if ($this->moduleHandler->moduleExists('content_translation')) {
      $header['translatable'] = $yes_no($this->t('Translatable'));
    }
    if ($this->moduleHandler->moduleExists('layout_builder')) {
      $header['layout_builder'] = $yes_no($this->t('Layout Builder'));
    }
    return $header;
  }

  /**
   * Batch operation: lists the bundles of an entity type.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   * @param \Drupal\reports\ReportWriter $writer
   *   The report writer.
   * @param array|\ArrayAccess $context
   *   The batch context.
   */
  public function processBundleList(string $entity_type_id, ReportWriter $writer, &$context): void {
    $entity_type = $this->entityTypeManager->getDefinition($entity_type_id);
    $bundle_entities = $entity_type->getBundleEntityType() ? $this->entityTypeManager->getStorage($entity_type->getBundleEntityType())->loadMultiple() : [];
    $menus = $this->moduleHandler->moduleExists('menu_ui') ? $this->entityTypeManager->getStorage('menu')->loadMultiple() : [];
    foreach ($this->bundleInfo->getBundleInfo($entity_type_id) as $bundle => $info) {
      $bundle_entity = $bundle_entities[$bundle] ?? NULL;
      // Menu UI stores a content type's menus as a third-party setting.
      $available_menus = $bundle_entity instanceof ConfigEntityInterface ? $bundle_entity->getThirdPartySetting('menu_ui', 'available_menus', []) : [];
      $writer->addRow([
        'label' => $info['label'],
        'name' => $bundle,
        'description' => $bundle_entity instanceof EntityDescriptionInterface ? $bundle_entity->getDescription() : '',
        'menus' => implode(', ', array_map(fn ($menu) => isset($menus[$menu]) ? $menus[$menu]->label() : $menu, $available_menus)),
        'url_pattern' => implode('; ', $this->getUrlPatterns($entity_type_id, (string) $bundle)),
      ] + $this->getBundleSettings($entity_type_id, (string) $bundle, $bundle_entity), "$entity_type_id--bundles");
    }
    $context['message'] = $this->t('Checking @entity_type types.', ['@entity_type' => $entity_type->getLabel()]);
  }

  /**
   * Gets a bundle's revision, workflow, translation, display and type settings.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   * @param string $bundle
   *   The bundle.
   * @param \Drupal\Core\Entity\EntityInterface|null $bundle_entity
   *   The bundle's config entity, if the entity type has one.
   *
   * @return array
   *   Values for the getBundleHeader() columns. Columns that aren't in the
   *   sheet's header are ignored.
   */
  protected function getBundleSettings(string $entity_type_id, string $bundle, ?EntityInterface $bundle_entity): array {
    $yes_no = fn (bool $value) => $value ? $this->t('Yes') : $this->t('No');
    $settings = [];
    if ($bundle_entity instanceof RevisionableEntityBundleInterface) {
      $settings['revisions'] = $yes_no($bundle_entity->shouldCreateNewRevision());
    }
    if ($this->moderationInformation) {
      $settings['workflow'] = $this->moderationInformation->getWorkflowForEntityTypeAndBundle($entity_type_id, $bundle)?->label() ?? '';
    }
    if ($this->contentTranslationManager) {
      $settings['translatable'] = $yes_no($this->contentTranslationManager->isEnabled($entity_type_id, $bundle));
    }
    if ($this->moduleHandler->moduleExists('layout_builder')) {
      $display = $this->entityDisplayRepository->getViewDisplay($entity_type_id, $bundle);
      $settings['layout_builder'] = match (TRUE) {
        !$display->getThirdPartySetting('layout_builder', 'enabled') => $this->t('No'),
        (bool) $display->getThirdPartySetting('layout_builder', 'allow_custom') => $this->t('Yes, overrides allowed'),
        default => $this->t('Yes'),
      };
    }
    if ($bundle_entity instanceof NodeTypeInterface) {
      // FALSE returns the NodePreviewMode enum; the integer form is deprecated
      // in Drupal 11.3, but the interface doesn't declare the parameter yet.
      // @phpstan-ignore arguments.count
      $settings['preview'] = $bundle_entity->getPreviewMode(FALSE)->label();
      $settings['submitted'] = $yes_no($bundle_entity->displaySubmitted());
      $status = $this->entityFieldManager->getFieldDefinitions($entity_type_id, $bundle)['status'] ?? NULL;
      $settings['published_default'] = $yes_no((bool) ($status?->getDefaultValueLiteral()[0]['value'] ?? TRUE));
    }
    if ($bundle_entity instanceof MediaTypeInterface) {
      $settings['media_source'] = $bundle_entity->getSource()->getPluginDefinition()['label'] ?? $bundle_entity->getSource()->getPluginId();
    }
    return $settings;
  }

  /**
   * Gets the Pathauto patterns that can apply to a bundle.
   *
   * @return string[]
   *   The enabled patterns for the entity type whose bundle conditions include
   *   the bundle, or that have none, by weight: the first one that applies
   *   is used. Empty if Pathauto isn't installed.
   */
  protected function getUrlPatterns(string $entity_type_id, string $bundle): array {
    if (!$this->moduleHandler->moduleExists('pathauto')) {
      return [];
    }
    $patterns = $this->entityTypeManager->getStorage('pathauto_pattern')->loadByProperties([
      'type' => "canonical_entities:$entity_type_id",
      'status' => TRUE,
    ]);
    uasort($patterns, fn ($a, $b) => $a->getWeight() <=> $b->getWeight());
    $result = [];
    foreach ($patterns as $pattern) {
      $bundles = [];
      foreach ($pattern->getSelectionConditions() as $condition) {
        $bundles = array_merge($bundles, array_keys($condition->getConfiguration()['bundles'] ?? []));
      }
      if (!$bundles || in_array($bundle, array_map('strval', $bundles), TRUE)) {
        $result[] = $pattern->getPattern() . ($bundles ? '' : ' (' . $this->t('default') . ')');
      }
    }
    return $result;
  }

  /**
   * Adds a row for each subfield of a custom field (custom_field module).
   *
   * Subfields' settings are spread over the field storage ('columns': type
   * and length), the field ('field_settings': label, help text, required,
   * allowed values), and the form and view displays ('fields': widget and
   * formatter).
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field
   *   The custom field.
   * @param array|null $widget
   *   The field's form display component, or NULL if it's hidden.
   * @param array|null $formatter
   *   The field's view display component, or NULL if it's hidden.
   * @param \Drupal\reports\ReportWriter $writer
   *   The report writer.
   * @param string $sheet
   *   The sheet ID.
   */
  protected function addCustomFieldRows(FieldDefinitionInterface $field, ?array $widget, ?array $formatter, ReportWriter $writer, string $sheet): void {
    $field_settings = $field->getSetting('field_settings') ?? [];
    foreach ($field->getFieldStorageDefinition()->getSetting('columns') ?? [] as $name => $column) {
      $settings = $field_settings[$name] ?? [];
      $widget_settings = $widget['settings']['fields'][$name] ?? NULL;
      $formatter_type = $formatter['settings']['fields'][$name]['format_type'] ?? NULL;
      $values = array_map(fn ($value) => "{$value['key']}: {$value['label']}", array_filter($settings['allowed_values'] ?? [], fn ($value) => isset($value['key'], $value['label'])));
      $limits = array_filter([
        !empty($column['length']) ? $this->t('@count (storage)', ['@count' => $column['length']]) : NULL,
        !empty($widget_settings['maxlength']) ? $this->t('@count (widget)', ['@count' => $widget_settings['maxlength']]) : NULL,
      ]);
      $writer->addRow([
        'label' => $field->getLabel() . ': ' . ($settings['label'] ?? $name),
        'name' => $field->getName() . '.' . $name,
        'type' => $this->getPluginLabel($this->customFieldTypeManager, $column['type']),
        'values' => implode('; ', $values),
        'required' => !empty($settings['required']) ? $this->t('Yes') : $this->t('No'),
        'widget' => $widget_settings ? $this->getPluginLabel($this->customFieldWidgetManager, $widget_settings['type'] ?? '') : $this->t('Hidden'),
        'formatter' => $formatter_type ? $this->getPluginLabel($this->customFieldFormatterManager, $formatter_type) : $this->t('Hidden'),
        'character_limit' => implode(', ', array_map('strval', $limits)),
        'description' => $settings['description'] ?? '',
        'translatable' => !empty($settings['translatable']) ? $this->t('Yes') : $this->t('No'),
      ], $sheet);
    }
  }

  /**
   * Gets the label of a plugin, or its ID if it has none.
   */
  protected function getPluginLabel(?PluginManagerInterface $manager, string $id): string {
    return (string) ($manager?->getDefinition($id, FALSE)['label'] ?? $id);
  }

  /**
   * Describes the values a field accepts.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field
   *   The field.
   *
   * @return string
   *   Allowed values of list fields ('key: label', separated by semicolons),
   *   the target type and bundles of references, the allowed extensions of
   *   files and images, or the minimum and maximum of numbers. Empty for
   *   other fields.
   */
  protected function getAllowedValues(FieldDefinitionInterface $field): string {
    $settings = $field->getSettings();
    if (isset($settings['allowed_values']) && is_array($settings['allowed_values'])) {
      return implode('; ', array_map(fn ($key, $label) => "$key: $label", array_keys($settings['allowed_values']), $settings['allowed_values']));
    }
    if (isset($settings['target_type'])) {
      $target_type = $this->entityTypeManager->getDefinition($settings['target_type'], FALSE);
      $bundles = $settings['handler_settings']['target_bundles'] ?? [];
      $bundle_labels = array_map(fn ($bundle) => $this->bundleInfo->getBundleInfo($settings['target_type'])[$bundle]['label'] ?? $bundle, array_values($bundles ?: []));
      return ($target_type?->getLabel() ?? $settings['target_type']) . ($bundle_labels ? ': ' . implode(', ', $bundle_labels) : '');
    }
    if (!empty($settings['file_extensions'])) {
      return (string) $settings['file_extensions'];
    }
    $range = array_filter([
      isset($settings['min']) && $settings['min'] !== '' ? $this->t('Minimum @min', ['@min' => $settings['min']]) : NULL,
      isset($settings['max']) && $settings['max'] !== '' ? $this->t('Maximum @max', ['@max' => $settings['max']]) : NULL,
    ]);
    return implode(', ', array_map('strval', $range));
  }

  /**
   * Gets a field's character limit.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field
   *   The field.
   * @param array|null $widget
   *   The field's form display component, or NULL if it's hidden.
   *
   * @return string
   *   The storage's maximum length (e.g. for plain text fields) and the
   *   Maxlength module's limit on the widget, if any.
   */
  protected function getCharacterLimit(FieldDefinitionInterface $field, ?array $widget): string {
    $limits = [];
    if ($max_length = $field->getFieldStorageDefinition()->getSetting('max_length')) {
      $limits[] = $this->t('@count (storage)', ['@count' => $max_length]);
    }
    if ($maxlength = $widget['third_party_settings']['maxlength']['maxlength_js'] ?? NULL) {
      $limits[] = $this->t('@count (Maxlength)', ['@count' => $maxlength]);
    }
    return implode(', ', array_map('strval', $limits));
  }

  /**
   * Gets the bundles of the configured entity types.
   *
   * @return list<array{0: string, 1: string, 2: string}>
   *   The entity type ID, bundle and label of each bundle.
   */
  protected function getBundles(): array {
    $bundles = [];
    foreach ($this->configuration['entity_types'] as $entity_type_id) {
      $entity_type = $this->entityTypeManager->getDefinition($entity_type_id, FALSE);
      if (!$entity_type?->entityClassImplements(ContentEntityInterface::class)) {
        continue;
      }
      foreach ($this->bundleInfo->getBundleInfo($entity_type_id) as $bundle => $info) {
        $bundles[] = [$entity_type_id, (string) $bundle, $entity_type->getLabel() . ': ' . $info['label']];
      }
    }
    return $bundles;
  }

}
