<?php

declare(strict_types=1);

namespace Drupal\reports;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Utility\Crypt;
use Drupal\Core\Cache\CacheTagsChecksumInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\PrivateKey;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\StreamWrapper\StreamWrapperInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManager;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\reports\Exception\ReportGeneratingException;
use Drupal\reports\Plugin\Report\RealtimeReportInterface;
use Drupal\reports\Plugin\Report\ReportInterface;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Stores generated reports and their status.
 *
 * Each report configuration has its files in the reports directory (see
 * getDirectory()), named after its key: '<key>.csv' for the default sheet,
 * '<key>.<sheet>.csv' for other sheets and '<key>.xlsx' for a spreadsheet of
 * all sheets, and a status in the 'reports' key/value collection:
 * - status: 'generating', 'complete' or 'failed'.
 * - heartbeat: When a batch operation last ran, while generating.
 * - started: When the last generation started.
 * - completed: When the stored files were generated, if there are any.
 * - uid: The user who started the last generation.
 * - tags: The report's cache tags when the last generation started.
 * - checksum: The checksum of those cache tags then.
 */
class ReportStorage {

  use StringTranslationTrait;

  /**
   * The default directory of generated reports.
   *
   * Sites can use another with $settings['reports_directory'].
   */
  const DEFAULT_DIRECTORY = 'private://reports';

  /**
   * Seconds without batch activity after which a generation is abandoned.
   */
  const GENERATING_TIMEOUT = 300;

  public function __construct(
    protected readonly FileSystemInterface $fileSystem,
    protected readonly KeyValueFactoryInterface $keyValueFactory,
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly TimeInterface $time,
    protected readonly StreamWrapperManagerInterface $streamWrapperManager,
    protected readonly AccountInterface $currentUser,
    protected readonly CacheTagsChecksumInterface $checksum,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly DateFormatterInterface $dateFormatter,
    protected readonly PrivateKey $privateKey,
    #[Autowire(service: 'lock')]
    protected readonly LockBackendInterface $lock,
  ) {}

  /**
   * Gets the directory of generated reports.
   *
   * @return string
   *   $settings['reports_directory'] from settings.php, or 'private://reports'.
   */
  public function getDirectory(): string {
    return rtrim((string) Settings::get('reports_directory', static::DEFAULT_DIRECTORY), '/');
  }

  /**
   * Checks whether generated reports can be stored.
   */
  public function isAvailable(): bool {
    return $this->getUnavailableReason() === NULL;
  }

  /**
   * Explains why generated reports can't be stored.
   *
   * The directory's stream wrapper must be registered and writable. A public
   * directory can be used, but ReportManager::getRequirements() warns about
   * it.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup|null
   *   The reason, or NULL if they can be stored.
   */
  public function getUnavailableReason(): ?TranslatableMarkup {
    $directory = $this->getDirectory();
    $scheme = StreamWrapperManager::getScheme($directory);
    if (!$scheme || !isset($this->streamWrapperManager->getWrappers(StreamWrapperInterface::WRITE)[$scheme])) {
      return $scheme === 'private'
        ? $this->t('Generated reports need the private file system. Set the file_private_path setting in settings.php, or the reports_directory setting to another private directory.')
        : $this->t('The directory for generated reports (%directory) is not writable: its stream wrapper is missing or read-only. Check the reports_directory setting in settings.php.', ['%directory' => $directory]);
    }
    return NULL;
  }

  /**
   * Checks whether generated reports are stored in the public file system.
   *
   * Anyone who has a file's URL can download it there.
   */
  public function isPublic(): bool {
    return StreamWrapperManager::getScheme($this->getDirectory()) === 'public';
  }

  /**
   * Checks whether generated reports are stored in the temporary directory.
   *
   * It can be cleared at any time, and is separate on each web server.
   */
  public function isTemporary(): bool {
    return StreamWrapperManager::getScheme($this->getDirectory()) === 'temporary';
  }

  /**
   * Gets the storage key of a report and its configuration.
   *
   * @param \Drupal\reports\Plugin\Report\ReportInterface $report
   *   The report.
   *
   * @return string
   *   The key, also used in file names: the plugin ID, followed by a keyed
   *   hash (HMAC) of the plugin ID and configuration, using the site's hash
   *   salt and private key. File names can't be guessed without them, which
   *   matters when reports are stored in a public directory.
   */
  public function getKey(ReportInterface $report): string {
    // Derivative IDs contain ':', which some file systems don't allow.
    $data = ['id' => $report->getPluginId(), 'configuration' => $report->getConfiguration()];
    return str_replace(':', '--', $report->getPluginId()) . '--' . Crypt::hmacBase64(static::encodeArray($data), $this->privateKey->get() . Settings::getHashSalt());
  }

