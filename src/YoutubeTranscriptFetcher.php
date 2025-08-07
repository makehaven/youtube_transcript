<?php

namespace Drupal\youtube_transcript;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\taxonomy\Entity\Term;
use Google\Client as Google_Client;
use Google\Service\YouTube;
use Drupal\Core\File\FileSystemInterface;

/**
 * Fetches YouTube transcripts and updates taxonomy terms.
 */
class YoutubeTranscriptFetcher {
  protected $configFactory;
  protected $lastError = '';

  public function __construct(ConfigFactoryInterface $config_factory) {
    $this->configFactory = $config_factory;
  }

  /**
   * Get the last error message.
   */
  public function getLastError() {
    return $this->lastError;
  }

  /**
   * Fetch and store transcript for a given taxonomy term.
   */
  public function fetchAndStoreTranscript(Term $term) {
    \Drupal::logger('youtube_transcript')->notice('Starting transcript fetch for term: @term_id', ['@term_id' => $term->id()]);
    $youtube_urls = $term->get('field_badge_video')->getValue();
    $youtube_url = $youtube_urls[0]['input'] ?? null;
    if (!$youtube_url) {
      $this->lastError = 'No YouTube URL found for term ' . $term->id();
      \Drupal::logger('youtube_transcript')->error($this->lastError);
      return FALSE;
    }
    $video_id = $this->extractVideoId($youtube_url);
    if (!$video_id) {
      $this->lastError = 'Invalid YouTube URL for term ' . $term->id() . ': ' . $youtube_url;
      \Drupal::logger('youtube_transcript')->error($this->lastError);
      return FALSE;
    }
    $transcript = $this->fetchTranscript($video_id);
    if ($transcript) {
      \Drupal::logger('youtube_transcript')->notice('Transcript successfully retrieved for term @term_id.', ['@term_id' => $term->id()]);
      $term->set('field_badge_video_transcript', $transcript);
      $term->save();
      return TRUE;
    }
    else {
      \Drupal::logger('youtube_transcript')->error('Failed to retrieve transcript for term ' . $term->id() . '. Error: ' . $this->lastError);
      return FALSE;
    }
  }

  /**
   * Extracts YouTube video ID from different URL formats.
   */
  protected function extractVideoId($url) {
    if (preg_match('/v=([a-zA-Z0-9_-]+)/', $url, $matches)) {
      return $matches[1];
    }
    if (preg_match('/youtu\.be\/([a-zA-Z0-9_-]+)/', $url, $matches)) {
      return $matches[1];
    }
    return null;
  }

