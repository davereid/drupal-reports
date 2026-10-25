<?php

declare(strict_types=1);

namespace Drupal\reports;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Component\Render\PlainTextOutput;
use Drupal\Core\Link;
use Drupal\Core\Url;

/**
 * Appends rows to a generated report's sheet files.
 *
 * Each sheet is a CSV file that starts with the sheet's column labels.
 */
class ReportWriter {

  /**
   * Open file handles, keyed by sheet ID.
   *
   * @var array<string, resource>
   */
  protected array $handles = [];

  /**
   * Constructs a ReportWriter.
   *
   * @param string $directory
   *   The directory to write the sheet files to. It must exist.
   * @param array $sheets
   *   The report's sheets, as returned by ReportInterface::getSheets().
   */
  public function __construct(
    protected readonly string $directory,
    protected readonly array $sheets,
  ) {}

  /**
   * Closes the files.
   */
  public function __destruct() {
    array_map('fclose', $this->handles);
  }

  /**
   * Adds a row to a sheet.
   *
   * @param array<string, mixed> $row
   *   The row, keyed by column key. Missing columns are left empty, and
   *   unknown columns are ignored.
   * @param string $sheet
   *   (optional) The sheet ID. Defaults to 'default'.
   *
   * @throws \InvalidArgumentException
   *   If the report has no such sheet.
   */
  public function addRow(array $row, string $sheet = 'default'): void {
    if (!isset($this->sheets[$sheet])) {
      throw new \InvalidArgumentException("The report has no sheet named '$sheet'.");
    }
    if (!isset($this->handles[$sheet])) {
      $path = "{$this->directory}/{$sheet}.csv";
      $exists = file_exists($path);
      $this->handles[$sheet] = fopen($path, 'a');
      if (!$exists) {
        static::writeCsvRow($this->handles[$sheet], static::getLabels($this->sheets[$sheet]['header']));
      }
    }
    static::writeCsvRow($this->handles[$sheet], static::normalizeRow($this->sheets[$sheet]['header'], $row));
  }

  /**
   * Creates the files of sheets that have no rows, so they have a header.
   */
  public function createEmptySheets(): void {
    foreach ($this->sheets as $sheet => $info) {
      if (!file_exists("{$this->directory}/{$sheet}.csv")) {
        $handle = fopen("{$this->directory}/{$sheet}.csv", 'w');
        static::writeCsvRow($handle, static::getLabels($info['header']));
        fclose($handle);
      }
    }
  }

  /**
   * Writes a row to a CSV file.
   *
   * @param resource $handle
   *   The file handle.
   * @param array $row
   *   The row's cells.
   */
  public static function writeCsvRow($handle, array $row): void {
    fputcsv($handle, array_map(static::normalizeCell(...), $row), escape: '');
  }

  /**
   * Gets the column labels of a header.
   *
   * @param array<string, string|\Stringable|array{label: string|\Stringable, filter?: string|false}> $header
   *   The header, as in ReportInterface::getSheets().
   *
   * @return array<string, string|\Stringable>
   *   The labels, keyed by column key.
   */
  public static function getLabels(array $header): array {
    return array_map(fn ($column) => is_array($column) ? $column['label'] : $column, $header);
  }

  /**
   * Orders a row by the header's columns and converts its cells to strings.
   *
   * @param array<string, mixed> $header
   *   Column labels keyed by column key.
   * @param array<string, mixed> $row
   *   The row, keyed by column key.
   *
   * @return list<string>
   *   The cells, in the header's order.
   */
  public static function normalizeRow(array $header, array $row): array {
    return array_map(fn ($key) => static::normalizeCell($row[$key] ?? NULL), array_keys($header));
  }

  /**
   * Converts a cell to plain text.
   *
   * URLs become absolute URLs. Links keep only their text: use a separate
   * \Drupal\Core\Url column when the URL is needed in files and Drush output.
   *
   * @param mixed $cell
   *   The cell.
   *
   * @return string
   *   The plain text.
   */
  public static function normalizeCell(mixed $cell): string {
    return match (TRUE) {
      $cell === NULL => '',
      is_bool($cell) => $cell ? '1' : '0',
      $cell instanceof Url => $cell->setAbsolute()->toString(),
      $cell instanceof Link => PlainTextOutput::renderFromHtml((string) $cell->getText()),
      $cell instanceof MarkupInterface => PlainTextOutput::renderFromHtml((string) $cell),
      $cell instanceof \UnitEnum => $cell->name,
      default => (string) $cell,
    };
  }

}