  /**
   * Gets the directory a report is generated in.
   *
   * Its files are named '<sheet>.csv' and 'report.xlsx', and moved to their
   * final names when generation completes.
   */
  public function getTemporaryDirectory(ReportInterface $report): string {
    return $this->getDirectory() . '/' . $this->getKey($report) . '.tmp';
  }

  /**
   * Gets the URI of a completed report file.
   *
   * @param \Drupal\reports\Plugin\Report\ReportInterface $report
   *   The report.
   * @param string $name
   *   The file's name in the temporary directory: '<sheet>.csv' or
   *   'report.xlsx'.
   *
   * @return string
   *   '<key>.csv' for the default sheet, '<key>.<sheet>.csv' for other
   *   sheets, and '<key>.xlsx' for the spreadsheet. Keys never contain a dot.
   */
  protected function getFileUri(ReportInterface $report, string $name): string {
    $base = $this->getDirectory() . '/' . $this->getKey($report);
    return match ($name) {
      'default.csv' => "$base.csv",
      'report.xlsx' => "$base.xlsx",
      default => "$base.$name",
    };
  }

  /**
   * Gets the file of a generated sheet.
   *
   * @return string|null
   *   The file URI, or NULL if the report hasn't been generated or reports
   *   can't be stored.
   */
  public function getFile(ReportInterface $report, string $sheet = ReportInterface::SHEET_DEFAULT): ?string {
    $uri = $this->getFileUri($report, "$sheet.csv");
    // Without its stream wrapper, e.g. without a private file system, there's
    // no file, and file_exists() warns about the missing wrapper.
    return @file_exists($uri) ? $uri : NULL;
  }

  /**
   * Checks whether a report's generated files exist.
   *
   * @return bool
   *   TRUE if the report's spreadsheet (for download-only reports) or its
   *   first sheet's file exists.
   */
  public function hasFiles(ReportInterface $report): bool {
    return $this->isDownloadOnly($report)
      ? $this->getSpreadsheetFile($report) !== NULL
      : $this->getFile($report, array_key_first($report->getSheets())) !== NULL;
  }

  /**
   * Checks whether a report is download-only.
   *
   * Download-only reports keep only their spreadsheet: their sheets' CSV
   * files are deleted once it's written.
   */
  public function isDownloadOnly(ReportInterface $report): bool {
    return !empty($report->getPluginDefinition()['downloadOnly']);
  }

  /**
   * Gets the spreadsheet of a generated report with several sheets.
   *
   * @return string|null
   *   The file URI, or NULL if there is none.
   */
  public function getSpreadsheetFile(ReportInterface $report): ?string {
    $uri = $this->getFileUri($report, 'report.xlsx');
    return @file_exists($uri) ? $uri : NULL;
  }

  /**
   * Writes the sheets being generated to an Excel spreadsheet.
   *
   * Only done for reports with several sheets, or that are download-only:
   * other single sheets are downloaded as CSV files.
   *
   * shortcut: converts every sheet in one request; split it into batch passes
   * if large reports run out of time.
   */
  public function writeSpreadsheet(ReportInterface $report): void {
    $sheets = $report->getSheets();
    if (count($sheets) < 2 && !$this->isDownloadOnly($report)) {
      return;
    }
    $directory = $this->getTemporaryDirectory($report);
    (new ReportWriter($directory, $report))->createEmptySheets();
    // OpenSpout writes a zip file, which needs a local path.
    $writer = new XlsxWriter();
    $writer->openToFile($this->fileSystem->realpath($directory) . '/report.xlsx');
    $names = [];
    foreach ($sheets as $id => $label) {
      $header = $report->getHeader((string) $id);
      $sheet = $names ? $writer->addNewSheetAndMakeItCurrent() : $writer->getCurrentSheet();
      $sheet->setName($names[] = static::getSheetName((string) $label, $names));
      $handle = fopen("$directory/$id.csv", 'r');
      // The column labels, then the rows, with links.
      $writer->addRow(Row::fromValues(fgetcsv($handle, escape: '') ?: []));
      while (($cells = fgetcsv($handle, escape: '')) !== FALSE) {
        $writer->addRow(ReportWriter::toSpreadsheetRow($header, $cells));
      }
      fclose($handle);
    }
    $writer->close();
  }

