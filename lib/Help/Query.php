<?php

declare(strict_types=1);

namespace TildeRadio\Site\Help;

use InvalidArgumentException;

final readonly class Query
{
    public function __construct(public string $text = '', public string $category = '', public string $audience = '', public string $topic = '')
    {
    }

    /** @param array<string,mixed> $input */
    public static function parse(array $input, Catalog $catalog): self
    {
        $values = [];
        foreach (['q' => 160, 'category' => 40, 'audience' => 20, 'topic' => 80] as $key => $limit) {
            $value = $input[$key] ?? '';
            if (!is_string($value) || strlen($value) > $limit || preg_match('//u', $value) !== 1 || preg_match('/[\x00-\x1f\x7f]/u', $value)) {
                throw new InvalidArgumentException('Use a plain-text search of at most 160 bytes and choose the filters from the list.');
            }
            $values[$key] = trim($value);
        }
        if ($values['category'] !== '' && !isset($catalog->categories()[$values['category']])) {
            throw new InvalidArgumentException('Choose an available help category.');
        }
        if (!in_array($values['audience'], ['', 'dj', 'listener', 'admin'], true)) {
            throw new InvalidArgumentException('Choose DJs, listeners, administrators or everyone.');
        }

        return new self($values['q'], $values['category'], $values['audience'], $values['topic']);
    }
}
