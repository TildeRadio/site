<?php

declare(strict_types=1);

namespace TildeRadio\Site\Help;

use RuntimeException;

final class Catalog
{
    /** @var array<string,array<string,mixed>> */
    private array $articles = [];
    /** @var array<string,array<string,string>> */
    private array $categories = [];

    public function __construct(?string $path = null)
    {
        $path ??= dirname(__DIR__, 2) . '/data/help.json';
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Help catalog unavailable.');
        }
        $bytes = file_get_contents($path);
        if ($bytes === false || strlen($bytes) > 524288) {
            throw new RuntimeException('Help catalog unavailable.');
        }
        $data = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($data) || ($data['version'] ?? null) !== 1 || !is_array($data['categories'] ?? null) || !is_array($data['articles'] ?? null)) {
            throw new RuntimeException('Invalid help catalog.');
        }
        foreach ($data['categories'] as $category) {
            if (!is_array($category) || !is_string($category['id'] ?? null) || isset($this->categories[$category['id']])) {
                throw new RuntimeException('Invalid help category.');
            }
            $this->categories[$category['id']] = $category;
        }
        foreach ($data['articles'] as $article) {
            if (!is_array($article) || !is_string($article['id'] ?? null) || isset($this->articles[$article['id']]) || !isset($this->categories[$article['category'] ?? ''])) {
                throw new RuntimeException('Invalid help article.');
            }
            $this->articles[$article['id']] = $article;
        }
    }

    /** @return array<string,array<string,string>> */
    public function categories(): array
    {
        return $this->categories;
    }

    /** @return array<string,array<string,mixed>> */
    public function articles(): array
    {
        return $this->articles;
    }

    /** @return array<string,mixed>|null */
    public function find(string $id): ?array
    {
        return $this->articles[$id] ?? null;
    }

    /** @param array<string,mixed> $section */
    public static function sectionText(array $section): string
    {
        $text = [];
        array_walk_recursive($section, static function (mixed $value, string|int $key) use (&$text): void {
            if (is_string($value) && !in_array($key, ['id', 'type', 'href'], true)) {
                $text[] = $value;
            }
        });

        return implode(' ', $text);
    }

    /** @return list<array{article:array<string,mixed>,section:?string,score:int}> */
    public function search(string $query = '', string $category = '', string $audience = ''): array
    {
        $words = preg_split('/\s+/u', trim($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $results = [];
        foreach ($this->articles as $article) {
            if (($category !== '' && $article['category'] !== $category) || ($audience !== '' && !in_array($audience, $article['audience'], true))) {
                continue;
            }
            $sections = [];
            foreach ($article['sections'] as $section) {
                $sections[$section['id']] = self::sectionText($section);
            }
            $title = $article['title'];
            $summary = $article['summary'];
            $body = $title . ' ' . $summary . ' ' . $article['keywords'] . ' ' . implode(' ', $sections);
            $score = 0;
            foreach ($words as $word) {
                // Treat all input as literal text; even regex punctuation is searchable.
                $pattern = '/' . preg_quote($word, '/') . '/iu';
                if (preg_match($pattern, $body) !== 1) {
                    continue 2;
                }
                $score += preg_match($pattern, $title) === 1 ? 10 : (preg_match($pattern, $summary) === 1 ? 5 : (preg_match($pattern, $article['keywords']) === 1 ? 4 : 1));
            }
            $best = null;
            $bestScore = 0;
            foreach ($sections as $id => $text) {
                $matches = 0;
                foreach ($words as $word) {
                    $matches += preg_match('/' . preg_quote($word, '/') . '/iu', $text) === 1 ? 1 : 0;
                }
                if ($matches > $bestScore) {
                    $best = $id;
                    $bestScore = $matches;
                }
            }
            $results[] = ['article' => $article, 'section' => $best, 'score' => $score];
        }
        usort($results, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return $results;
    }
}