  /**
   * Makes a spreadsheet tab name valid and unique.
   *
   * @param string $label
   *   The sheet label.
   * @param string[] $existing
   *   The names already used.
   *
   * @return string
   *   At most 31 characters, without the characters Excel doesn't allow.
   */
  public static function getSheetName(string $label, array $existing): string {
    $base = trim(preg_replace('/\s+/', ' ', str_replace(['\\', '/', '?', '*', ':', '[', ']'], ' ', $label)), " '") ?: 'Sheet';
    $name = mb_substr($base, 0, 31);
    for ($i = 2; in_array(mb_strtolower($name), array_map('mb_strtolower', $existing), TRUE); $i++) {
      $name = mb_substr($base, 0, 31 - strlen(" $i")) . " $i";
    }
    return $name;
  }

  /**
   * Reads the rows of a generated sheet.
   *
   * @return \Generator<array<string, string|string[]>>
   *   Rows keyed by column key, with arrays for list columns. Nothing if the
   *   report hasn't been generated.
   */
  public function readRows(ReportInterface $report, string $sheet = ReportInterface::SHEET_DEFAULT): \Generator {
    $uri = $this->getFile($report, $sheet);
    if (!$uri) {
      return;
    }
    $header = $report->getHeader($sheet);
    $keys = array_keys($header);
    // Lists are stored one item per line.
    $list_keys = array_keys(array_filter($header, fn ($column) => is_array($column) && !empty($column['list'])));
    $handle = fopen($uri, 'r');
    // Skip the column labels.
    fgetcsv($handle, escape: '');
    while (($cells = fgetcsv($handle, escape: '')) !== FALSE) {
      // Pad or cut rows written before the report's columns changed.
      $row = array_combine($keys, array_slice(array_pad($cells, count($keys), ''), 0, count($keys)));
      foreach ($list_keys as $key) {
        $row[$key] = $row[$key] === '' ? [] : explode("\n", $row[$key]);
      }
      yield $row;
    }
    fclose($handle);
  }

  /**
   * Gets the rows of a report's sheet.
   *
   * @return iterable<array<string, mixed>>
   *   Rows keyed by column key: from the stored file for generated reports,
   *   otherwise from the report.
   */
  public function getRows(ReportInterface $report, string $sheet = ReportInterface::SHEET_DEFAULT): iterable {
    return $report instanceof RealtimeReportInterface ? $report->getRows($sheet) : $this->readRows($report, $sheet);
  }

  /**
   * Gets the status of a generated report.
   *
   * A status whose files are missing, e.g. on a copy of a site's database
   * without its private files, is corrected: it's deleted, or for a report
   * that's generating, its completed time is removed.
   *
   * @return array{status: string, started: int, completed: ?int, uid: int, tags: string[], checksum: string}|null
   *   The status, or NULL if the report hasn't been generated.
   */
  public function getStatus(ReportInterface $report): ?array {
    $key = $this->getKey($report);
    $status = $this->keyValue()->get($key);
    if (isset($status['completed']) && !$this->hasFiles($report)) {
      if ($status['status'] !== 'generating') {
        $this->keyValue()->delete($key);
        return NULL;
      }
      $status['completed'] = NULL;
      $this->keyValue()->set($key, $status);
    }
    return $status;
  }

  /**
   * Checks whether a generated report is out of date.
   *
   * @return bool
   *   TRUE if it was completed longer ago than the regenerate_after setting.
   */
  public function isStale(ReportInterface $report): bool {
    $interval = (int) $this->configFactory->get('reports.settings')->get('regenerate_after');
    $completed = $this->getStatus($report)['completed'] ?? NULL;
    return $interval > 0 && $completed !== NULL && $completed < $this->time->getRequestTime() - $interval;
  }

  /**
   * Checks whether a generated report's data changed since it was generated.
   *
   * @return bool
   *   TRUE if a cache tag of the stored report was invalidated since its
   *   generation started.
   */
  public function hasChanged(ReportInterface $report): bool {
    $status = $this->getStatus($report);
    if (!isset($status['completed'], $status['checksum'])) {
      return FALSE;
    }
    // A failed or unfinished generation can't be compared with the stored
    // files, which came from an earlier one.
    if ($status['status'] !== 'complete') {
      return TRUE;
    }
    // Invalidating tags only increases their checksum, so a lower one means
    // the tags were purged, e.g. by drush cache:rebuild. That erases the
    // history, so assume nothing changed and compare from now on.
    $current = $this->checksum->getCurrentChecksum($status['tags']);
    if ($current >= 0 && $current < (int) $status['checksum']) {
      $status['checksum'] = $current;
      $this->keyValue()->set($this->getKey($report), $status);
      return FALSE;
    }
    return !$this->checksum->isValid($status['checksum'], $status['tags']);
  }

