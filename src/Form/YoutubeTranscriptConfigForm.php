<?php

namespace Drupal\youtube_transcript\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;

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

    $form['instructions'] = [
      '#type' => 'details',
      '#title' => $this->t('Setup Instructions'),
      '#open' => FALSE,
      '#markup' => $this->t('
        <ol>
          <li>Go to <a href="https://console.cloud.google.com/" target="_blank">Google Cloud Console</a>.</li>
          <li>Create a new project (or select an existing one).</li>
          <li>Enable the <strong>YouTube Data API v3</strong> for your project.</li>
          <li>Navigate to <em>APIs & Services → Credentials</em>.</li>
          <li>Create an <strong>OAuth 2.0 Client ID</strong> (choose type: Web Application).</li>
          <li>Add <code>@redirect_url</code> as an authorized redirect URI.</li>
          <li>Copy the <strong>Client ID</strong> and <strong>Client Secret</strong> into the fields below.</li>
          <li>Save configuration, then click <strong>Authenticate with Google</strong>.</li>
        </ol>
      ', [
        '@redirect_url' => Url::fromRoute('youtube_transcript.authenticate', [], ['absolute' => TRUE])->toString(),
      ]),
    ];
    
    
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
      '#type' => 'link',
      '#title' => $this->t('Authenticate with Google'),
      '#url' => Url::fromRoute('youtube_transcript.authenticate'),
      '#attributes' => [
        'class' => ['button', 'button--primary'],
      ],
    ];

    

    // Button to fetch all transcripts.
    $form['fetch_all_transcripts'] = [
      '#type' => 'submit',
      '#value' => $this->t('Fetch All Transcripts'),
      '#submit' => ['::fetchAllTranscripts'],
    ];

    

    // ADD in buildForm() to render a reset link:
$form['reset_queue'] = [
  '#type' => 'submit',
  '#value' => $this->t('Reset Transcript Queue'),
  '#submit' => ['::resetTranscriptQueue'],
  '#limit_validation_errors' => [],
];

    // In buildForm():
    $form['reset_cache'] = [
      '#type' => 'submit',
      '#value' => $this->t('Reset Caption Cache'),
      '#submit' => ['::resetTranscriptCache'],
      '#limit_validation_errors' => [],
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
// REPLACE the whole fetchAllTranscripts() in the form class:

  public function fetchAllTranscripts(array &$form, FormStateInterface $form_state) {
    $state = \Drupal::state();
    $storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
  
    // Build queue once: only terms in 'badges' vocabulary that have a YouTube URL.
    $queue = $state->get('youtube_transcript.queue', []);
    $offset = (int) $state->get('youtube_transcript.offset', 0);
  
    if (empty($queue)) {
      $tids = [];
      $terms = $storage->loadByProperties(['vid' => 'badges']);
      foreach ($terms as $term) {
        $vals = $term->get('field_badge_video')->getValue();
        $url = $vals[0]['input'] ?? '';
        if (!empty($url)) {
          $tids[] = (int) $term->id();
        }
      }
      sort($tids, SORT_NUMERIC);
      $queue = $tids;
      $offset = 0;
      $state->set('youtube_transcript.queue', $queue);
      $state->set('youtube_transcript.offset', $offset);
      $this->messenger()->addStatus($this->t('Initialized queue with @n terms.', ['@n' => count($queue)]));
    }
  
    if ($offset >= count($queue)) {
      $this->messenger()->addStatus($this->t('All items already processed.'));
      return;
    }
  
    $fetcher = \Drupal::service('youtube_transcript.fetcher');
  
    // Tune this to control quota usage per click/cron run.
    $batch_size = 10;
    $processed = 0;
  
    while ($processed < $batch_size && $offset < count($queue)) {
      $tid = $queue[$offset];
      $term = $storage->load($tid);
      $offset++;
      if (!$term) {
        continue;
      }
  
      $ok = $fetcher->fetchAndStoreTranscript($term);
      $processed++;
  
      if (!$ok) {
        $err = (string) $fetcher->getLastError();
        // Stop on quota‑type messages (don’t burn more calls).
        if (stripos($err, 'quota') !== FALSE || stripos($err, 'quotaExceeded') !== FALSE) {
          $state->set('youtube_transcript.offset', $offset);
          $this->messenger()->addError($this->t('Stopped early due to quota: @e. Progress saved at offset @off/@total.', [
            '@e' => $err,
            '@off' => $offset,
            '@total' => count($queue),
          ]));
          return;
        }
        // For other errors, just log and continue to the next term.
        $this->messenger()->addWarning($this->t('Error on term @tid: @e', ['@tid' => $tid, '@e' => $err]));
      }
    }
  
    // Save progress.
    $state->set('youtube_transcript.offset', $offset);
  
    // Finished?
    if ($offset >= count($queue)) {
      // Clear queue markers so a future click starts fresh.
      $state->delete('youtube_transcript.queue');
      $state->delete('youtube_transcript.offset');
      $this->messenger()->addStatus($this->t('Completed all items. Processed @n total this run.', ['@n' => $processed]));
    }
    else {
      $this->messenger()->addStatus($this->t('Processed @n this run. Progress: @off / @total. Click again later to continue.', [
        '@n' => $processed,
        '@off' => $offset,
        '@total' => count($queue),
      ]));
    }
  }
  
    // ADD THIS WHOLE METHOD inside the YoutubeTranscriptConfigForm class.
    public function resetTranscriptQueue(array &$form, FormStateInterface $form_state) {
      $state = \Drupal::state();
      $state->delete('youtube_transcript.queue');
      $state->delete('youtube_transcript.offset');
      $this->messenger()->addStatus($this->t('Transcript queue has been reset. Click "Fetch All Transcripts" to rebuild and start again.'));
    }
  


  public function resetTranscriptCache(array &$form, FormStateInterface $form_state) {
    $store = \Drupal::keyValue('youtube_transcript');
    $state = \Drupal::state();
    $all = $state->get('youtube_transcript.cached_terms', []);
    foreach (array_keys($all) as $tid) {
      $store->delete('caption_' . $tid);
    }
    $state->delete('youtube_transcript.cached_terms');
    $this->messenger()->addStatus($this->t('Caption cache has been reset.'));
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
