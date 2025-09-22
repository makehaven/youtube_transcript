<?php

namespace Drupal\youtube_transcript\Commands;

use Drush\Commands\DrushCommands;
use Drupal\taxonomy\Entity\Term;
use Drupal\youtube_transcript\YoutubeTranscriptFetcher;

/**
 * Drush commands for YouTube Transcript fetching.
 */
class YoutubeTranscriptCommands extends DrushCommands {

  protected $fetcher;

  public function __construct(YoutubeTranscriptFetcher $fetcher) {
    $this->fetcher = $fetcher;
  }

  /**
   * Fetch and update transcripts for all badge terms.
   *
   * @command youtube_transcript:fetch
   */
  public function fetchTranscripts() {
    $query = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->getQuery();
    $query->condition('vid', 'badges')->accessCheck(FALSE);
    $tids = $query->execute();
    $terms = Term::loadMultiple($tids);
    foreach ($terms as $term) {
      $this->fetcher->fetchAndStoreTranscript($term);
    }
    $this->output()->writeln("YouTube transcripts updated.");
  }
}
