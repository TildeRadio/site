<?php

declare(strict_types=1);

$community = require dirname(__DIR__, 2) . '/data/community.php';
$submissions = is_array($community['submissions'] ?? null) ? $community['submissions'] : [];
$irc = isset($submissions['irc']) && is_string($submissions['irc'])
    ? $submissions['irc']
    : 'https://tilde.chat/kiwi/#tilderadio';
$maxSeconds = is_int($submissions['max_station_id_seconds'] ?? null)
    ? $submissions['max_station_id_seconds']
    : 10;
$formats = is_array($submissions['preferred_formats'] ?? null)
    ? array_values(array_filter($submissions['preferred_formats'], 'is_string'))
    : ['FLAC', 'WAV', 'OGG'];

$title = 'contribute';
$page_stylesheets = ['css/community-contribute.css'];
include dirname(__DIR__, 2) . '/header.php';
?>

<section class="tr-section tr-contribute-hero">
    <div class="tr-title"><span class="tr-badge">CONTRIBUTE</span></div>
    <h1>put something of yours on tilderadio</h1>
    <p class="tr-lede">
        Record a TildeRadio station ID, make a jingle, pitch a strange one-off broadcast, or help with an event.
        You do not need Git knowledge to take part.
    </p>
</section>

<section class="tr-section">
    <div class="tr-contribute-grid">
        <article>
            <span class="tr-contribute-kicker">AUDIO</span>
            <h2>TildeRadio IDs &amp; jingles</h2>
            <p>Short pieces made for TildeRadio that can live on the community audio shelf and eventually turn up between shows or tracks.</p>
            <a href="#audio">audio guide &darr;</a>
        </article>
        <article>
            <span class="tr-contribute-kicker">EVENTS</span>
            <h2>one-offs &amp; takeovers</h2>
            <p>Theme nights, relays, guest sets, time capsules, experiments, or another idea that needs airtime.</p>
            <a href="#events">event guide &darr;</a>
        </article>
        <article>
            <span class="tr-contribute-kicker">BROADCAST</span>
            <h2>become a DJ</h2>
            <p>If what you really want is your own slot, the DJ handbook covers accounts, testing, and going live.</p>
            <a href="<?= htmlspecialchars(asset('djinfo/'), ENT_QUOTES, 'UTF-8') ?>">DJ handbook &rarr;</a>
        </article>
    </div>
</section>

<section class="tr-section" id="audio">
    <div class="tr-title"><span class="tr-badge">STATION IDs &amp; JINGLES</span></div>
    <h2>small audio with a little personality</h2>
    <p>
        TildeRadio station IDs should generally stay around <strong><?= $maxSeconds ?> seconds or less</strong>.
        Preferred source formats are <strong><?= htmlspecialchars(implode(', ', $formats), ENT_QUOTES, 'UTF-8') ?></strong>.
        The shelf can serve OGG/OGA, Opus, MP3, WAV, and FLAC files.
    </p>

    <ol class="tr-contribute-steps">
        <li><strong>Make the audio.</strong><span>Voice, music you created or can license, weird noises, terminal sounds, and other short ideas are all fair territory.</span></li>
        <li><strong>Check the levels.</strong><span>Avoid clipping and leave a little headroom. The ID should not arrive much louder than the stream around it.</span></li>
        <li><strong>Choose reuse terms.</strong><span>Tell us what license or permission should be attached to the submission.</span></li>
        <li><strong>Send it in.</strong><span>Use the repository workflow below, or simply bring the file and details to #tilderadio.</span></li>
    </ol>

    <div class="tr-contribute-note">
        <strong>rights:</strong> only submit audio you created or otherwise have permission to distribute under the terms you provide.
        A TildeRadio station ID is small, but copyright law remains annoyingly full-sized.
    </div>
</section>

<section class="tr-section" id="repository">
    <div class="tr-title"><span class="tr-badge">REPOSITORY WORKFLOW</span></div>
    <h2>one audio file + one JSON file</h2>
    <p>If you already work with Git, clone the site repository first:</p>

    <pre><code>git clone https://github.com/TildeRadio/site.git
cd site</code></pre>

    <p>From the repository root, add your audio and metadata:</p>

    <pre><code>community/audio/your-nick-tilderadio-id.ogg
data/community/audio/your-nick-tilderadio-id.json</code></pre>

    <p>Start with the sample metadata:</p>
    <pre><code>cp data/community/audio/example.json.sample \
   data/community/audio/your-nick-tilderadio-id.json</code></pre>

    <pre><code>{
  "title": "TildeRadio ID by your-nick",
  "by": "your-nick",
  "file": "your-nick-tilderadio-id.ogg",
  "description": "A very short description.",
  "license": "CC BY 4.0",
  "url": "https://example.com/",
  "published": true
}</code></pre>

    <p>
        The <code>file</code> value names a file directly inside <code>community/audio/</code>.
        The filename identifies the contributed audio clip, not a separate radio station. JSON filenames use lowercase
        letters, numbers, and hyphens. Remove optional fields you do not need.
    </p>
    <p class="tr-muted">
        The loader rejects malformed JSON, unsafe filenames, missing audio files, oversized metadata, and unsupported audio extensions instead of breaking the page.
    </p>
</section>

<section class="tr-section" id="events">
    <div class="tr-title"><span class="tr-badge">COMMUNITY EVENTS</span></div>
    <h2>pitch the idea before building machinery around it</h2>
    <p>For a one-off show, takeover, relay, or themed event, bring the idea to IRC first. Useful details are:</p>
    <ul class="tr-contribute-list">
        <li>a short title or concept;</li>
        <li>rough length and a few possible dates/times;</li>
        <li>who wants to participate;</li>
        <li>whether it needs a live DJ slot, several handoffs, or just station promotion;</li>
        <li>a short description or external details page, if one exists.</li>
    </ul>
    <p>
        Once an event is settled, its public listing lives in <code>data/community.php</code> and automatically appears on the community page with localized times.
    </p>
</section>

<section class="tr-section" id="send">
    <div class="tr-title"><span class="tr-badge">SEND SOMETHING</span></div>
    <h2>IRC is the front door</h2>
    <p>
        You can submit through the source repository when that is convenient, but it is not a requirement.
        Drop into <a href="<?= htmlspecialchars($irc, ENT_QUOTES, 'UTF-8') ?>" rel="noopener">#tilderadio</a>, say what you have, and we can work out the easiest way to get it onto the station.
    </p>
    <p>
        <a href="<?= htmlspecialchars(asset('community/'), ENT_QUOTES, 'UTF-8') ?>">&larr; back to community</a>
        &nbsp;&middot;&nbsp;
        <a href="<?= htmlspecialchars(asset('djinfo/'), ENT_QUOTES, 'UTF-8') ?>">DJ handbook</a>
    </p>
</section>

<?php include dirname(__DIR__, 2) . '/footer.php'; ?>
