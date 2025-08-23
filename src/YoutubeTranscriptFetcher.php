<?php

namespace Drupal\youtube_transcript;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\taxonomy\Entity\Term;
use Google\Client as Google_Client;
use Google\Service\YouTube;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Url;


/**
 * Fetches YouTube transcripts and updates taxonomy terms.
 */
class YoutubeTranscriptFetcher {
  protected $configFactory;
  protected $lastError = '';

  /** @var bool */
protected $ownerVerified = false;

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
/**
 * Fetch and store transcript for a given taxonomy term.
 */
public function fetchAndStoreTranscript(Term $term) {
  $tid = (int) $term->id();
  $title = $term->label();

  \Drupal::logger('youtube_transcript')->notice(
    'Starting transcript fetch for term: @tid (@title)',
    ['@tid' => $tid, '@title' => $title]
  );

  // Get the YouTube URL from the field.
  $youtube_urls = $term->get('field_badge_video')->getValue();
  $youtube_url = $youtube_urls[0]['input'] ?? '';

  if (trim($youtube_url) === '') {
    $this->lastError = "No YouTube URL found for term {$tid} ({$title}).";
    \Drupal::logger('youtube_transcript')->error($this->lastError);
    return FALSE;
  }

  // Extract the video ID (supports watch?v= and youtu.be/).
  $video_id = $this->extractVideoId($youtube_url);
  // Helpful links we can include in messages.
  $studio_link = $video_id ? "https://studio.youtube.com/video/{$video_id}/translations" : '';
  $watch_link  = $video_id ? "https://www.youtube.com/watch?v={$video_id}" : $youtube_url;

  if (!$video_id) {
    $this->lastError = "Invalid YouTube URL for term {$tid} ({$title}): {$youtube_url}";
    \Drupal::logger('youtube_transcript')->error($this->lastError);
    return FALSE;
  }

  // Fetch the transcript (this handles OAuth, caption listing, etc).
  $transcript = $this->fetchTranscript($video_id, $term);

  if ($transcript) {
    \Drupal::logger('youtube_transcript')->notice(
      'Transcript successfully retrieved for term @tid (@title).',
      ['@tid' => $tid, '@title' => $title]
    );

    // Save transcript to the field and persist the term.
    $term->set('field_badge_video_transcript', $transcript);
    $term->save();
    return TRUE;
  }
  else {
    // $this->lastError is already set by fetchTranscript(); append a helpful link.
    if ($studio_link) {
      $this->lastError .= " (YouTube Studio: {$studio_link})";
    } elseif ($watch_link) {
      $this->lastError .= " (Watch: {$watch_link})";
    }

    \Drupal::logger('youtube_transcript')->error(
      'Failed to retrieve transcript for term @tid (@title). Error: @err',
      ['@tid' => $tid, '@title' => $title, '@err' => $this->lastError]
    );
    return FALSE;
  }
}


