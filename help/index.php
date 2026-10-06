<?php

declare(strict_types=1);

use TildeRadio\Site\Help\Catalog;
use TildeRadio\Site\Help\Query;

// Public help must remain readable when private authentication or Carrier is offline.
require_once dirname(__DIR__) . '/lib/Help/Catalog.php';
require_once dirname(__DIR__) . '/lib/Help/Query.php';
require_once __DIR__ . '/_view.php';

header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header('Cross-Origin-Opener-Policy: same-origin');
header('Cross-Origin-Resource-Policy: same-origin');
// Use the existing DJ-page policy: persistent navigation also runs the site's
// legacy inline page scripts after a visitor opens Help with the player running.
header("Content-Security-Policy: frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'");

$error = null;
$query = new Query();
$catalog = null;
$article = null;
try {
    $catalog = new Catalog();
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
        header('Allow: GET, HEAD');
        http_response_code(405);
        $error = 'Help and search accept GET requests only.';
    } else {
        $query = Query::parse($_GET, $catalog);
        $topic = $helpTopicOverride ?? $query->topic;
        if ($topic !== '') {
            $article = $catalog->find($topic);
            if ($article === null) {
                http_response_code(404);
                $error = 'That guide was not found. Search or browse the topics below.';
            }
        }
    }
} catch (InvalidArgumentException $exception) {
    http_response_code(400);
    $error = $exception->getMessage();
} catch (Throwable) {
    http_response_code(503);
    $error = 'Help is temporarily unavailable. Please try again shortly.';
    error_log('{"channel":"help","level":"error","message":"Help catalog unavailable"}');
}
header($error === null ? 'Cache-Control: public, max-age=300' : 'Cache-Control: no-store');
$categories = $catalog?->categories() ?? [];
$filtered = $query->text !== '' || $query->category !== '' || $query->audience !== '';
$results = $catalog?->search($query->text, $query->category, $query->audience) ?? [];
$title = tr_help_h($article['title'] ?? 'Help & guides');
$page_stylesheets = ['css/help.css'];
$page_nav_section = 'help';
require dirname(__DIR__) . '/header.php';
?>
<section class="tr-section tr-help" aria-labelledby="help-title">
    <a class="tr-help-skip" href="#help-content">Skip to guides</a>
    <nav class="tr-help-breadcrumb" aria-label="Breadcrumb"><a href="<?= tr_help_h(asset('/')) ?>">Home</a><span aria-hidden="true">/</span><?php if ($article !== null) : ?><a href="<?= tr_help_h(tr_help_url()) ?>">Help &amp; guides</a><span aria-hidden="true">/</span><span><?= tr_help_h($categories[$article['category']]['title']) ?></span><?php else : ?><span>Help &amp; guides</span><?php endif; ?></nav>
    <header class="tr-help-hero">
        <span class="tr-badge">TildeRadio help</span>
        <h1 id="help-title"><?= tr_help_h($article['title'] ?? 'What would you like to do?') ?></h1>
        <p class="tr-help-lede"><?= tr_help_h($article['summary'] ?? 'From your first stream to your next episode: find the right page, follow a short guide, or search a bot command.') ?></p>
    </header>
    <?php if ($article !== null) : ?><details class="tr-help-search-panel"><summary>Search guides and bot commands</summary><?php endif; ?>
    <form method="get" action="<?= tr_help_h(asset('help/')) ?>" class="tr-help-search" role="search" data-tr-help-search>
        <div class="tr-help-search-text"><label for="help-query">Search guides and bot commands</label><input type="search" id="help-query" name="q" value="<?= tr_help_h($query->text) ?>" maxlength="160" placeholder="Try !songs, book airtime, recording…" aria-describedby="help-search-hint"></div>
        <div><label for="help-category">Topic</label><select id="help-category" name="category"><option value="">All topics</option><?php foreach ($categories as $category) : ?><option value="<?= tr_help_h($category['id']) ?>"<?= $query->category === $category['id'] ? ' selected' : '' ?>><?= tr_help_h($category['title']) ?></option><?php endforeach; ?></select></div>
        <div><label for="help-audience">For</label><select id="help-audience" name="audience"><?php foreach (['' => 'Everyone', 'dj' => 'DJs', 'listener' => 'Listeners', 'admin' => 'Administrators'] as $role => $label) : ?><option value="<?= $role ?>"<?= $query->audience === $role ? ' selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></div>
        <button type="submit">Search</button>
        <p id="help-search-hint">Search titles, steps and commands. Use several words to narrow results. Search also works without JavaScript.</p>
    </form>
    <?php if ($article !== null) : ?></details><?php endif; ?>
    <?php if ($error !== null) : ?><p class="tr-help-notice" role="alert"><?= tr_help_h($error) ?></p><?php endif; ?>
    <div class="tr-help-layout">
        <aside class="tr-help-sidebar">
            <?php if ($article !== null) : ?><details class="tr-help-navigation"><summary>Browse guides</summary><?php endif; ?>
            <nav aria-label="Help topics"><?php if ($article === null) : ?><h2>Browse guides</h2><?php endif; ?><a href="<?= tr_help_h(tr_help_url()) ?>"<?= !$filtered && $article === null ? ' aria-current="page"' : '' ?>>All guides</a><?php foreach ($categories as $category) : ?><a href="<?= tr_help_h(tr_help_url(['category' => $category['id'], 'q' => $query->text, 'audience' => $query->audience])) ?>"<?= ($article['category'] ?? $query->category) === $category['id'] ? ' aria-current="page"' : '' ?>><?= tr_help_h($category['title']) ?></a><?php endforeach; ?></nav>
            <?php if ($article !== null) : ?></details><?php endif; ?>
            <?php if ($article !== null) : ?><details class="tr-help-navigation tr-help-toc"><summary>On this page</summary><nav aria-label="On this page"><?php foreach ($article['sections'] as $part) : ?><a href="#<?= tr_help_h($part['id']) ?>"><?= tr_help_h($part['title']) ?></a><?php endforeach; ?></nav></details><?php endif; ?>
            <p><a href="<?= tr_help_h(asset('dj/')) ?>">Open the DJ booth →</a></p>
        </aside>
        <div class="tr-help-content" id="help-content" tabindex="-1">
            <?php if ($article !== null) : ?>
                <p class="tr-help-meta">For <?= tr_help_h(implode(' · ', array_map(static fn (string $role): string => ['dj' => 'DJs', 'listener' => 'Listeners', 'admin' => 'Administrators'][$role], $article['audience']))) ?></p>
                <?php foreach ($article['sections'] as $part) : ?><section class="tr-help-article-section" id="<?= tr_help_h($part['id']) ?>"><h2><?= tr_help_h($part['title']) ?><a class="tr-help-permalink" href="<?= tr_help_h(tr_help_url(['topic' => $article['id']], $part['id'])) ?>" aria-label="<?= tr_help_h('Link to ' . $part['title']) ?>">#</a></h2><?php foreach ($part['blocks'] as $block) { tr_help_block($block); } ?></section><?php endforeach; ?>
                <?php if ($article['related'] !== []) : ?><section class="tr-help-related"><h2>Useful next guides</h2><div class="tr-help-cards"><?php foreach ($article['related'] as $related) : $next = $catalog?->find($related); if ($next !== null) { tr_help_card($next, $categories[$next['category']]['title']); } endforeach; ?></div></section><?php endif; ?>
                <p><a href="<?= tr_help_h(tr_help_url(['q' => $query->text, 'category' => $query->category, 'audience' => $query->audience])) ?>">← Back to <?= $filtered ? 'search results' : 'all guides' ?></a></p>
            <?php elseif ($catalog !== null) : ?>
                <?php if (!$filtered) : ?>
                    <section class="tr-help-paths"><h2>Start with your next step</h2><div class="tr-help-cards"><?php foreach (['start-here', 'booking', 'live-controls', 'broadcasts'] as $number => $id) : $next = $catalog->find($id); if ($next !== null) : ?><article class="tr-help-card"><span class="tr-help-kicker"><?= $number + 1 ?> / <?= tr_help_h($categories[$next['category']]['title']) ?></span><h3><a href="<?= tr_help_h(tr_help_url(['topic' => $id])) ?>"><?= tr_help_h($next['title']) ?></a></h3><p><?= tr_help_h($next['summary']) ?></p></article><?php endif; endforeach; ?></div></section>
                    <nav class="tr-help-role-paths" aria-label="Choose your role"><a href="<?= tr_help_h(tr_help_url(['audience' => 'dj'])) ?>">I’m a DJ</a><a href="<?= tr_help_h(tr_help_url(['audience' => 'listener'])) ?>">I’m listening</a><a href="<?= tr_help_h(tr_help_url(['audience' => 'admin'])) ?>">I manage the station</a><a href="<?= tr_help_h(tr_help_url(['topic' => 'commands'])) ?>">Find a bot command</a></nav>
                <?php endif; ?>
                <section aria-labelledby="help-results"><div class="tr-help-results-heading"><h2 id="help-results"><?= $filtered ? 'Search results' : 'Browse the full guide library' ?></h2><span><?= count($results) ?> <?= count($results) === 1 ? 'guide' : 'guides' ?></span></div>
                    <?php if ($query->text !== '') : ?><p>Matching “<?= tr_help_h($query->text) ?>”<?= $query->category !== '' ? ' in ' . tr_help_h($categories[$query->category]['title']) : '' ?>.</p><?php endif; ?>
                    <?php if ($results === []) : ?><div class="tr-help-empty"><h3>No guide matched</h3><p>Try fewer words, a command such as !track, or clear a topic/role filter.</p><a href="<?= tr_help_h(tr_help_url()) ?>">Clear search and filters</a></div><?php else : ?><div class="tr-help-cards"><?php foreach ($results as $result) { tr_help_card($result['article'], $categories[$result['article']['category']]['title'], $result['section'], ['q' => $query->text, 'category' => $query->category, 'audience' => $query->audience]); } ?></div><?php endif; ?>
                </section>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php require dirname(__DIR__) . '/footer.php'; ?>
