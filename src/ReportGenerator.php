<?php

declare(strict_types=1);

namespace Drupal\reports;

use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\reports\Plugin\Report\GeneratedReportInterface;

/**
 * Generates reports with the Batch API.
 */
class ReportGenerator {

  use StringTranslationTrait;

  public function __construct(
    protected readonly ReportStorage $storage,
  ) {}

  /**
   * Builds the batch that generates a report.
   *
   * Sets the report's status to generating, so only call it when the batch
   * will be run.
   *
   * @throws \Drupal\reports\Exception\ReportGeneratingException
   *   If the report is already being generated with the same options.
   *
   * @param \Drupal\reports\Plugin\Report\GeneratedReportInterface $report
   *   The report, with its configuration.
   *
   * @return array
   *   The batch, for batch_set().
   */
  public function buildBatch(GeneratedReportInterface $report): array {
    $this->storage->start($report);
    $id = $report->getPluginId();
    $configuration = $report->getConfiguration();
    $batch = (new BatchBuilder())
      ->setTitle($this->t('Generating %report', ['%report' => $report->getPluginDefinition()['label']]))
      ->setFinishCallback([static::class, 'finishBatch']);
    foreach ($report->getBatchOperations() as $operation) {
      $batch->addOperation([static::class, 'runOperation'], [$id, $configuration, $operation[0], $operation[1] ?? []]);
    }
    $batch->addOperation([static::class, 'completeReport'], [$id, $configuration]);
    return $batch->toArray();
  }

  /**
   * Batch operation: runs one of the report's operations.
   *
   * @param string $id
   *   The report plugin ID.
   * @param array $configuration
   *   The report configuration.
   * @param string $method
   *   The report method to call.
   * @param array $arguments
   *   The arguments of the method.
   * @param array|\ArrayAccess $context
   *   The batch context.
   */
  public static function runOperation(string $id, array $configuration, string $method, array $arguments, &$context): void {
    $context['results']['report'] = [$id, $configuration];
    $report = static::createReport($id, $configuration);
    $storage = \Drupal::service(ReportStorage::class);
    $storage->keepGenerating($report);
    $arguments[] = new ReportWriter($storage->getTemporaryDirectory($report), $report->getSheets());
    $arguments[] = &$context;
    $report->{$method}(...$arguments);
  }

  /**
   * Batch operation: replaces the stored report with the generated one.
   *
   * @param string $id
   *   The report plugin ID.
   * @param array $configuration
   *   The report configuration.
   * @param array|\ArrayAccess $context
   *   The batch context.
   */
  public static function completeReport(string $id, array $configuration, &$context): void {
    $report = static::createReport($id, $configuration);
    $storage = \Drupal::service(ReportStorage::class);
    $storage->writeSpreadsheet($report);
    $storage->finish($report, TRUE);
    $context['results']['completed'] = TRUE;
  }

  /**
   * Batch finished callback.
   *
   * @param bool $success
   *   Whether the batch completed without errors.
   * @param array $results
   *   The results set by the operations.
   */
  public static function finishBatch(bool $success, array $results): void {
    if (!empty($results['completed'])) {
      \Drupal::messenger()->addStatus(t('The report has been generated.'));
      return;
    }
    if (isset($results['report'])) {
      \Drupal::service(ReportStorage::class)->finish(static::createReport(...$results['report']), FALSE);
    }
    \Drupal::messenger()->addError(t('The report could not be generated.'));
  }

  /**
   * Creates a generated report.
   */
  protected static function createReport(string $id, array $configuration): GeneratedReportInterface {
    $report = \Drupal::service(ReportManager::class)->createInstance($id, $configuration);
    assert($report instanceof GeneratedReportInterface);
    return $report;
  }

}
