<?php

namespace Drupal\youtube_transcript\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
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
    $default_redirect_uri = $config->get('google_redirect_uri') ?: Url::fromRoute('youtube_transcript.oauth_callback', [], ['absolute' => TRUE])->toString();

    // Setup instructions (rendered inside a <details>).
    $form['instructions'] = [
      '#type' => 'details',
      '#title' => $this->t('Setup Instructions'),
      '#open' => FALSE,
    ];
    $form['instructions']['content'] = [
      '#type' => 'item',
      '#markup' => $this->t('
        <ol>
          <li>Go to <a href="https://console.cloud.google.com/" target="_blank" rel="noopener">Google Cloud Console</a>.</li>
          <li>Create a new project (or select an existing one).</li>
          <li>Enable the <strong>YouTube Data API v3</strong> for your project.</li>
          <li>Navigate to <em>APIs &amp; Services → Credentials</em>.</li>
          <li>Create an <strong>OAuth 2.0 Client ID</strong> (type: <em>Web application</em>).</li>
          <li>Add <code>@redirect_url</code> as an <em>Authorized redirect URI</em>.</li>
          <li>Copy the <strong>Client ID</strong> and <strong>Client Secret</strong> below, then save.</li>
          <li>Click <strong>Authenticate with Google</strong>. When the OAuth flow completes, return to this page.</li>
        </ol>
      ', [
        '@redirect_url' => Url::fromRoute('youtube_transcript.oauth_callback', [], ['absolute' => TRUE])->toString(),
      ]),
    ];

    // OAuth credentials.
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
      '#description' => $this->t('This must exactly match an Authorized Redirect URI in Google Cloud Console.'),
      '#required' => TRUE,
    ];

    // Authenticate link (routes to your controller that starts the OAuth flow).
    $form['authenticate'] = [
      '#type' => 'link',
      '#title' => $this->t('Authenticate with Google'),
      '#url' => Url::fromRoute('youtube_transcript.authenticate'),
      '#attributes' => [
        'class' => ['button', 'button--primary'],
      ],
    ];

    // Batch controls.
    $form['batch_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Batch Processing'),
      '#open' => TRUE,
    ];
    $form['batch_settings']['batch_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Batch size'),
      '#default_value' => $config->get('batch_size') ?: 20,
      '#min' => 1,
      '#max' => 500,
      '#description' => $this->t('How many terms to process per run. Smaller batches help avoid YouTube quota issues.'),
    ];
    $form['batch_settings']['start_offset'] = [
      '#type' => 'number',
      '#title' => $this->t('Start offset'),
      '#default_value' => $config->get('start_offset') ?: 0,
      '#min' => 0,
      '#description' => $this->t('Zero-based index into the badges list. Each run starts here; after a run this value auto-increments by the number processed.'),
    ];
    $form['batch_settings']['reset_offset'] = [
      '#type' => 'submit',
      '#value' => $this->t('Reset offset to 0'),
      '#submit' => ['::resetOffset'],
      '#limit_validation_errors' => [],
    ];

    // Buttons to fetch and to reset caches.
    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['fetch_all_transcripts'] = [
      '#type' => 'submit',
      '#value' => $this->t('Fetch All Transcripts'),
      '#submit' => ['::fetchAllTranscripts'],
    ];
    $form['actions']['reset_cache'] = [
      '#type' => 'submit',
      '#value' => $this->t('Reset Caption Cache'),
      '#submit' => ['::resetTranscriptCache'],
      '#limit_validation_errors' => [],
    ];
    // (Optional legacy) reset old queue state keys, if you had them before.
    $form['actions']['reset_queue'] = [
      '#type' => 'submit',
      '#value' => $this->t('Reset Transcript Queue (legacy)'),
      '#submit' => ['::resetTranscriptQueue'],
      '#limit_validation_errors' => [],
    ];

    // Test section (does not save the config).
    $form['test'] = [
      '#type' => 'details',
      '#title' => $this->t('One-off Test'),
      '#open' => FALSE,
    ];
    $form['test']['test_video_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Test Video URL'),
      '#default_value' => '',
      '#description' => $this->t('Enter a YouTube video URL to test transcript fetching without updating all badges.'),
    ];
    $form['test']['test_transcript'] = [
      '#type' => 'submit',
      '#value' => $this->t('Test Transcript'),
      '#submit' => ['::testTranscript'],
      '#limit_validation_errors' => [],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * Fetches all transcripts (batch by offset/size).
   */
  public function fetchAllTranscripts(array &$form, FormStateInterface $form_state) {
    $config = $this->config('youtube_transcript.settings');

    $batch_size = (int) ($config->get('batch_size') ?? 20);
    if ($batch_size < 1) { $batch_size = 1; }

    $offset = (int) ($config->get('start_offset') ?? 0);
    if ($offset < 0) { $offset = 0; }

    // Get all badge term IDs in a stable order.
    $ids = \Drupal::entityQuery('taxonomy_term')
      ->condition('vid', 'badges')
      ->sort('tid', 'ASC')
      ->execute();

    $total = count($ids);
    if ($total === 0) {
      $this->messenger()->addWarning($this->t('No badge terms found.'));
      return;
    }
    if ($offset >= $total) {
      $this->messenger()->addWarning($this->t('Start offset (@offset) is beyond the end of the list (@total). Resetting to 0.', [
        '@offset' => $offset, '@total' => $total
      ]));
      $offset = 0;
    }

    $window_ids = array_slice(array_values($ids), $offset, $batch_size);
    $storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
    $terms = $storage->loadMultiple($window_ids);

    $fetcher = \Drupal::service('youtube_transcript.fetcher');

    $processed = 0;
    $processed_ids = [];

    foreach ($terms as $term) {
      $ok = $fetcher->fetchAndStoreTranscript($term);
      $processed++;
      $processed_ids[] = (int) $term->id();

      if (!$ok) {
        $err = (string) $fetcher->getLastError();

        // Build a watch URL for helpful messages.
        $youtube_urls = $term->get('field_badge_video')->getValue();
        $youtube_url = $youtube_urls[0]['input'] ?? '';
        $vid = NULL;
        if (preg_match('/v=([a-zA-Z0-9_-]+)/', $youtube_url, $m)) {
          $vid = $m[1];
        } elseif (preg_match('/youtu\.be\/([a-zA-Z0-9_-]+)/', $youtube_url, $m)) {
          $vid = $m[1];
        }
        $watch_url = $vid ? "https://www.youtube.com/watch?v={$vid}" : $youtube_url;

        if (stripos($err, 'auto-generated (ASR)') !== FALSE) {
          $this->messenger()->addWarning($this->t(
            'Term @tid skipped: only auto-generated (ASR) captions exist (API cannot download ASR). <a href=":url" target="_blank" rel="noopener">Open on YouTube</a> to add a manual caption (SRT/VTT), then retry.',
            ['@tid' => $term->id(), ':url' => $watch_url]
          ));
        } else {
          $this->messenger()->addWarning($this->t('Error on term @tid: @err', [
            '@tid' => $term->id(), '@err' => $err
          ]));
        }

        // Stop early on quota-related errors.
        if (stripos($err, 'quota') !== FALSE) {
          $new_offset = $offset + $processed;
          $this->configFactory->getEditable('youtube_transcript.settings')
            ->set('start_offset', $new_offset >= $total ? 0 : $new_offset)
            ->save();

          $this->messenger()->addError($this->t(
            'Stopped early due to quota after @n items. Progress: @done / @total. Next run will start at offset @next.',
            [
              '@n' => $processed,
              '@done' => min($offset + $processed, $total),
              '@total' => $total,
              '@next' => $new_offset >= $total ? 0 : $new_offset,
            ]
          ));
          return;
        }
      }
    }

    // Remember last batch (useful for a “Re-run last batch” action if you add one).
    \Drupal::state()->set('youtube_transcript.last_batch', $processed_ids);

    // Bump offset (wrap to 0 at the end).
    $new_offset = $offset + $processed;
    if ($new_offset >= $total) {
      $new_offset = 0;
      $this->messenger()->addStatus($this->t('Reached the end of the list. Offset wrapped to 0.'));
    }
    $this->configFactory->getEditable('youtube_transcript.settings')
      ->set('start_offset', $new_offset)
      ->save();

    $this->messenger()->addStatus($this->t(
      'Processed @n this run. Progress: @done / @total. Next start offset: @next.',
      [
        '@n' => $processed,
        '@done' => min($offset + $processed, $total),
        '@total' => $total,
        '@next' => $new_offset,
      ]
    ));
  }

  /**
   * Reset (legacy) queue state keys from older versions.
   */
  public function resetTranscriptQueue(array &$form, FormStateInterface $form_state) {
    $state = \Drupal::state();
    $state->delete('youtube_transcript.queue');
    $state->delete('youtube_transcript.offset');
    $this->messenger()->addStatus($this->t('Legacy transcript queue state cleared. The module now uses offset + batch size.'));
  }

  /**
   * Clears cached manual caption IDs.
   */
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
   * Resets the start offset to 0.
   */
  public function resetOffset(array &$form, FormStateInterface $form_state) {
    $this->configFactory->getEditable('youtube_transcript.settings')
      ->set('start_offset', 0)
      ->save();
    $this->messenger()->addStatus($this->t('Start offset reset to 0.'));
  }

  /**
   * One-off tester for a single video URL.
   */
  public function testTranscript(array &$form, FormStateInterface $form_state) {
    $user_input = $form_state->getUserInput();
    if (empty($user_input['test_video_url'])) {
      $this->messenger()->addError($this->t('Please enter a YouTube video URL.'));
      return;
    }
    $video_url = $user_input['test_video_url'];

    // Extract a video ID.
    $video_id = NULL;
    if (preg_match('/v=([a-zA-Z0-9_-]+)/', $video_url, $m)) {
      $video_id = $m[1];
    }
    elseif (preg_match('/youtu\.be\/([a-zA-Z0-9_-]+)/', $video_url, $m)) {
      $video_id = $m[1];
    }
    if (!$video_id) {
      $this->messenger()->addError($this->t('Invalid YouTube URL.'));
      return;
    }

    $fetcher = \Drupal::service('youtube_transcript.fetcher');
    try {
      $transcript = $fetcher->testTranscript($video_id);
      if ($transcript) {
        $this->messenger()->addStatus($this->t('Transcript fetched successfully.'));
      } else {
        $err = (string) $fetcher->getLastError();
        $this->messenger()->addError($this->t('No transcript returned. @err', ['@err' => $err ?: '']));
      }
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($this->t('Failed to fetch transcript. Error: @msg', [
        '@msg' => $e->getMessage(),
      ]));
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
      ->set('batch_size', max(1, (int) $form_state->getValue('batch_size')))
      ->set('start_offset', max(0, (int) $form_state->getValue('start_offset')))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
