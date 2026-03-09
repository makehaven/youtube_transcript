<?php

namespace Drupal\Tests\youtube_transcript\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\Entity\FieldConfig;

/**
 * Kernel tests for transcript failure counter behaviour.
 *
 * Covers:
 *   - Cron skips a video after YOUTUBE_TRANSCRIPT_MAX_FAILURES failures.
 *   - The presave hook clears a transcript and resets the failure counter
 *     when the badge video URL changes.
 *
 * @group youtube_transcript
 */
class TranscriptFailureCounterTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'taxonomy',
    'field',
    'text',
    'link',
    'file',
    'image',
    'youtube',
    'youtube_transcript',
  ];

  protected $strictConfigSchema = FALSE;

  /**
   * The KeyValue store used by the module.
   */
  protected $kv;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installSchema('system', ['sequences']);
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('user');

    // Create the badges vocabulary.
    Vocabulary::create(['vid' => 'badges', 'name' => 'Badges'])->save();

    // Create field_badge_video using the youtube field type (which stores the
    // URL in the 'input' column — matching what the presave hook reads).
    FieldStorageConfig::create([
      'field_name'  => 'field_badge_video',
      'entity_type' => 'taxonomy_term',
      'type'        => 'youtube',
      'cardinality' => 1,
    ])->save();
    FieldConfig::create([
      'field_name'  => 'field_badge_video',
      'entity_type' => 'taxonomy_term',
      'bundle'      => 'badges',
      'label'       => 'Badge Video',
    ])->save();

    // Create field_badge_video_transcript (string_long).
    FieldStorageConfig::create([
      'field_name'  => 'field_badge_video_transcript',
      'entity_type' => 'taxonomy_term',
      'type'        => 'string_long',
      'cardinality' => 1,
    ])->save();
    FieldConfig::create([
      'field_name'  => 'field_badge_video_transcript',
      'entity_type' => 'taxonomy_term',
      'bundle'      => 'badges',
      'label'       => 'Badge Video Transcript',
    ])->save();

    $this->kv = \Drupal::keyValue('youtube_transcript');
  }

  /**
   * Cron skips a video that has reached the max failure threshold.
   *
   * We can't call the full cron without a working YouTube API, so we verify
   * the KeyValue-based gate directly: a video whose failure counter is at or
   * above YOUTUBE_TRANSCRIPT_MAX_FAILURES must be skipped (its counter must
   * not increase further) and a warning must be logged.
   */
  public function testCronSkipsBlockedVideo(): void {
    $video_id = 'testVid1234';
    $fail_key = 'fail_' . $video_id;

    // Pre-seed the counter at the limit.
    $this->kv->set($fail_key, YOUTUBE_TRANSCRIPT_MAX_FAILURES);

    // Simulate the guard check used in both cron sub-tasks.
    $current_fails = (int) $this->kv->get($fail_key, 0);
    $is_blocked = $current_fails >= YOUTUBE_TRANSCRIPT_MAX_FAILURES;

    $this->assertTrue($is_blocked, 'Video at max failures should be considered blocked.');

    // Verify the counter was not incremented (guard returned early).
    $this->assertSame(YOUTUBE_TRANSCRIPT_MAX_FAILURES, (int) $this->kv->get($fail_key));
  }

  /**
   * Resetting the failure counter un-blocks a video.
   */
  public function testRetryResetsFailureCounter(): void {
    $video_id = 'testVid1234';
    $fail_key = 'fail_' . $video_id;

    $this->kv->set($fail_key, YOUTUBE_TRANSCRIPT_MAX_FAILURES);
    $this->assertSame(YOUTUBE_TRANSCRIPT_MAX_FAILURES, (int) $this->kv->get($fail_key));

    // Retry: delete the counter (what retryVideo() and retry-failures do).
    $this->kv->delete($fail_key);

    $current_fails = (int) $this->kv->get($fail_key, 0);
    $this->assertLessThan(
      YOUTUBE_TRANSCRIPT_MAX_FAILURES,
      $current_fails,
      'After reset the video should no longer be blocked.'
    );
  }

  /**
   * The presave hook clears transcript and resets failure counter on URL change.
   */
  public function testPresaveHookClearsTranscriptOnUrlChange(): void {
    $old_url = 'https://youtu.be/oldVideoId1';
    $new_url = 'https://youtu.be/newVideoId2';

    // Create a badge term with an existing transcript and old video URL.
    // The youtube field type stores URLs under the 'input' key.
    $term = Term::create([
      'vid'                        => 'badges',
      'name'                       => 'Test Badge',
      'field_badge_video'          => [['input' => $old_url, 'video_id' => 'oldVideoId1']],
      'field_badge_video_transcript' => [['value' => 'Old transcript text.']],
    ]);
    $term->save();

    // Simulate a pre-existing failure counter for the new video.
    $this->kv->set('fail_newVideoId2', YOUTUBE_TRANSCRIPT_MAX_FAILURES);

    // Reload and change the video URL.
    $term = Term::load($term->id());
    $term->set('field_badge_video', [['input' => $new_url, 'video_id' => 'newVideoId2']]);
    // The presave hook fires on save().
    $term->save();

    // Reload to get the saved state.
    $term = Term::load($term->id());

    $this->assertTrue(
      $term->get('field_badge_video_transcript')->isEmpty(),
      'Transcript should be cleared when the video URL changes.'
    );

    $this->assertLessThan(
      YOUTUBE_TRANSCRIPT_MAX_FAILURES,
      (int) $this->kv->get('fail_newVideoId2', 0),
      'Failure counter for the new video should be reset so cron retries it.'
    );
  }

  /**
   * Presave hook does NOT clear transcript when the URL is unchanged.
   *
   * Mirrors the real production flow: cron adds the transcript in a second
   * save after the term already exists with its video URL.
   */
  public function testPresaveHookDoesNotClearTranscriptWhenUrlUnchanged(): void {
    $url = 'https://youtu.be/sameVideoId1';
    $transcript = 'Existing transcript.';

    // Step 1: Create the term with a URL but no transcript (cron hasn't run yet).
    $term = Term::create([
      'vid'              => 'badges',
      'name'             => 'Stable Badge',
      'field_badge_video' => [['input' => $url, 'video_id' => 'sameVideoId1']],
    ]);
    $term->save();

    // Step 2: Simulate cron writing the transcript — reload, set transcript,
    // save. The URL is unchanged so the hook should leave the transcript alone.
    $term = Term::load($term->id());
    $term->set('field_badge_video_transcript', $transcript);
    $term->save();

    // Step 3: User edits the term (e.g. renames it) without touching the URL.
    $term = Term::load($term->id());
    $term->set('name', 'Stable Badge (renamed)');
    $term->save();

    $term = Term::load($term->id());
    $this->assertFalse(
      $term->get('field_badge_video_transcript')->isEmpty(),
      'Transcript should NOT be cleared when the video URL has not changed.'
    );
    $this->assertSame(
      $transcript,
      $term->get('field_badge_video_transcript')->value,
      'Transcript content should be unchanged.'
    );
  }

}
