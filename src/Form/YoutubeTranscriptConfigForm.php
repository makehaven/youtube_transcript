<?php

namespace Drupal\youtube_transcript\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configuration form for YouTube Transcript API settings.
 */
class YoutubeTranscriptConfigForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['youtube_transcript.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'youtube_transcript_config_form';
  }

  /**
   * Builds the configuration form.
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('youtube_transcript.settings');
    $default_redirect_uri = $config->get('google_redirect_uri') ?: 'https://dev.makehaven.org/youtube_transcript/oauth-callback';

    $form['google_client_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Google OAuth Client ID'),
      '#default_value' => $config->get('google_client_id'),
      '#required' => TRUE,
    ];

    $form['google_client_secret'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Google OAuth Client Secret'),
      '#default_value' => $config->get('google_client_secret'),
      '#required' => TRUE,
    ];

    $form['google_redirect_uri'] = [
      '#type' => 'textfield',
      '#title' => $this->t('OAuth Redirect URI'),
      '#default_value' => $default_redirect_uri,
      '#description' => $this->t('Set this as an authorized redirect URI in your Google Cloud Console. For example: https://dev.makehaven.org/youtube_transcript/oauth-callback'),
      '#required' => TRUE,
    ];

    // Button to start Google OAuth authentication.
    $form['authenticate'] = [
      '#type' => 'markup',
      '#markup' => '<a href="/youtube_transcript/authenticate" class="button button--primary">' . $this->t('Authenticate with Google') . '</a>',
    ];

    // Button to fetch all transcripts.
    $form['fetch_all_transcripts'] = [
      '#type' => 'submit',
      '#value' => $this->t('Fetch All Transcripts'),
      '#submit' => ['::fetchAllTranscripts'],
    ];

    // --- Test Section (Top Level) ---
    // Ensure the field is top-level by not nesting it and explicitly set default_value.
    $form['test_video_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Test Video URL'),
      '#default_value' => '',
      '#description' => $this->t('Enter a YouTube video URL to test transcript fetching without updating all badges.'),
      '#tree' => FALSE,
    ];
    $form['test_transcript'] = [
      '#type' => 'submit',
      '#value' => $this->t('Test Transcript'),
      '#submit' => ['::testTranscript'],
      // Prevent full form validation so only this part is processed.
      '#limit_validation_errors' => [],
    ];
    // --- End Test Section ---

    return parent::buildForm($form, $form_state);
  }

  /**
   * Fetches all transcripts when the admin clicks the button.
   */
  public function fetchAllTranscripts(array &$form, FormStateInterface $form_state) {
    $terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadByProperties(['vid' => 'badges']);
    $fetcher = \Drupal::service('youtube_transcript.fetcher');

    foreach ($terms as $term) {
      $fetcher->fetchAndStoreTranscript($term);
    }

    $this->messenger()->addStatus($this->t('All transcripts have been refreshed.'));
  }

  /**
   * Tests fetching a transcript for a specific video.
   */
  public function testTranscript(array &$form, FormStateInterface $form_state) {
    // Retrieve raw user input.
    $user_input = $form_state->getUserInput();
    if (empty($user_input['test_video_url'])) {
      $this->messenger()->addError($this->t('Please enter a YouTube video URL.'));
      return;
    }
    $video_url = $user_input['test_video_url'];
    // Extract video ID from the provided URL.
    if (preg_match('/v=([a-zA-Z0-9_-]+)/', $video_url, $matches)) {
      $video_id = $matches[1];
    }
    elseif (preg_match('/youtu\.be\/([a-zA-Z0-9_-]+)/', $video_url, $matches)) {
      $video_id = $matches[1];
    }
    else {
      $this->messenger()->addError($this->t('Invalid YouTube URL.'));
      return;
    }
    $fetcher = \Drupal::service('youtube_transcript.fetcher');
    $transcript = $fetcher->testTranscript($video_id);
    if ($transcript) {
      $trimmed = mb_strimwidth($transcript, 0, 200, '...');
      $this->messenger()->addStatus($this->t('Transcript fetched successfully: @transcript', ['@transcript' => $trimmed]));
    }
    else {
      $error = $fetcher->getLastError();
      if (empty($error)) {
        $error = 'Unknown error.';
      }
      $this->messenger()->addError($this->t('Failed to fetch transcript for the provided video URL. Error: @error', ['@error' => $error]));
    }
  }

  /**
   * Saves configuration settings.
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('youtube_transcript.settings')
      ->set('google_client_id', $form_state->getValue('google_client_id'))
      ->set('google_client_secret', $form_state->getValue('google_client_secret'))
      ->set('google_redirect_uri', $form_state->getValue('google_redirect_uri'))
      ->save();

    return parent::submitForm($form, $form_state);
  }

}