  /**
   * Fetch transcript from YouTube API.
   */
  protected function fetchTranscript($video_id) {
    \Drupal::logger('youtube_transcript')->notice('Fetching transcript for video ID: @video_id', ['@video_id' => $video_id]);
    $config = $this->configFactory->get('youtube_transcript.settings');
    $client = new Google_Client();
    $client->setClientId($config->get('google_client_id'));
    $client->setClientSecret($config->get('google_client_secret'));
    $client->setRedirectUri($config->get('google_redirect_uri'));
    $client->addScope(YouTube::YOUTUBE_FORCE_SSL);
    $client->setAccessType('offline');
    $client->setIncludeGrantedScopes(true);
    $client->setPrompt('consent');

    // Prepare the private directory.
    $file_system = \Drupal::service('file_system');
    $private_dir = $file_system->realpath('private://');
    if (!$private_dir) {
      $this->lastError = 'Private file system directory not found.';
      \Drupal::logger('youtube_transcript')->error($this->lastError);
      return null;
    }
    $token_file = $private_dir . '/youtube_oauth_token.json';
    $directory = 'private://';
    if (!$file_system->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY)) {
      $this->lastError = 'Failed to prepare private:// directory.';
      \Drupal::logger('youtube_transcript')->error($this->lastError);
      return null;
    }
    if (file_exists($token_file)) {
      $access_token = json_decode(file_get_contents($token_file), true);
      if ($access_token) {
        $client->setAccessToken($access_token);
      }
    }
    if ($client->isAccessTokenExpired()) {
      $refresh_token = $access_token['refresh_token'] ?? null;
      if (!$refresh_token) {
        $this->lastError = 'Missing refresh token. Please reauthenticate the Google account.';
        \Drupal::logger('youtube_transcript')->error($this->lastError);
        return null;
      }
      try {
        $new_access_token = $client->fetchAccessTokenWithRefreshToken($refresh_token);
        $new_access_token['refresh_token'] = $refresh_token;
        file_put_contents($token_file, json_encode($new_access_token));
        $client->setAccessToken($new_access_token);
      }
      catch (\Exception $e) {
        $this->lastError = 'Google OAuth token refresh failed: ' . $e->getMessage();
        \Drupal::logger('youtube_transcript')->error($this->lastError);
        return null;
      }
    }
    $youtube = new YouTube($client);
    try {
      $captions = $youtube->captions->listCaptions('snippet', $video_id);
      \Drupal::logger('youtube_transcript')->notice('Caption response for video ID @video_id: <pre>@response</pre>', [
        '@video_id' => $video_id,
        '@response' => print_r($captions, TRUE),
      ]);
      $manual_caption_id = null;
      $auto_caption_id = null;
      $caption_language = 'en';
      foreach ($captions->items as $caption) {
        if (isset($caption->snippet->language)) {
          $caption_language = $caption->snippet->language;
        }
        if ($caption->snippet->trackKind === 'standard') {
          $manual_caption_id = $caption->id;
          break;
        } elseif ($caption->snippet->trackKind === 'asr') {
          $auto_caption_id = $caption->id;
        }
      }
      if ($manual_caption_id) {
        // For manual captions, we no longer specify a format.
        return $this->downloadTranscript($manual_caption_id, $youtube, false, $caption_language);
      }
      elseif ($auto_caption_id) {
        return $this->downloadTranscript($auto_caption_id, $youtube, true, $caption_language);
      }
      else {
        $this->lastError = 'No caption track available.';
        \Drupal::logger('youtube_transcript')->error($this->lastError);
        return null;
      }
    }
    catch (\Exception $e) {
      $this->lastError = 'YouTube API error fetching captions: ' . $e->getMessage();
      \Drupal::logger('youtube_transcript')->error($this->lastError);
      return null;
    }
  }

  /**
   * Download transcript text.
   *
   * For auto-generated captions, we include the language parameter.
   * For manual captions, we no longer pass a tfmt parameter so that the API returns the default format (usually TTML).
   *
   * @param string $caption_id
   *   The caption track ID.
   * @param \Google\Service\YouTube $youtube
   *   The YouTube service instance.
   * @param bool $is_auto
   *   TRUE if downloading an auto-generated caption; defaults to FALSE.
   * @param string $lang
   *   (Optional) The language code to request; default "en".
   *
   * @return string|null
   *   The cleaned transcript text, or NULL on error.
   */
  protected function downloadTranscript($caption_id, YouTube $youtube, $is_auto = false, $lang = 'en') {
    if ($is_auto) {
      // For auto-generated captions, include the language parameter.
      try {
        $response = $youtube->captions->download($caption_id, ['tlang' => $lang]);
        $content = $response->getBody()->getContents();
        \Drupal::logger('youtube_transcript')->notice('Auto transcript raw content: <pre>@content</pre>', ['@content' => $content]);
        if (stripos(trim($content), '<?xml') === 0 || stripos(trim($content), '<tt') === 0) {
          return trim(strip_tags($content));
        }
        return trim($content);
      }
      catch (\Exception $e) {
        $this->lastError = 'Error downloading auto transcript: ' . $e->getMessage();
        if (method_exists($e, 'getResponse') && $e->getResponse()) {
          $raw_response = $e->getResponse()->getBody()->getContents();
          \Drupal::logger('youtube_transcript')->error('Auto transcript error response: <pre>@response</pre>', ['@response' => $raw_response]);
        }
        \Drupal::logger('youtube_transcript')->error($this->lastError);
        return null;
      }
    }
    else {
      // For manual captions, do not specify any tfmt parameter.
      try {
        \Drupal::logger('youtube_transcript')->notice('Downloading manual caption ID @caption_id without tfmt parameter', ['@caption_id' => $caption_id]);
        $response = $youtube->captions->download($caption_id);
        $content = $response->getBody()->getContents();
        \Drupal::logger('youtube_transcript')->notice('Manual caption raw content: <pre>@content</pre>', ['@content' => $content]);
        // Process TTML content by stripping XML tags.
        return trim(strip_tags($content));
      }
      catch (\Exception $e) {
        $this->lastError = 'Error downloading manual transcript: ' . $e->getMessage();
        if (method_exists($e, 'getResponse') && $e->getResponse()) {
          $raw_response = $e->getResponse()->getBody()->getContents();
          \Drupal::logger('youtube_transcript')->error('Manual transcript error response: <pre>@response</pre>', ['@response' => $raw_response]);
        }
        \Drupal::logger('youtube_transcript')->error($this->lastError);
        return null;
      }
    }
  }

  /**
   * Public method for testing transcript fetch.
   */
  public function testTranscript($video_id) {
    return $this->fetchTranscript($video_id);
  }
}
