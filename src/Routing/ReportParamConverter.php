<?php

declare(strict_types=1);

namespace Drupal\reports\Routing;

use Drupal\Core\ParamConverter\ParamConverterInterface;
use Drupal\reports\Plugin\Report\ReportInterface;
use Drupal\reports\ReportManager;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Route;

/**
 * Converts {report} route parameters to report plugins.
 *
 * The report gets the configuration from the 'config' query string. Unknown
 * reports, and reports that don't apply to the site, aren't converted, so
 * their routes return 404 Not Found.
 *
 * Use it on a route with:
 * @code
 * options:
 *   parameters:
 *     report:
 *       type: report
 * @endcode
 */
class ReportParamConverter implements ParamConverterInterface {

  public function __construct(
    protected readonly ReportManager $reportManager,
    protected readonly RequestStack $requestStack,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function convert($value, $definition, $name, array $defaults): ?ReportInterface {
    $id = (string) $value;
    if (!$this->reportManager->isApplicable($id)) {
      return NULL;
    }
    $configuration = $this->requestStack->getCurrentRequest()?->query->all('config') ?? [];
    return $this->reportManager->createInstance($id, $configuration);
  }

  /**
   * {@inheritdoc}
   */
  public function applies($definition, $name, Route $route): bool {
    return ($definition['type'] ?? NULL) === 'report';
  }

}
