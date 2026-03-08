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
    $tids = \Drupal::entityTypeManager()->getStorage('taxonomy_term')
      ->getQuery()
      ->condition('vid', 'badges')
      ->accessCheck(FALSE)
      ->execute();
    $terms = Term::loadMultiple($tids);
    foreach ($terms as $term) {
      $this->fetcher->fetchAndStoreTranscript($term);
    }
    $this->output()->writeln("YouTube transcripts updated.");
  }

  /**
   * Fetch transcripts for tool_video paragraphs on item nodes.
   *
   * Processes all item nodes (or a single node if --nid is given). For each
   * tool_video paragraph with a YouTube URL, fetches the transcript and saves
   * it to field_video_transcript on the paragraph.
   *
   * @param array $options
   *   An associative array of options.
   *
   * @option nid
   *   Process only this node ID.
   * @option overwrite
   *   Overwrite existing transcripts (default: skip if already set).
   *
   * @command youtube_transcript:fetch-tool-videos
   * @usage drush youtube_transcript:fetch-tool-videos
   *   Fetch transcripts for all tool nodes.
   * @usage drush youtube_transcript:fetch-tool-videos --nid=4553
   *   Fetch transcripts only for node 4553.
   * @usage drush youtube_transcript:fetch-tool-videos --overwrite
   *   Re-fetch and overwrite all existing transcripts.
   */
  public function fetchToolVideoTranscripts(array $options = ['nid' => NULL, 'overwrite' => FALSE]) {
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $overwrite = (bool) $options['overwrite'];

    if (!empty($options['nid'])) {
      $nids = [(int) $options['nid']];
    }
    else {
      $nids = $storage->getQuery()
        ->condition('type', 'item')
        ->condition('status', 1)
        ->accessCheck(FALSE)
        ->execute();
    }

    $total = count($nids);
    $this->output()->writeln("Processing {$total} item node(s)...");

    $success = $skipped = $failed = 0;

    foreach ($storage->loadMultiple($nids) as $node) {
      if ($node->get('field_item_videos')->isEmpty()) {
        continue;
      }

      $this->output()->writeln('Node ' . $node->id() . ': ' . $node->getTitle());
      $results = $this->fetcher->fetchForNode($node, $overwrite);

      foreach ($results as $pid => $result) {
        if ($result === TRUE) {
          $this->output()->writeln("  [OK]  Paragraph {$pid}: transcript saved.");
          $success++;
        }
        elseif ($result === FALSE) {
          $this->output()->writeln("  [FAIL] Paragraph {$pid}: " . $this->fetcher->getLastError());
          $failed++;
        }
        else {
          $this->output()->writeln("  [SKIP] Paragraph {$pid}: already has transcript.");
          $skipped++;
        }
      }
    }

    $this->output()->writeln("Done. Saved: {$success}  Skipped: {$skipped}  Failed: {$failed}");
  }

}
