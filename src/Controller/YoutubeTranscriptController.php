<?php

namespace Drupal\youtube_transcript\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\taxonomy\Entity\Term;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Url;
use Google_Client;

/**
 * Controller for YouTube Transcript module.
 */
class YoutubeTranscriptController extends ControllerBase {

  /**
   * Fetch and update transcript for a single term.
   */
  public function refreshTranscript($term_id) {
    $term = Term::load($term_id);
    if ($term) {
      $fetcher = \Drupal::service('youtube_transcript.fetcher');
      $fetcher->fetchAndStoreTranscript($term);
      \Drupal::messenger()->addStatus($this->t('Transcript updated.'));
    }
    return new TrustedRedirectResponse(\Drupal::request()->headers->get('referer'));
  }

  /**
   * Initiates the OAuth flow with Google.
   */
  public function authenticate() {
    $config = \Drupal::config('youtube_transcript.settings');
    $client = new Google_Client();
    $client->setClientId($config->get('google_client_id'));
    $client->setClientSecret($config->get('google_client_secret'));
    $client->setRedirectUri($config->get('google_redirect_uri'));
    // Request the proper scope for caption management.
    $client->addScope("https://www.googleapis.com/auth/youtube.force-ssl");

    // Request offline access to get a refresh token.
    $client->setAccessType('offline');
    // Force the consent screen to ensure a refresh token is issued.
    $client->setPrompt('consent');

    $authUrl = $client->createAuthUrl();
    return new TrustedRedirectResponse($authUrl);
  }

  /**
   * Handles the OAuth callback from Google.
   */
  public function oauthCallback(Request $request) {
    $config = \Drupal::config('youtube_transcript.settings');
    $client = new Google_Client();
    $client->setClientId($config->get('google_client_id'));
    $client->setClientSecret($config->get('google_client_secret'));
    $client->setRedirectUri($config->get('google_redirect_uri'));

    $code = $request->query->get('code');
    if ($code) {
      $token = $client->fetchAccessTokenWithAuthCode($code);
      if (isset($token['error'])) {
        \Drupal::messenger()->addError($this->t('Error fetching token: @error', ['@error' => $token['error']]));
      }
      else {
        // Resolve the private file system path.
        $private_path = \Drupal::service('file_system')->realpath('private://');
        if (!is_dir($private_path)) {
          \Drupal::messenger()->addError($this->t('Private file system directory not found: @path', ['@path' => $private_path]));
        }
        else {
          $token_file = $private_path . '/youtube_oauth_token.json';
          if (file_put_contents($token_file, json_encode($token)) === FALSE) {
            \Drupal::messenger()->addError($this->t('Failed to write token file. Check your file permissions.'));
          }
          else {
            \Drupal::messenger()->addStatus($this->t('Authentication successful and token saved.'));
          }
        }
      }
    }
    else {
      \Drupal::messenger()->addError($this->t('Authorization code not found.'));
    }
    // Redirect to the front page.
    return new TrustedRedirectResponse(Url::fromRoute('<front>', [], ['absolute' => TRUE])->toString());
  }
}
