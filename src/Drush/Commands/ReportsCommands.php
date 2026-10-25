<?php

declare(strict_types=1);

namespace Drupal\reports\Drush\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Url;
use Drupal\reports\Plugin\Report\GeneratedReportInterface;
use Drupal\reports\Plugin\Report\ReportInterface;
use Drupal\reports\ReportGenerator;
use Drupal\reports\ReportManager;
use Drupal\reports\ReportStorage;
use Drupal\reports\ReportWriter;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for reports.
 */
class ReportsCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    protected readonly ReportManager $reportManager,
    protected readonly ReportStorage $reportStorage,
    protected readonly ReportGenerator $reportGenerator,
  ) {
    parent::__construct();
  }

  /**
   * Lists the available reports.
   */
  #[CLI\Command(name: 'reports:list')]
  #[CLI\FieldLabels(labels: ['id' => 'ID', 'label' => 'Label', 'type' => 'Type', 'status' => 'Status'])]
  public function list(array $options = ['format' => 'table']): RowsOfFields {
    $rows = [];
    foreach ($this->reportManager->getDefinitions() as $id => $definition) {
      $report = $this->reportManager->createInstance($id);
      $status = $report instanceof GeneratedReportInterface ? $this->reportStorage->getStatus($report) : NULL;
      $rows[$id] = [
        'id' => $id,
        'label' => (string) $definition['label'],
        'type' => $report instanceof GeneratedReportInterface ? 'generated' : 'realtime',
        'status' => isset($status['completed']) ? date('Y-m-d H:i:s', $status['completed']) . ($status['status'] === 'complete' ? '' : " ({$status['status']})") : ($status['status'] ?? ''),
      ];
    }
    return new RowsOfFields($rows);
  }

  /**
   * Outputs a report.
   *
   * Generated reports are read from their stored file. If it's missing, out
   * of date or failed, you're asked whether to regenerate it. Download-only
   * reports aren't output: the command gives their download URL instead.
   */
  #[CLI\Command(name: 'reports:run')]
  #[CLI\Argument(name: 'id', description: 'The report ID.')]
  #[CLI\Option(name: 'config', description: 'Report configuration as key=value. Repeat for several keys; separate list values with commas.')]
  #[CLI\Option(name: 'sheet', description: 'The sheet to output, for reports with several sheets.')]
  #[CLI\Option(name: 'generate', description: 'Regenerate a generated report first.')]
  #[CLI\Usage(name: 'drush reports:run absolute_urls --config=domains=example.com --format=csv', description: 'Outputs the absolute URLs to example.com as CSV.')]
  public function run(string $id, array $options = ['config' => [], 'sheet' => 'default', 'generate' => FALSE, 'format' => 'table']): ?RowsOfFields {
    $report = $this->createReport($id, $options['config']);
    $download_only = !empty($report->getPluginDefinition()['downloadOnly']);
    $sheets = $report->getSheets();
    // Download-only reports check their first sheet's file.
    if ($download_only) {
      $options['sheet'] = array_key_first($sheets);
    }
    if (!isset($sheets[$options['sheet']])) {
      throw new \InvalidArgumentException(dt('The report has no sheet named @sheet. Sheets: @sheets', ['@sheet' => $options['sheet'], '@sheets' => implode(', ', array_keys($sheets))]));
    }

    if ($errors = $this->getRequirementErrors($report)) {
      throw new \RuntimeException($errors);
    }
    if ($report instanceof GeneratedReportInterface) {
      $status = $this->reportStorage->getStatus($report);
      $exists = $download_only ? $this->reportStorage->hasFiles($report) : $this->reportStorage->getFile($report, $options['sheet']) !== NULL;
      $outdated = !$exists || $this->reportStorage->isStale($report) || $this->reportStorage->hasChanged($report) || ($status['status'] ?? NULL) === 'failed';
      if ($options['generate'] || ($outdated && $this->input()->isInteractive() && $this->io()->confirm(dt('The report is missing or out of date. Regenerate it now?')))) {
        $this->generate($id, ['config' => $options['config'], 'all' => FALSE]);
      }
      elseif (!$exists) {
        throw new \RuntimeException(dt('The report has not been generated. Use --generate to generate it.'));
      }
      elseif ($outdated) {
        $this->logger()->warning(dt('The report was generated @date and may be out of date.', ['@date' => date('Y-m-d H:i:s', $status['completed'])]));
      }
    }

    if ($download_only) {
      $query = $report->getConfiguration() ? ['config' => $report->getConfiguration()] : [];
      $route = $report instanceof GeneratedReportInterface && $this->reportStorage->getSpreadsheetFile($report) ? 'reports.report.xlsx' : 'reports.report';
      $this->logger()->success(dt('@report is download only. Download it from: @url', [
        '@report' => $report->getPluginDefinition()['label'],
        '@url' => Url::fromRoute($route, ['report' => $id], ['query' => $query, 'absolute' => TRUE])->toString(),
      ]));
      return NULL;
    }

    $header = $sheets[$options['sheet']]['header'];
    $rows = [];
    foreach ($this->reportStorage->getRows($report, $options['sheet']) as $row) {
      $rows[] = array_combine(array_keys($header), ReportWriter::normalizeRow($header, $row));
    }
    // Columns are labeled with their keys, which --fields also uses.
    return new RowsOfFields($rows);
  }

  /**
   * Generates a report, or all generated reports.
   */
  #[CLI\Command(name: 'reports:generate')]
  #[CLI\Argument(name: 'id', description: 'The report ID. Leave out with --all.')]
  #[CLI\Option(name: 'config', description: 'Report configuration as key=value. Repeat for several keys; separate list values with commas.')]
  #[CLI\Option(name: 'all', description: 'Generate every generated report, with its default configuration.')]
  #[CLI\Usage(name: 'drush reports:generate --all', description: 'Generates every generated report with its default options.')]
  public function generate(?string $id = NULL, array $options = ['config' => [], 'all' => FALSE]): void {
    if ($options['all']) {
      if ($id !== NULL || $options['config']) {
        throw new \InvalidArgumentException(dt('--all generates every report with its default options, so leave out the report ID and --config.'));
      }
      $reports = array_filter(array_map(fn ($id) => $this->reportManager->createInstance($id), array_keys($this->reportManager->getDefinitions())), fn ($report) => $report instanceof GeneratedReportInterface && $report->isApplicable());
    }
    elseif ($id === NULL) {
      throw new \InvalidArgumentException(dt('Give a report ID, or use --all.'));
    }
    else {
      $reports = [$this->createReport($id, $options['config'])];
      if (!$reports[0] instanceof GeneratedReportInterface) {
        throw new \InvalidArgumentException(dt('The @id report is not a generated report.', ['@id' => $id]));
      }
    }
    // Reports whose requirements aren't met are skipped with --all.
    foreach ($reports as $key => $report) {
      if ($errors = $this->getRequirementErrors($report)) {
        if (!$options['all']) {
          throw new \RuntimeException($errors);
        }
        $this->logger()->warning(dt('Skipping @report: @errors', ['@report' => $report->getPluginDefinition()['label'], '@errors' => $errors]));
        unset($reports[$key]);
      }
    }
    // Each report is its own batch set, run one after another.
    foreach ($reports as $report) {
      $this->logger()->notice(dt('Generating @report.', ['@report' => $report->getPluginDefinition()['label']]));
      batch_set($this->reportGenerator->buildBatch($report));
    }
    if ($reports) {
      drush_backend_batch_process();
    }
  }

  /**
   * Deletes old and abandoned generated report files.
   *
   * Cron does the same. Files are deleted once they're older than the
   * delete_after setting.
   */
  #[CLI\Command(name: 'reports:clean')]
  public function clean(): void {
    $this->logger()->success(dt('Deleted @count generated report files.', ['@count' => $this->reportStorage->deleteExpired()]));
  }

  /**
   * Describes a report's unmet requirements that prevent using it.
   *
   * @return string
   *   The errors' titles and descriptions, or an empty string if it's usable.
   */
  protected function getRequirementErrors(ReportInterface $report): string {
    $errors = [];
    foreach ($this->reportManager->getRequirements($report) as $requirement) {
      if ($requirement['severity'] === RequirementSeverity::Error) {
        $errors[] = $requirement['title'] . ': ' . strip_tags((string) ($requirement['description'] ?? ''));
      }
    }
    return implode(' ', $errors);
  }

  /**
   * Creates a report with configuration from key=value options.
   */
  protected function createReport(string $id, array $config): ReportInterface {
    if (!$this->reportManager->hasDefinition($id)) {
      throw new \InvalidArgumentException(dt('There is no report named @id.', ['@id' => $id]));
    }
    $configuration = [];
    foreach ($config as $pair) {
      [$key, $value] = explode('=', $pair, 2) + [1 => ''];
      $configuration[$key] = $value;
    }
    return $this->reportManager->createInstance($id, $configuration);
  }

}
