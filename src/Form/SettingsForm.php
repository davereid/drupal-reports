<?php

declare(strict_types=1);

namespace Drupal\reports\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configures the Reports module.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'reports_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['reports.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['regenerate_after'] = [
      '#type' => 'number',
      '#title' => $this->t('Generated reports are out of date after'),
      '#field_suffix' => $this->t('seconds'),
      '#min' => 0,
      '#description' => $this->t('Out of date reports show a warning and ask to be regenerated. Use 0 to never warn.'),
      '#config_target' => 'reports.settings:regenerate_after',
    ];
    $form['delete_after'] = [
      '#type' => 'number',
      '#title' => $this->t('Delete generated reports after'),
      '#field_suffix' => $this->t('seconds'),
      '#min' => 0,
      '#description' => $this->t('Generated report files older than this are deleted on cron, and generated again when they are next viewed. Use 0 to keep them.'),
      '#config_target' => 'reports.settings:delete_after',
    ];
    return parent::buildForm($form, $form_state);
  }

}