  /**
   * Checks whether a report is being generated.
   *
   * @return bool
   *   TRUE if its status is generating, and a batch operation ran less than
   *   GENERATING_TIMEOUT seconds ago. Abandoned batches stop counting after
   *   that.
   */
  public function isGenerating(ReportInterface $report): bool {
    return $this->isGeneratingStatus($this->keyValue()->get($this->getKey($report)));
  }

  /**
   * Checks whether a status is of a generation still in progress.
   */
  protected function isGeneratingStatus(?array $status): bool {
    return ($status['status'] ?? NULL) === 'generating'
      && ($status['heartbeat'] ?? $status['started']) > $this->time->getCurrentTime() - static::GENERATING_TIMEOUT;
  }

  /**
   * Records that a report's generation is still running.
   *
   * Called by each batch operation, so the report keeps counting as being
   * generated.
   */
  public function keepGenerating(ReportInterface $report): void {
    $key = $this->getKey($report);
    $status = $this->keyValue()->get($key);
    if (($status['status'] ?? NULL) === 'generating') {
      $status['heartbeat'] = $this->time->getCurrentTime();
      $this->keyValue()->set($key, $status);
    }
  }

  /**
   * Prepares a report to be generated.
   *
   * Removes any earlier unfinished files, and sets its status to generating.
   *
   * @throws \Drupal\reports\Exception\ReportGeneratingException
   *   If the report is already being generated with the same options.
   */
  public function start(ReportInterface $report): void {
    // Check and set the status in one step, so two requests can't both start.
    $lock_name = 'reports_start:' . $this->getKey($report);
    if (!$this->lock->acquire($lock_name)) {
      // The message is shown to users, so it's translated.
      // phpcs:ignore DrupalPractice.General.ExceptionT.ExceptionT
      throw new ReportGeneratingException((string) $this->t('This report is already being generated.'));
    }
    try {
      $status = $this->keyValue()->get($this->getKey($report));
      if ($this->isGeneratingStatus($status)) {
        $user = $this->entityTypeManager->getStorage('user')->load($status['uid']);
        // phpcs:ignore DrupalPractice.General.ExceptionT.ExceptionT
        throw new ReportGeneratingException((string) $this->t('This report is already being generated by @user, started @time ago.', [
          '@user' => $user?->getDisplayName() ?? $this->t('Anonymous'),
          '@time' => $this->dateFormatter->formatTimeDiffSince($status['started']),
        ]));
      }
      $this->startGenerating($report);
    }
    finally {
      $this->lock->release($lock_name);
    }
  }

