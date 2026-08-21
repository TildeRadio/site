<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/api.php';

$directory = [
    'service' => 'tilderadio public api',
    'version' => 1,
    'endpoints' => [
        'now' => '/api/now/',
        'history' => '/api/history/',
        'schedule' => '/api/schedule/',
        'djs' => '/api/djs/',
        'episodes' => '/api/episodes/',
    ],
    'terminal' => [
        'now' => '/now/',
        'schedule' => '/schedule/',
        'djs' => '/djs/',
        'episodes' => '/episodes/',
    ],
];

if (!tr_api_docs_wants_html()) {
    tr_api_json($directory, 200, 60);
}

$title = 'public API';
$page_stylesheets = ['css/api.css'];
include dirname(__DIR__) . '/header.php';
?>

<section class="tr-section">
    <div class="tr-title"><span class="tr-badge">PUBLIC API</span></div>
    <h1>tilderadio, but machine-readable</h1>
    <p class="tr-lede">
        Small JSON endpoints for now playing, recent tracks, the schedule, DJ profiles, and completed live episodes.
        No API key is required for these public read-only endpoints.
    </p>
</section>

<section class="tr-section">
    <div class="tr-title"><span class="tr-badge">ENDPOINTS</span></div>
    <div class="tr-api-grid">
        <article class="tr-api-card">
            <h2><a href="<?= htmlspecialchars(asset('api/now/'), ENT_QUOTES, 'UTF-8') ?>">/api/now/</a></h2>
            <p>Current track, listeners, live-DJ state, artwork, and recent history.</p>
            <p class="tr-muted">cache: about 10 seconds</p>
        </article>
        <article class="tr-api-card">
            <h2><a href="<?= htmlspecialchars(asset('api/history/'), ENT_QUOTES, 'UTF-8') ?>">/api/history/</a></h2>
            <p>The recent track history currently reported by the station.</p>
            <p class="tr-muted">cache: about 10 seconds</p>
        </article>
        <article class="tr-api-card">
            <h2><a href="<?= htmlspecialchars(asset('api/schedule/'), ENT_QUOTES, 'UTF-8') ?>">/api/schedule/</a></h2>
            <p>Upcoming schedule entries with Unix timestamps and UTC data.</p>
            <p class="tr-muted">cache: about 60 seconds</p>
        </article>
        <article class="tr-api-card">
            <h2><a href="<?= htmlspecialchars(asset('api/djs/'), ENT_QUOTES, 'UTF-8') ?>">/api/djs/</a></h2>
            <p>DJ profiles merged with upcoming schedule information.</p>
            <p class="tr-muted">cache: about 60 seconds</p>
        </article>
        <article class="tr-api-card">
            <h2><a href="<?= htmlspecialchars(asset('api/episodes/'), ENT_QUOTES, 'UTF-8') ?>">/api/episodes/</a></h2>
            <p>Live and completed sets with resolved show/format metadata, stats, and automatic track logs.</p>
            <p class="tr-muted">cache: about 30 seconds</p>
        </article>
    </div>
</section>

<section class="tr-section">
    <div class="tr-title"><span class="tr-badge">RESPONSE SHAPE</span></div>
    <p>
        Every JSON response includes <code>api_version</code>, <code>status</code>, and <code>updated_at</code>.
        Existing endpoint fields remain at the top level so older consumers keep working.
    </p>
    <pre><code>{
  "api_version": 1,
  "status": "ok",
  "updated_at": 1786990000,
  "...": "endpoint-specific fields"
}</code></pre>
</section>

<section class="tr-section">
    <div class="tr-title"><span class="tr-badge">TERMINAL</span></div>
    <p>The human-facing DJ and schedule pages notice curl/wget and answer with plain text instead of HTML.</p>
    <div class="tr-api-examples">
        <pre><code>curl https://tilderadio.org/now/</code></pre>
        <pre><code>curl https://tilderadio.org/schedule/</code></pre>
        <pre><code>curl https://tilderadio.org/djs/</code></pre>
        <pre><code>curl https://tilderadio.org/episodes/</code></pre>
        <pre><code>curl https://tilderadio.org/api/now/</code></pre>
    </div>
    <p class="tr-muted">
        Add <code>?format=text</code> to <code>/schedule/</code> or <code>/djs/</code> to request text explicitly.
        Add <code>?format=html</code> to force the normal page from a terminal client.
    </p>
</section>

<section class="tr-section">
    <div class="tr-title"><span class="tr-badge">PUBLIC STREAMS</span></div>
    <ul class="tr-links">
        <li><a href="https://tilderadio.org/listen">https://tilderadio.org/listen</a> (default)</li>
        <li><a href="https://tilderadio.org/listen/ogg/192k">https://tilderadio.org/listen/ogg/192k</a></li>
        <li><a href="https://tilderadio.org/listen/ogg/320k">https://tilderadio.org/listen/ogg/320k</a></li>
        <li><a href="https://tilderadio.org/listen/mp3/192k">https://tilderadio.org/listen/mp3/192k</a></li>
        <li><a href="https://tilderadio.org/listen/mp3/320k">https://tilderadio.org/listen/mp3/320k</a></li>
    </ul>
</section>

<?php include dirname(__DIR__) . '/footer.php'; ?>