  /**
   * Extracts YouTube video ID from different URL formats.
   */
  protected function extractVideoId($url) {
    // youtu.be/{id}
    if (preg_match('/youtu\.be\/([a-zA-Z0-9_-]{6,})/i', $url, $m)) {
      return $m[1];
    }
    // youtube.com/watch?v={id}&...
    $q = [];
    $parts = parse_url($url);
    if (!empty($parts['query'])) {
      parse_str($parts['query'], $q);
      if (!empty($q['v']) && preg_match('/^[a-zA-Z0-9_-]{6,}$/', $q['v'])) {
        return $q['v'];
      }
    }
    return null;
  }
  


/**
 * Verify the authenticated account owns (or at least has a channel).
 * Logs the channel title/ID for clarity.
 *
 * @throws \RuntimeException if no channel is associated to the auth account.
 */
/**
 * Verify the authenticated account has a YouTube channel.
 * - Caches success in Drupal State to avoid repeated calls (saves quota).
 * - On quotaExceeded, logs and returns (doesn't hard-fail the run).
 * - On real ownership problems, throws.
 */
protected function assertChannelOwnership(\Google\Service\YouTube $youtube): void {
  if ($this->ownerVerified) {
    return;
  }

  $state = \Drupal::state();
  if ($state->get('youtube_transcript.owner_verified') === TRUE) {
    $this->ownerVerified = true;
    return;
  }

  try {
    // Minimal fields to save quota.
    $resp = $youtube->channels->listChannels('id', [
      'mine' => true,
      'maxResults' => 1,
      'fields' => 'items(id)',
    ]);
  }
  catch (\Google\Service\Exception $e) {
    $msg = $e->getMessage();
    // If quota exceeded, skip ownership verification for now.
    if (stripos($msg, 'quotaExceeded') !== false) {
      \Drupal::logger('youtube_transcript')->warning(
        'Skipping channel ownership check this run due to quotaExceeded.'
      );
      return; // don't throw
    }
    throw $e; // other API errors should still bubble
  }

  $items = method_exists($resp, 'getItems') ? $resp->getItems() : ($resp->items ?? []);
  if (empty($items)) {
    throw new \RuntimeException('Authenticated Google account has no YouTube channel. Sign in with the channel owner account.');
  }

  $this->ownerVerified = true;
  $state->set('youtube_transcript.owner_verified', TRUE);
}



/**
 * Get cached manual caption ID for this term from the KeyValue store.
 */
protected function getCachedCaptionId(Term $term): ?string {
  $store = \Drupal::keyValue('youtube_transcript');
  $val = $store->get('caption_' . $term->id());
  return is_string($val) && $val !== '' ? $val : NULL;
}

/**
 * Cache manual caption ID for this term in the KeyValue store.
 */
protected function cacheCaptionId(Term $term, string $caption_id): void {
  $store = \Drupal::keyValue('youtube_transcript');
  $store->set('caption_' . $term->id(), $caption_id);

  $state = \Drupal::state();
  $all = $state->get('youtube_transcript.cached_terms', []);
  unset($all[(string) $term->id()]);
  $state->set('youtube_transcript.cached_terms', $all);

}





