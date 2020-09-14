<?php

namespace Drupal\sendinblue_api\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

class SendinblueApiSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'sendinblue_api_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['sendinblue_api.config'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('sendinblue_api.config');

    $form['api_key'] = array(
      '#default_value' => $config->get('api_key'),
      '#title' => t('API Key'),
      '#type' => 'textfield',
      '#required' => TRUE,
    );

    $form['sender_name'] = array(
      '#default_value' => $config->get('sender_name'),
      '#title' => t('Sender Name'),
      '#type' => 'textfield',
      '#required' => TRUE,
    );

    $form['sender_email'] = array(
      '#default_value' => $config->get('sender_email'),
      '#title' => t('Sender Email'),
      '#type' => 'textfield',
      '#required' => TRUE,
    );

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('sendinblue_api.config')
      ->set('api_key', $form_state->getValue('api_key'))
      ->set('sender_name', $form_state->getValue('sender_name'))
      ->set('sender_email', $form_state->getValue('sender_email'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
