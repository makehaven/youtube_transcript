<?php

namespace Drupal\Tests\youtube_transcript\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\youtube_transcript\YoutubeTranscriptFetcher;

/**
 * Unit tests for YoutubeTranscriptFetcher::extractVideoId().
 *
 * @coversDefaultClass \Drupal\youtube_transcript\YoutubeTranscriptFetcher
 * @group youtube_transcript
 */
class YoutubeTranscriptExtractVideoIdTest extends UnitTestCase {

  /**
   * The fetcher under test.
   */
  protected YoutubeTranscriptFetcher $fetcher;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $this->fetcher = new YoutubeTranscriptFetcher($config_factory);
  }

  /**
   * @covers ::extractVideoId
   * @dataProvider provideVideoUrls
   */
  public function testExtractVideoId(string $url, ?string $expected): void {
    $this->assertSame($expected, $this->fetcher->extractVideoId($url));
  }

  /**
   * Data provider for testExtractVideoId.
   */
  public static function provideVideoUrls(): array {
    return [
      'standard watch URL'           => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
      'watch URL with extra params'  => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=42s', 'dQw4w9WgXcQ'],
      'youtu.be short URL'           => ['https://youtu.be/dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
      'youtu.be with query string'   => ['https://youtu.be/dQw4w9WgXcQ?t=42', 'dQw4w9WgXcQ'],
      'ID with hyphens and underscores' => ['https://youtu.be/a3-mI0ScY20', 'a3-mI0ScY20'],
      'ID with leading hyphen'       => ['https://youtu.be/-euPZm4tAjg', '-euPZm4tAjg'],
      'embed URL — not supported'    => ['https://www.youtube.com/embed/dQw4w9WgXcQ', NULL],
      'empty string'                 => ['', NULL],
      'non-YouTube URL'              => ['https://vimeo.com/123456789', NULL],
      'plain text'                   => ['not a URL at all', NULL],
      'URL without video ID param'   => ['https://www.youtube.com/watch?list=PLabcd', NULL],
    ];
  }

}