  /**
 * Fetch transcript from YouTube API.
 *
 * Prefers manual ("standard") captions. If only auto-generated ("asr")
 * captions exist, the API will not allow download — we detect that and
 * return a clear error instead of attempting a download.
 */

 
 protected function fetchTranscript($video_id, ?Term $term = NULL) {
  \Drupal::logger('youtube_transcript')->notice(
    'Fetching transcript for video ID: @video_id',
    ['@video_id' => $video_id]
  );

  // --- Build Google client from saved config ---
  $config = $this->configFactory->get('youtube_transcript.settings');

  $client = new Google_Client(); 
  $client->setClientId($config->get('google_client_id'));
  $client->setClientSecret($config->get('google_client_secret'));
  $client->setRedirectUri($config->get('google_redirect_uri'));
  $client->addScope(\Google\Service\YouTube::YOUTUBE_FORCE_SSL);
  $client->setAccessType('offline');
  $client->setIncludeGrantedScopes(true);
  $client->setPrompt('consent');

  // --- Ensure private token file exists / refresh if needed ---
  /** @var \Drupal\Core\File\FileSystemInterface $file_system */
  $file_system = \Drupal::service('file_system');

// Ensure private:// exists (prepareDirectory requires by-ref variable).
$directory = 'private://';
if (!$file_system->prepareDirectory($directory, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY)) {
  $this->lastError = 'Failed to prepare private:// directory.';
  \Drupal::logger('youtube_transcript')->error($this->lastError);
  return NULL;
}

// Resolve real path after ensuring the directory exists.
$private_real = $file_system->realpath($directory);
if (!$private_real) {
  $this->lastError = 'Private file system directory not found after preparation.';
  \Drupal::logger('youtube_transcript')->error($this->lastError);
  return NULL;
}

$token_file = $private_real . '/youtube_oauth_token.json';



  $access_token = NULL;

  if (is_readable($token_file)) {
    $access_token = json_decode((string) file_get_contents($token_file), TRUE);
    if (is_array($access_token)) {
      $client->setAccessToken($access_token);
    }
  }

  // If we still don't have a token, tell the admin to authenticate first.
  if (!$client->getAccessToken()) {
    $this->lastError = 'No OAuth token found. Click "Authenticate with Google" first.';
    \Drupal::logger('youtube_transcript')->error($this->lastError);
    return NULL;
  }

  // Refresh if expired.
  if ($client->isAccessTokenExpired()) {
    $refresh_token = $client->getRefreshToken();
    if (!$refresh_token && is_array($access_token)) {
      $refresh_token = $access_token['refresh_token'] ?? NULL;
    }
    if (!$refresh_token) {
      $this->lastError = 'Missing refresh token. Please reauthenticate the Google account.';
      \Drupal::logger('youtube_transcript')->error($this->lastError);
      return NULL;
    }
    try {
      $new = $client->fetchAccessTokenWithRefreshToken($refresh_token);
      // Preserve refresh token.
      $new['refresh_token'] = $refresh_token;
      file_put_contents($token_file, json_encode($new));
      $client->setAccessToken($new);
    }
    catch (\Exception $e) {
      $this->lastError = 'Google OAuth token refresh failed: ' . $e->getMessage();
      \Drupal::logger('youtube_transcript')->error($this->lastError);
      return NULL;
    }
  }

  // --- YouTube service ---
  $youtube = new YouTube($client);
  $this->assertChannelOwnership($youtube);

  // If a manual caption ID is already cached on this term, use it and skip captions.list
if ($term) {
  $cached = $this->getCachedCaptionId($term);
  if ($cached) {
    \Drupal::logger('youtube_transcript')->notice('Using cached caption ID @id for video @vid', ['@id' => $cached, '@vid' => $video_id]);
    return $this->downloadTranscript($cached, $youtube, FALSE, 'en');
  }
}


try {
  // List available caption tracks (partial response to save quota).
  $captions = $youtube->captions->listCaptions('snippet', $video_id, [
    'fields' => 'items(id,snippet/language,snippet/trackKind)'
  ]);

  // (Optional) very verbose dump — comment out once things are stable.
  \Drupal::logger('youtube_transcript')->notice(
    'Caption response for video ID @video_id: @dump',
    ['@video_id' => $video_id, '@dump' => print_r($captions, TRUE)]
  );

  // Get items first, then log how many we got.
  $items = method_exists($captions, 'getItems') ? $captions->getItems() : ($captions->items ?? []);
  \Drupal::logger('youtube_transcript')->notice(
    'Found @n caption track(s) for video @video_id',
    ['@n' => is_array($items) ? count($items) : 0, '@video_id' => $video_id]
  );

  $manual_caption_id = NULL;
  $asr_caption_id = NULL;
  $caption_language = 'en';

  foreach ($items as $item) {
    $snippet = method_exists($item, 'getSnippet') ? $item->getSnippet() : ($item->snippet ?? NULL);
    if ($snippet) {
      if (!empty($snippet->language)) {
        $caption_language = $snippet->language;
      } elseif (method_exists($snippet, 'getLanguage') && $snippet->getLanguage()) {
        $caption_language = $snippet->getLanguage();
      }
      $trackKind = method_exists($snippet, 'getTrackKind') ? $snippet->getTrackKind() : ($snippet->trackKind ?? NULL);

      if ($trackKind === 'standard' && !$manual_caption_id) {
        $manual_caption_id = method_exists($item, 'getId') ? $item->getId() : ($item->id ?? NULL);
        if ($term && $manual_caption_id) {
          $this->cacheCaptionId($term, $manual_caption_id);
        }
      } elseif ($trackKind === 'asr' && !$asr_caption_id) {
        $asr_caption_id = method_exists($item, 'getId') ? $item->getId() : ($item->id ?? NULL);
      }
    }
  }

    // Prefer manual captions.
    if ($manual_caption_id) {
      \Drupal::logger('youtube_transcript')->notice(
        'Downloading manual transcript for video @id',
        ['@id' => $video_id]
      );
      return $this->downloadTranscript($manual_caption_id, $youtube, FALSE, $caption_language);
    }

    // Only ASR exists -> YouTube API will not allow download. Explain and stop.
    if ($asr_caption_id) {
      $this->lastError = 'Only auto-generated (ASR) captions exist for this video; the YouTube API does not allow downloading ASR tracks. Upload a manual caption (SRT/VTT) in YouTube Studio and try again.';
      \Drupal::logger('youtube_transcript')->warning($this->lastError);
      return NULL;
    }

    // No captions at all.
    $this->lastError = 'No caption track available for this video.';
    \Drupal::logger('youtube_transcript')->error($this->lastError);
    return NULL;
  }
  catch (\Google\Service\Exception $e) {
    // Return the structured Google API error.
    $this->lastError = 'YouTube API error fetching captions: ' . $e->getMessage();
    \Drupal::logger('youtube_transcript')->error($this->lastError);
    return NULL;
  }
  catch (\Exception $e) {
    $this->lastError = 'Unexpected error fetching captions: ' . $e->getMessage();
    \Drupal::logger('youtube_transcript')->error($this->lastError);
    return NULL;
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
/**
 * Download a caption track and return plain text.
 *
 * - Manual ("standard") tracks: try SRT, then fallback (often TTML) and strip.
 * - Auto ("asr") tracks: not downloadable via API -> we block earlier,
 *   but keep guard here too.
 */
protected function downloadTranscript($caption_id, YouTube $youtube, $is_auto = false, $lang = 'en') {
  // Guard: API does not allow downloading ASR tracks.
  if ($is_auto) {
    $msg = 'Auto-generated (ASR) captions are not downloadable via the YouTube API.';
    \Drupal::logger('youtube_transcript')->warning($msg);
    $this->lastError = $msg;
    return NULL;
  }

  // Helper to clean text from SRT/TTML.
  $clean = static function (string $raw): string {
    if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) {
      $raw = substr($raw, 3);
    }
    $trimmed = ltrim($raw);

    // TTML/XML -> strip tags + decode entities + tidy whitespace.
    if (strlen($trimmed) && $trimmed[0] === '<') {
      $text = strip_tags($raw);
      $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
      $text = preg_replace('/[ \t]+/u', ' ', $text);
      $text = preg_replace('/\R{2,}/u', "\n\n", $text);
      return trim($text);
    }
    // SRT or plain text.
    return trim($raw);
  };

  // Try SRT first, then fallback (some tracks reject tfmt).
  try {
    try {
      \Drupal::logger('youtube_transcript')->notice(
        'Attempting SRT download for caption @id', ['@id' => $caption_id]
      );
      $response = $youtube->captions->download($caption_id, ['tfmt' => 'srt']);
    } catch (\Google\Service\Exception $e) {
      // If SRT request fails for any reason other than auth/forbidden, try no tfmt.
      $msg = $e->getMessage();
      // If this is a permissions issue, handle below in the outer catch.
      if (stripos($msg, '"forbidden"') !== false || stripos($msg, 'insufficient') !== false) {
        throw $e;
      }
      \Drupal::logger('youtube_transcript')->notice(
        'SRT not available for caption @id, retrying without tfmt. Error: @err',
        ['@id' => $caption_id, '@err' => $msg]
      );
      $response = $youtube->captions->download($caption_id);
    }

    $content = (string) $response->getBody();
    \Drupal::logger('youtube_transcript')->notice(
      'Caption raw content (first 400 chars): @preview',
      ['@preview' => mb_substr($content, 0, 400)]
    );
    return $clean($content);
  }
  catch (\Google\Service\Exception $e) {
    // Classify "forbidden" (not owner / third‑party contributions / ASR) and return NULL.
    $msg = $e->getMessage();

    // Extract common signals from Google error payload.
    $is_forbidden = stripos($msg, '"forbidden"') !== false
      || stripos($msg, 'not sufficient to download the caption track') !== false
      || stripos($msg, 'third-party contributions') !== false;

    if ($is_forbidden) {
      $this->lastError =
        'Permissions are not sufficient to download this caption track. ' .
        'Make sure you are authenticated as the channel owner/manager and that a manual (non‑ASR) caption exists. ' .
        'If the caption was added by a third party, upload your own SRT/VTT in YouTube Studio and try again.';
      \Drupal::logger('youtube_transcript')->warning($this->lastError . ' Google response: ' . $msg);
      return NULL; // Let the caller log a friendly message with Studio link and continue.
    }

    // Other Google errors (e.g., quotaExceeded) — bubble up with context.
    $msg = 'Error downloading transcript (Google Service): ' . $e->getMessage();
    \Drupal::logger('youtube_transcript')->error($msg);
    throw new \RuntimeException($msg, 0, $e);
  }
  catch (\Exception $e) {
    $msg = 'Error downloading transcript: ' . $e->getMessage();
    \Drupal::logger('youtube_transcript')->error($msg);
    throw $e;
  }
}



  /**
   * Public method for testing transcript fetch.
   */
  public function testTranscript($video_id) {
    return $this->fetchTranscript($video_id);
  }
}
