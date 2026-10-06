<?php

declare(strict_types=1);

function tr_help_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** @param array<string,string> $parameters */
function tr_help_url(array $parameters = [], string $anchor = ''): string
{
    $parameters = array_filter($parameters, static fn (string $value): bool => $value !== '');

    return asset('help/') . ($parameters === [] ? '' : '?' . http_build_query($parameters, '', '&', PHP_QUERY_RFC3986))
        . ($anchor === '' ? '' : '#' . rawurlencode($anchor));
}

function tr_help_href(string $href): string
{
    if (str_starts_with($href, 'https://')) {
        return $href;
    }
    if (str_starts_with($href, '//') || str_contains($href, ':') || str_contains($href, '..')) {
        return '#';
    }

    return asset($href);
}

/** @param array<string,mixed> $block */
function tr_help_block(array $block): void
{
    switch ($block['type']) {
        case 'paragraph':
            echo '<p>' . tr_help_h($block['text']) . '</p>';
            break;
        case 'steps':
        case 'list':
            $tag = $block['type'] === 'steps' ? 'ol' : 'ul';
            echo '<' . $tag . ' class="tr-help-list">';
            foreach ($block['items'] as $item) {
                echo '<li>' . tr_help_h($item) . '</li>';
            }
            echo '</' . $tag . '>';
            break;
        case 'code':
            echo '<pre tabindex="0"><code>' . tr_help_h($block['text']) . '</code></pre>';
            break;
        case 'table':
            echo '<div class="tr-help-table" role="region" aria-label="Reference table" tabindex="0"><table><thead><tr>';
            foreach ($block['headers'] as $heading) {
                echo '<th scope="col">' . tr_help_h($heading) . '</th>';
            }
            echo '</tr></thead><tbody>';
            foreach ($block['rows'] as $row) {
                echo '<tr>';
                foreach ($row as $index => $cell) {
                    echo ($index === 0 ? '<th scope="row">' : '<td>') . tr_help_h($cell) . ($index === 0 ? '</th>' : '</td>');
                }
                echo '</tr>';
            }
            echo '</tbody></table></div>';
            break;
        case 'links':
            echo '<ul class="tr-help-actions">';
            foreach ($block['items'] as $link) {
                echo '<li><a href="' . tr_help_h(tr_help_href($link['href'])) . '"' . (str_starts_with($link['href'], 'https://') ? ' rel="noopener"' : '') . '>' . tr_help_h($link['label']) . '<span aria-hidden="true"> →</span></a></li>';
            }
            echo '</ul>';
            break;
    }
}

/**
 * @param array<string,mixed> $article
 * @param array<string,string> $search
 */
function tr_help_card(array $article, string $category, ?string $section = null, array $search = [], int $heading = 3): void
{
    $heading = $heading === 4 ? 'h4' : 'h3';
    echo '<article class="tr-help-card">';
    if ($category !== '') {
        echo '<span class="tr-help-kicker">' . tr_help_h($category) . '</span>';
    }
    echo '<' . $heading . '><a href="' . tr_help_h(tr_help_url(['topic' => $article['id']] + $search)) . '">' . tr_help_h($article['title']) . '</a></' . $heading . '><p>' . tr_help_h($article['summary']) . '</p>';
    if ($section !== null) {
        foreach ($article['sections'] as $part) {
            if ($part['id'] === $section) {
                echo '<a class="tr-help-match" href="' . tr_help_h(tr_help_url(['topic' => $article['id']] + $search, $section)) . '">Jump to: ' . tr_help_h($part['title']) . '</a>';
            }
        }
    }
    echo '</article>';
}
