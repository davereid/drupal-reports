<?php

declare(strict_types=1);

namespace Drupal\reports\Plugin\Report;

/**
 * A report that is expensive to build.
 *
 * It's generated on demand with the Batch API, and stored in the private
 * file system for each configuration.
 */
interface GeneratedReportInterface extends ReportInterface {

  /**
   * Gets the batch operations that generate the report.
   *
   * Each operation calls a public method of the plugin, with the arguments
   * given, followed by the writer and the batch context:
   * @code
   * public function processField(string $entity_type_id, string $field_name, ReportWriter $writer, &$context): void
   * @endcode
   * Methods can run in several passes by setting $context['finished'] to a
   * number below 1, keeping their progress in $context['sandbox'].
   *
   * @return array<array{0: string, 1?: array}>
   *   A list of operations: the method name, and its arguments. Arguments
   *   must be serializable.
   */
  public function getBatchOperations(): array;

}
