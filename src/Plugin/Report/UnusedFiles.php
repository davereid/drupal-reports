<?php

declare(strict_types=1);

namespace Drupal\reports\Plugin\Report;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\Core\StringTranslation\ByteSizeMarkup;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\reports\Attribute\Report;
use Drupal\reports\ReportWriter;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lists files that nothing uses.
 *
 * A file is unused when it has no file usage records: no field, media item or
 * text (with the Editor module) references it.
 */
#[Report(
  id: 'unused_files',
  label: new TranslatableMarkup('Unused files'),
  description: new TranslatableMarkup('Files that no content, media or other file usage references.'),
  // It lists every file, including private ones, like core's file overview.
  permission: 'access site reports,access files overview',
)]
class UnusedFiles extends ReportBase implements GeneratedReportInterface, PluginFormInterface {

  /**
   * The database connection.
   */
  protected Connection $database;

  /**
   * The file URL generator.
   */
  protected FileUrlGeneratorInterface $fileUrlGenerator;

  /**
   * The date formatter.
   */
  protected DateFormatterInterface $dateFormatter;

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->database = $container->get('database');
    $instance->fileUrlGenerator = $container->get('file_url_generator');
    $instance->dateFormatter = $container->get('date.formatter');
    $instance->entityTypeManager = $container->get('entity_type.manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return ['temporary' => FALSE];
  }

  /**
   * {@inheritdoc}
   */
  public function setConfiguration(array $configuration): void {
    parent::setConfiguration($configuration);
    $this->configuration['temporary'] = (bool) $this->configuration['temporary'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form['temporary'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Include temporary files'),
      '#description' => $this->t('Temporary files are deleted automatically on cron after some time.'),
      '#default_value' => $this->configuration['temporary'],
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
   */
  public function getHeader(string $sheet = self::SHEET_DEFAULT): array {
    return [
      'filename' => ['label' => $this->t('File name'), 'url' => 'file_url'],
      'id' => $this->t('ID'),
      'file_url' => ['label' => $this->t('File URL'), 'hidden' => TRUE],
      'mime' => ['label' => $this->t('MIME type'), 'filter' => 'list'],
      'size' => $this->t('Size'),
      'bytes' => ['label' => $this->t('Bytes'), 'hidden' => TRUE],
      'status' => ['label' => $this->t('Status'), 'filter' => 'list'],
      'created' => $this->t('Uploaded'),
      'edit_url' => $this->t('Delete URL'),
    ];
  }

  /**
   * {@inheritdoc}
   *
   * File usage changes don't invalidate any cache tag, so this only covers
   * files being added, changed or deleted.
   */
  public function getCacheTags(): array {
    return ['file_list'];
  }

  /**
   * {@inheritdoc}
   */
  public function getBatchOperations(): array {
    return [['processFiles']];
  }

  /**
   * Batch operation: lists the next files without usage.
   *
   * @param \Drupal\reports\ReportWriter $writer
   *   The report writer.
   * @param array|\ArrayAccess $context
   *   The batch context.
   */
  public function processFiles(ReportWriter $writer, &$context): void {
    $limit = $this->getBatchSize();
    if (!isset($context['sandbox']['last_fid'])) {
      $context['sandbox']['last_fid'] = 0;
      $context['sandbox']['done'] = 0;
      $context['sandbox']['total'] = (int) $this->getQuery()->countQuery()->execute()->fetchField();
    }
    // Paged by file ID, so deleting files meanwhile doesn't skip any.
    $files = $this->getQuery()
      ->fields('f', ['fid', 'filename', 'uri', 'filemime', 'filesize', 'status', 'created'])
      ->condition('f.fid', $context['sandbox']['last_fid'], '>')
      ->orderBy('f.fid')
      ->range(0, $limit)
      ->execute()
      ->fetchAll();
    // Core's file delete form falls back to the file's canonical URL for its
    // Cancel link, which files only have with modules like File Entity, so
    // without one it needs a destination.
    $query = $this->entityTypeManager->getDefinition('file')->hasLinkTemplate('canonical')
      ? []
      : ['destination' => $this->toUrl()->toString()];
    foreach ($files as $file) {
      $writer->addRow([
        'filename' => $file->filename,
        'id' => $file->fid,
        'file_url' => $this->fileUrlGenerator->generateAbsoluteString($file->uri),
        'mime' => $file->filemime,
        'size' => ByteSizeMarkup::create((int) $file->filesize),
        'bytes' => $file->filesize,
        'status' => $file->status ? $this->t('Permanent') : $this->t('Temporary'),
        'created' => $this->dateFormatter->format((int) $file->created, 'short'),
        'edit_url' => Url::fromRoute('entity.file.delete_form', ['file' => $file->fid], ['query' => $query]),
      ]);
      $context['sandbox']['last_fid'] = $file->fid;
    }
    $context['sandbox']['done'] += count($files);
    $total = max(1, $context['sandbox']['total']);
    $context['finished'] = count($files) < $limit ? 1 : min(1, $context['sandbox']['done'] / $total);
    $context['message'] = $this->t('Checked @count of @total files.', [
      '@count' => $context['sandbox']['done'],
      '@total' => $context['sandbox']['total'],
    ]);
  }

  /**
   * Builds the query for files without usage.
   */
  protected function getQuery(): SelectInterface {
    $query = $this->database->select('file_managed', 'f');
    $query->leftJoin('file_usage', 'u', '[u].[fid] = [f].[fid]');
    $query->isNull('u.fid');
    if (!$this->configuration['temporary']) {
      $query->condition('f.status', 1);
    }
    return $query;
  }

}
