<?php

declare(strict_types=1);

namespace Drupal\reports\Controller;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Link;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\reports\Exception\ReportGeneratingException;
use Drupal\reports\Form\ReportConfigForm;
use Drupal\reports\Plugin\Report\GeneratedReportInterface;
use Drupal\reports\Plugin\Report\ReportInterface;
use Drupal\reports\ReportGenerator;
use Drupal\reports\ReportManager;
use Drupal\reports\ReportStorage;
use Drupal\reports\ReportWriter;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Displays reports.
 */
class ReportController extends ControllerBase {

  /**
   * The report plugin manager.
   */
  protected ReportManager $reportManager;

  /**
   * The report storage.
   */
  protected ReportStorage $reportStorage;

  /**
   * The report generator.
   */
  protected ReportGenerator $reportGenerator;

  /**
   * The date formatter.
   */
  protected DateFormatterInterface $dateFormatter;

  /**
   * The renderer.
   */
  protected RendererInterface $renderer;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->reportManager = $container->get(ReportManager::class);
    $instance->reportStorage = $container->get(ReportStorage::class);
    $instance->reportGenerator = $container->get(ReportGenerator::class);
    $instance->dateFormatter = $container->get('date.formatter');
    $instance->renderer = $container->get('renderer');
    return $instance;
  }

  /**
   * Displays a report.
   */
  public function view(Request $request, ReportInterface $report): array {
    $definition = $report->getPluginDefinition();
    $sheets = $report->getSheets();
    $sheet = $this->getSheet($request, $report);
    $build = ['#cache' => ['max-age' => 0]];

    if ($summary = $report->getSummary()) {
      $build['summary'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['reports-summary']],
        '#attached' => ['library' => ['reports/summary']],
        'summary' => $summary,
      ];
    }

    // Errors stop the report; warnings are shown with it. The options form
    // is still shown, so errors caused by the options can be fixed.
    $this->addRequirementMessages($this->reportManager->getRequirements($report));
    if ($report instanceof PluginFormInterface) {
      $build['config'] = $this->formBuilder()->getForm(ReportConfigForm::class, $report, $sheet);
    }
    if (!$this->reportManager->isUsable($report)) {
      return $build;
    }

    if ($report instanceof GeneratedReportInterface) {
      $build['status'] = $this->buildGeneratedStatus($report);
      if ($this->reportStorage->isDownloadOnly($report) ? !$this->reportStorage->hasFiles($report) : !$this->reportStorage->getFile($report, $sheet)) {
        return $build;
      }
    }

    if (!empty($definition['downloadOnly'])) {
      // Just generated: start the download.
      if ($request->query->getBoolean('download') && $report instanceof GeneratedReportInterface && $this->reportStorage->hasFiles($report)) {
        $build['#attached']['library'][] = 'reports/download';
        $build['#attached']['drupalSettings']['reports']['download'] = $report->toUrl('xlsx')->toString();
      }
      return $build + $this->buildDownloads($report);
    }

    // Reports that replace routes use those routes' local tasks as tabs.
    if (count($sheets) > 1 && !$this->reportManager->getReplacedRoutes($report->getPluginId())) {
      $links = [];
      foreach ($sheets as $id => $label) {
        $links[$id] = [
          'title' => $label,
          'url' => $report->toUrl('canonical', ['query' => ['sheet' => $id]]),
        ];
      }
      $build['sheets'] = ['#theme' => 'links', '#links' => $links, '#attributes' => ['class' => ['tabs', 'secondary']]];
    }

    // Reports with options have the downloads in their form's actions. Other
    // reports get a row of actions like it: (re)generate and downloads.
    if (!$report instanceof PluginFormInterface) {
      $build['actions'] = ['#type' => 'container', '#attributes' => ['class' => ['form-actions']]];
      if (isset($build['status']['generate'])) {
        $build['actions']['generate'] = $build['status']['generate'];
        unset($build['status']['generate']);
      }
      $build['actions']['download'] = Link::fromTextAndUrl($this->t('Download CSV'), $report->toUrl('csv', ['query' => ['sheet' => $sheet]]))->toRenderable();
      $build['actions']['download']['#attributes']['class'] = ['button'];
      $build['actions']['download_xlsx'] = Link::fromTextAndUrl($this->t('Download Excel file'), $report->toUrl('xlsx'))->toRenderable();
      $build['actions']['download_xlsx']['#attributes']['class'] = ['button'];
    }
    // The report's own operations, as buttons.
    foreach ($report->getOperations() as $name => $operation) {
      $build['operations'][$name] = Link::fromTextAndUrl($operation['title'], $operation['url'])->toRenderable() + [
        '#attributes' => ['class' => ['button', 'button--small']],
        '#weight' => $operation['weight'] ?? 0,
        '#access' => $operation['url']->access(),
      ];
    }

    $rows = $this->reportStorage->getRows($report, $sheet);
    if ($definition['type'] === 'status') {
      $requirements = [];
      foreach ($rows as $row) {
        $requirements[$row['key']] = [
          'title' => $row['label'],
          'value' => $row['status'] ?? '',
          'description' => $row['description'] ?? '',
          'severity' => $row['severity'] ?? NULL,
        ];
      }
      $build['report'] = ['#type' => 'status_report', '#requirements' => $requirements];
      return $build;
    }

    $header = array_map(fn ($column) => is_array($column) ? $column : ['label' => $column], $report->getHeader($sheet));
    $visible = array_filter($header, fn ($column) => empty($column['hidden']));
    $table_rows = [];
    // shortcut: renders every row for DataTables, switch to DataTables'
    // server-side processing if reports get too large for one page.
    foreach ($rows as $row) {
      $cells = [];
      foreach ($visible as $key => $column) {
        $url = isset($column['url']) ? $row[$column['url']] ?? NULL : NULL;
        $cells[] = ['data' => $this->buildCell($row[$key] ?? NULL, $url)];
      }
      $table_rows[] = $cells;
    }
    $build['report'] = [
      '#type' => 'table',
      '#header' => array_values(array_map(fn ($column) => [
        'data' => $column['label'],
        'data-reports-filter' => $column['filter'] ?? FALSE ?: 'none',
      ], $visible)),
      '#rows' => $table_rows,
      '#empty' => $this->t('No results.'),
    ];
    // DataTables' search, filters and paging are only useful with rows.
    if ($table_rows) {
      $build['report']['#attributes']['class'][] = 'reports-table';
      // The base of the filtered download's file name; the script adds the
      // filters.
      $build['report']['#attributes']['data-reports-filename'] = str_replace(':', '-', $report->getPluginId()) . ($sheet === ReportInterface::SHEET_DEFAULT ? '' : "-$sheet");
      $build['report']['#attached']['library'][] = 'reports/report';
    }
    return $build;
  }

  /**
   * Builds the downloads of a download-only report.
   *
   * An Excel file of all sheets, and a table of the sheets with their number
   * of rows, as counted when the report was generated.
   */
  protected function buildDownloads(ReportInterface $report): array {
    $build = [];
    // Reports with options have the download in their form's actions.
    if (!$report instanceof PluginFormInterface && $report instanceof GeneratedReportInterface && $this->reportStorage->getSpreadsheetFile($report)) {
      $build['download_xlsx'] = Link::fromTextAndUrl($this->t('Download Excel file'), $report->toUrl('xlsx'))->toRenderable();
      $build['download_xlsx']['#attributes']['class'] = ['button', 'button--primary'];
    }
    $counts = $report instanceof GeneratedReportInterface ? $this->reportStorage->getStatus($report)['rows'] ?? [] : [];
    $rows = [];
    foreach ($report->getSheets() as $id => $label) {
      $rows[] = [$label, $counts[$id] ?? ''];
    }
    $build['sheets'] = [
      '#type' => 'table',
      '#header' => [$this->t('Sheet'), $this->t('Rows')],
      '#rows' => $rows,
      '#attributes' => ['class' => ['reports-summary']],
      '#attached' => ['library' => ['reports/summary']],
    ];
    return $build;
  }

  /**
   * Downloads a report sheet as a CSV file.
   */
  public function csv(Request $request, ReportInterface $report): Response {
    $sheet = $this->getSheet($request, $report);
    $filename = str_replace(':', '-', $report->getPluginId()) . ($sheet === ReportInterface::SHEET_DEFAULT ? '' : "-$sheet") . '.csv';

    if ($report instanceof GeneratedReportInterface) {
      $uri = $this->reportStorage->getFile($report, $sheet) ?? throw new NotFoundHttpException();
      $response = new BinaryFileResponse($uri, 200, ['Content-Type' => 'text/csv; charset=utf-8'], FALSE);
    }
    else {
      $response = new StreamedResponse(function () use ($report, $sheet): void {
        $header = $report->getHeader($sheet);
        $handle = fopen('php://output', 'w');
        ReportWriter::writeCsvRow($handle, ReportWriter::getLabels($header));
        foreach ($this->reportStorage->getRows($report, $sheet) as $row) {
          ReportWriter::writeCsvRow($handle, ReportWriter::normalizeRow($header, $row));
        }
        fclose($handle);
      }, 200, ['Content-Type' => 'text/csv; charset=utf-8']);
    }
    $response->headers->set('Content-Disposition', $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $filename));
    return $response;
  }

  /**
   * Downloads all sheets of a generated report as an Excel file.
   */
  public function xlsx(Request $request, ReportInterface $report): Response {
    $headers = ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
    $uri = $report instanceof GeneratedReportInterface ? $this->reportStorage->getSpreadsheetFile($report) : NULL;
    if ($uri) {
      $response = new BinaryFileResponse($uri, 200, $headers, FALSE);
    }
    elseif ($report instanceof GeneratedReportInterface && !$this->reportStorage->hasFiles($report)) {
      throw new NotFoundHttpException();
    }
    else {
      // Reports without a stored spreadsheet are converted as they're sent.
      $response = new StreamedResponse(function () use ($report): void {
        $writer = new XlsxWriter();
        $writer->openToFile('php://output');
        $names = [];
        foreach ($report->getSheets() as $id => $label) {
          $header = $report->getHeader((string) $id);
          $sheet = $names ? $writer->addNewSheetAndMakeItCurrent() : $writer->getCurrentSheet();
          $sheet->setName($names[] = ReportStorage::getSheetName((string) $label, $names));
          $writer->addRow(Row::fromValues(array_map(ReportWriter::normalizeCell(...), array_values(ReportWriter::getLabels($header)))));
          foreach ($this->reportStorage->getRows($report, $id) as $row) {
            $writer->addRow(ReportWriter::toSpreadsheetRow($header, ReportWriter::normalizeRow($header, $row)));
          }
        }
        $writer->close();
      }, 200, $headers);
    }
    $response->headers->set('Content-Disposition', $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, str_replace(':', '-', $report->getPluginId()) . '.xlsx'));
    return $response;
  }

  /**
   * Generates a report with the Batch API.
   */
  public function generate(Request $request, ReportInterface $report): Response|array {
    assert($report instanceof GeneratedReportInterface);
    try {
      batch_set($this->reportGenerator->buildBatch($report));
    }
    catch (ReportGeneratingException $exception) {
      $this->messenger()->addError($exception->getMessage());
      return new RedirectResponse($report->toUrl()->setAbsolute()->toString());
    }
    // Download-only reports are downloaded when the page loads.
    $download = $this->reportStorage->isDownloadOnly($report) ? ['download' => 1] : [];
    return batch_process($report->toUrl('canonical', ['query' => $download]));
  }

  /**
   * Gets the title of a report.
   */
  public function title(ReportInterface $report): string|\Stringable {
    return $report->getPluginDefinition()['label'];
  }

  /**
   * Checks access to a report.
   */
  public function access(AccountInterface $account, ReportInterface $report): AccessResultInterface {
    return $report->access($account);
  }

  /**
   * Checks access to generating a report.
   */
  public function generateAccess(AccountInterface $account, ReportInterface $report): AccessResultInterface {
    return AccessResult::allowedIf($report instanceof GeneratedReportInterface && $this->reportManager->isUsable($report))
      ->andIf($report->access($account));
  }

  /**
   * Gets the requested sheet of a report.
   */
  protected function getSheet(Request $request, ReportInterface $report): string {
    // Routes the report replaces set the sheet they show.
    $sheet = (string) $request->attributes->get('sheet', $request->query->get('sheet', ''));
    return isset($report->getSheets()[$sheet]) ? $sheet : array_key_first($report->getSheets());
  }

  /**
   * Builds a table cell, turning URLs into links.
   *
   * @param mixed $cell
   *   The cell.
   * @param mixed $url
   *   (optional) A URL to link the cell's text to: a \Drupal\Core\Url, or an
   *   absolute URL string as stored for generated reports.
   */
  protected function buildCell(mixed $cell, mixed $url = NULL): mixed {
    if (is_string($url) && $url !== '' && UrlHelper::isValid($url, TRUE)) {
      $url = Url::fromUri($url);
    }
    if ($url instanceof Url && !$cell instanceof Link) {
      return Link::fromTextAndUrl(ReportWriter::normalizeCell($cell), $url)->toRenderable();
    }
    return match (TRUE) {
      $cell instanceof Link => $cell->toRenderable(),
      $cell instanceof Url => Link::fromTextAndUrl($cell->setAbsolute()->toString(), $cell)->toRenderable(),
      // Generated reports only have strings, so link absolute URLs.
      is_string($cell) && preg_match('#^https?://#', $cell) && UrlHelper::isValid($cell, TRUE) => Link::fromTextAndUrl($cell, Url::fromUri($cell))->toRenderable(),
      default => ['#plain_text' => ReportWriter::normalizeCell($cell)],
    };
  }

  /**
   * Builds the status of a generated report, with a link to (re)generate it.
   */
  protected function buildGeneratedStatus(GeneratedReportInterface $report): array {
    $status = $this->reportStorage->getStatus($report);
    $stale = $this->reportStorage->isStale($report) || $this->reportStorage->hasChanged($report);
    $build = [
      '#type' => 'container',
      'status' => ['#markup' => $this->describeStatus($report), '#prefix' => '<p>', '#suffix' => '</p>'],
    ];
    if ($stale) {
      $build['status']['#prefix'] = '<div class="messages messages--warning">';
      $build['status']['#suffix'] = ' ' . $this->t('It may be out of date.') . '</div>';
    }
    $build['generate'] = Link::fromTextAndUrl(
      match (TRUE) {
        isset($status['completed']) => $this->t('Regenerate report'),
        // Generating a download-only report also downloads it.
        $this->reportStorage->isDownloadOnly($report) => $this->t('Download report'),
        default => $this->t('Generate report'),
      },
      $report->toUrl('generate'),
    )->toRenderable();
    $build['generate']['#attributes']['class'] = $stale || empty($status['completed']) ? ['button', 'button--primary'] : ['button'];
    // Reports with options have View and Regenerate buttons in their form.
    $build['generate']['#access'] = !$report instanceof PluginFormInterface && $this->generateAccess($this->currentUser(), $report)->isAllowed();
    return $build;
  }

  /**
   * Shows a report's errors and warnings as status messages.
   *
   * @param array $requirements
   *   Requirements, as from ReportManager::getRequirements().
   */
  protected function addRequirementMessages(array $requirements): void {
    foreach ($requirements as $requirement) {
      $description = $requirement['description'] ?? '';
      $message = $this->t('<strong>@title:</strong> @description', [
        '@title' => $requirement['title'],
        '@description' => is_array($description) ? $this->renderer->renderInIsolation($description) : $description,
      ]);
      match ($requirement['severity']) {
        RequirementSeverity::Error => $this->messenger()->addError($message),
        RequirementSeverity::Warning => $this->messenger()->addWarning($message),
        default => NULL,
      };
    }
  }

  /**
   * Describes when a generated report was generated.
   */
  protected function describeStatus(GeneratedReportInterface $report): string|\Stringable {
    $status = $this->reportStorage->getStatus($report);
    $completed = isset($status['completed']) ? $this->t('Generated @time ago.', ['@time' => $this->dateFormatter->formatTimeDiffSince($status['completed'])]) : $this->t('Not generated yet.');
    if (($status['status'] ?? NULL) === 'complete' && $this->reportStorage->hasChanged($report)) {
      $completed = $this->t('@completed The data it was built from has changed since.', ['@completed' => $completed]);
    }
    return match (TRUE) {
      $this->reportStorage->isGenerating($report) => $this->t('@completed Being generated by @user, started @time ago.', [
        '@completed' => $completed,
        '@time' => $this->dateFormatter->formatTimeDiffSince($status['started']),
        '@user' => $this->entityTypeManager()->getStorage('user')->load($status['uid'])?->getDisplayName() ?? $this->t('Anonymous'),
      ]),
      // No batch activity for a while: the generation was abandoned.
      ($status['status'] ?? NULL) === 'generating' => $this->t('@completed The last attempt to generate it was interrupted.', ['@completed' => $completed]),
      ($status['status'] ?? NULL) === 'failed' => $this->t('@completed The last attempt to generate it failed.', ['@completed' => $completed]),
      default => $completed,
    };
  }

}