  /**
   * Sets a report's status to generating, with new temporary files.
   */
  protected function startGenerating(ReportInterface $report): void {
    $directory = $this->getTemporaryDirectory($report);
    $this->fileSystem->deleteRecursive($directory);
    $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);
    $tags = $report->getCacheTags();
    $this->keyValue()->set($this->getKey($report), [
      'status' => 'generating',
      'started' => $this->time->getCurrentTime(),
      'completed' => $this->getStatus($report)['completed'] ?? NULL,
      'uid' => (int) $this->currentUser->id(),
      'heartbeat' => $this->time->getCurrentTime(),
      // Taken before generating, so changes made meanwhile count as changes.
      'tags' => $tags,
      'checksum' => $this->checksum->getCurrentChecksum($tags),
    ]);
  }

  /**
   * Finishes generating a report.
   *
   * @param \Drupal\reports\Plugin\Report\ReportInterface $report
   *   The report.
   * @param bool $success
   *   TRUE to replace the stored files with the new ones, FALSE to discard
   *   them and keep the earlier files.
   */
  public function finish(ReportInterface $report, bool $success): void {
    $status = $this->getStatus($report) ?? ['started' => $this->time->getCurrentTime(), 'completed' => NULL, 'uid' => 0];
    $temporary = $this->getTemporaryDirectory($report);
    if ($success) {
      (new ReportWriter($temporary, $report))->createEmptySheets();
      // Download-only reports keep only their spreadsheet, and the number of
      // rows of each sheet for their page.
      if ($this->isDownloadOnly($report)) {
        $status['rows'] = [];
        foreach (array_keys($report->getSheets()) as $sheet) {
          $handle = fopen("$temporary/$sheet.csv", 'r');
          // Don't count the column labels.
          $count = -1;
          while (fgetcsv($handle, escape: '') !== FALSE) {
            $count++;
          }
          fclose($handle);
          $status['rows'][$sheet] = $count;
          $this->fileSystem->delete("$temporary/$sheet.csv");
        }
      }
      // Remove files of sheets the report no longer has, then move the new
      // files into place.
      $key = $this->getKey($report);
      foreach (['csv', 'xlsx'] as $extension) {
        foreach ($this->fileSystem->scanDirectory($this->getDirectory(), '/^' . preg_quote($key, '/') . '(\.[^.]+)?\.' . $extension . '$/', ['recurse' => FALSE]) as $file) {
          $this->fileSystem->delete($file->uri);
        }
      }
      foreach ($this->fileSystem->scanDirectory($temporary, '/./', ['recurse' => FALSE]) as $file) {
        $this->fileSystem->move($file->uri, $this->getFileUri($report, $file->filename), FileExists::Replace);
      }
      $status['completed'] = $this->time->getCurrentTime();
    }
    $this->fileSystem->deleteRecursive($temporary);
    $status['status'] = $success ? 'complete' : 'failed';
    $this->keyValue()->set($this->getKey($report), $status);
  }

  /**
   * Deletes old and unknown generated report files.
   *
   * Goes through the files in the reports directory, and gets each one's key
   * from its name. Deletes:
   * - Files without a completed generation, or completed longer ago than the
   *   delete_after setting, with their status.
   * - Temporary directories, unless their report is being generated (see
   *   isGenerating()). Abandoned generations are set to failed.
   * - Status entries without files, unless their report is generating.
   *
   * @return int
   *   The number of files and directories deleted.
   */
  public function deleteExpired(): int {
    if (!$this->isAvailable() || !is_dir($this->getDirectory())) {
      return 0;
    }
    $now = $this->time->getCurrentTime();
    $delete_after = (int) $this->configFactory->get('reports.settings')->get('delete_after');
    $statuses = $this->keyValue()->getAll();
    $deleted = 0;
    $keys_with_files = [];
    foreach ($this->fileSystem->scanDirectory($this->getDirectory(), '/.*/', ['recurse' => FALSE]) as $file) {
      // Keys never contain a dot, so the key is the name up to the first dot.
      $key = strstr($file->filename, '.', TRUE) ?: $file->filename;
      $status = $statuses[$key] ?? NULL;
      if (str_ends_with($file->filename, '.tmp')) {
        if ($this->isGeneratingStatus($status)) {
          continue;
        }
        $this->fileSystem->deleteRecursive($file->uri);
        $deleted++;
        if (($status['status'] ?? NULL) === 'generating') {
          $statuses[$key]['status'] = 'failed';
          $this->keyValue()->set($key, $statuses[$key]);
        }
        continue;
      }
      $completed = $status['completed'] ?? NULL;
      if ($completed === NULL || ($delete_after > 0 && $completed < $now - $delete_after)) {
        $this->fileSystem->delete($file->uri);
        $deleted++;
        continue;
      }
      $keys_with_files[$key] = TRUE;
    }
    foreach ($statuses as $key => $status) {
      if (!isset($keys_with_files[$key]) && !$this->isGeneratingStatus($status)) {
        $this->keyValue()->delete($key);
      }
    }
    return $deleted;
  }

  /**
   * Deletes all generated reports and their status.
   */
  public function deleteAll(): void {
    if ($this->isAvailable()) {
      $this->fileSystem->deleteRecursive($this->getDirectory());
    }
    $this->keyValue()->deleteAll();
  }

  /**
   * Encodes an array as JSON, ignoring the order of its keys.
   *
   * @todo Use a Helper method once Helper has one (see ArrayHelper::hash()).
   * @todo Should this have an option to sort or not?
   * @todo Should this be hashArray and take a $secure parameter to run it
   *   through Crypt::hmacBase64() instead of Crypt::hashBase64()?
   */
  protected static function encodeArray(array $array): string {
    $sort = function (array &$array) use (&$sort): void {
      ksort($array);
      foreach ($array as &$value) {
        if (is_array($value)) {
          $sort($value);
        }
      }
    };
    $sort($array);
    return json_encode($array, JSON_THROW_ON_ERROR);
  }

  /**
   * Gets the key/value collection with report status.
   */
  protected function keyValue(): KeyValueStoreInterface {
    return $this->keyValueFactory->get('reports');
  }

}
