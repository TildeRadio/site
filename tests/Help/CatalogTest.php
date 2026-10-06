<?php

declare(strict_types=1);

namespace TildeRadio\Site\Tests\Help;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use TildeRadio\Site\Help\Catalog;
use TildeRadio\Site\Help\Query;

final class CatalogTest extends TestCase
{
    public function testCatalogReferencesBlocksAndLocalLinksStayValid(): void
    {
        $catalog = new Catalog();
        self::assertCount(29, $catalog->articles());
        self::assertCount(8, $catalog->categories());
        foreach ($catalog->articles() as $article) {
            self::assertMatchesRegularExpression('/^[a-z0-9-]+$/', $article['id']);
            self::assertNotEmpty($article['title']);
            self::assertNotEmpty($article['summary']);
            self::assertNotEmpty($article['audience']);
            self::assertSame([], array_diff($article['audience'], ['dj', 'listener', 'admin']));
            self::assertArrayHasKey($article['category'], $catalog->categories());
            $ids = array_column($article['sections'], 'id');
            self::assertSame($ids, array_values(array_unique($ids)));
            foreach ($article['related'] as $id) {
                self::assertNotNull($catalog->find($id), $id);
            }
            foreach ($article['sections'] as $section) {
                self::assertMatchesRegularExpression('/^[a-z0-9-]+$/', $section['id']);
                self::assertNotEmpty($section['title']);
                foreach ($section['blocks'] as $block) {
                    self::assertContains($block['type'], ['paragraph', 'list', 'steps', 'code', 'table', 'links']);
                    if ($block['type'] === 'table') {
                        foreach ($block['rows'] as $row) {
                            self::assertCount(count($block['headers']), $row);
                        }
                    }
                    if ($block['type'] === 'links') {
                        foreach ($block['items'] as $link) {
                            self::assertNotEmpty($link['label']);
                            $url = parse_url($link['href']);
                            self::assertIsArray($url);
                            if (isset($url['scheme'])) {
                                self::assertSame('https', $url['scheme']);
                                continue;
                            }
                            self::assertFalse(str_contains($url['path'], '..'));
                            $path = dirname(__DIR__, 2) . '/' . $url['path'];
                            // /listen/ is provided by the production web-server stream proxy.
                            self::assertTrue($url['path'] === 'listen/' || file_exists($path), $link['href']);
                            if ($url['path'] === 'help/') {
                                parse_str($url['query'] ?? '', $query);
                                $target = $catalog->find($query['topic'] ?? '');
                                self::assertNotNull($target, $link['href']);
                                if (isset($url['fragment'])) {
                                    self::assertContains($url['fragment'], array_column($target['sections'], 'id'));
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    public function testSearchFindsCommandsAndRanksTaskGuides(): void
    {
        $catalog = new Catalog();
        self::assertSame('song-announcements', $catalog->search('!songs')[0]['article']['id']);
        self::assertSame('playlist', $catalog->search('!track')[0]['article']['id']);
        self::assertSame('toggle', $catalog->search('!songs')[0]['section']);
        self::assertNotEmpty($catalog->search('SONG announcements'));
        self::assertSame([], $catalog->search('song nonexistentword'));
        self::assertSame([], $catalog->search('.*'));
        self::assertSame([], $catalog->search('(?i)['));
        foreach ($catalog->search('', 'archive', 'dj') as $result) {
            self::assertSame('archive', $result['article']['category']);
            self::assertContains('dj', $result['article']['audience']);
        }
        self::assertSame([], $catalog->search('', 'admin', 'listener'));
    }

    public function testQueryRejectsArraysControlBytesOversizeAndInvalidFilters(): void
    {
        $catalog = new Catalog();
        foreach ([['q' => ['bad']], ['q' => str_repeat('x', 161)], ['q' => "\0"], ['q' => "\xff"], ['category' => 'no-such-category'], ['audience' => 'superuser'], ['topic' => ['bad']]] as $input) {
            try {
                Query::parse($input, $catalog);
                self::fail('Invalid input accepted');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        $query = Query::parse(['q' => '  !songs  ', 'audience' => 'dj'], $catalog);
        self::assertSame('!songs', $query->text);
        self::assertSame('dj', $query->audience);
        self::assertSame('café', Query::parse(['q' => 'café'], $catalog)->text);
        self::assertNull($catalog->find('../../etc/passwd'));
    }

    public function testLegacyHandbookAndCarrierAnchorsRemainAvailable(): void
    {
        $catalog = new Catalog();
        self::assertSame(['become', 'profile', 'connection', 'testing', 'going-live', 'linux-audio', 'help'], array_column($catalog->find('start-here')['sections'], 'id'));
        self::assertSame(['how-it-works', 'station', 'couch', 'live', 'dj', 'website', 'mastodon', 'handoff', 'games', 'set-commands', 'history', 'invite', 'flow'], array_column($catalog->find('carrier-overview')['sections'], 'id'));
    }
}
