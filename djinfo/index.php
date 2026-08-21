<?php

declare(strict_types=1);

$title = 'DJ handbook';
$page_stylesheets = ['css/djinfo.css'];

include dirname(__DIR__) . '/header.php';
?>

<section class="tr-section tr-djinfo-hero">
    <div class="tr-title"><span class="tr-badge">DJ HANDBOOK</span></div>
    <h1>broadcast on tilderadio</h1>
    <p class="tr-lede">
        Everything you need to get on the air, from asking for a slot to configuring your streaming software.
        Already have a DJ account? Jump straight to the connection details.
    </p>

    <div class="tr-djinfo-paths">
        <article>
            <span class="tr-djinfo-kicker">NEW HERE</span>
            <h2>want to become a DJ?</h2>
            <p>
                You do not need a polished show concept or radio experience. If you want to share music with the
                tildeverse, come say hello and tell us what you have in mind.
            </p>
            <a class="tr-djinfo-action" href="https://tilde.chat/kiwi/#tilderadio" rel="noopener">
                talk to us in #tilderadio
            </a>
        </article>

        <article>
            <span class="tr-djinfo-kicker">CURRENT DJ</span>
            <h2>ready to connect?</h2>
            <p>
                Keep your DJ username and password handy, choose an Icecast-compatible broadcaster, and use the
                connection settings below.
            </p>
            <a class="tr-djinfo-action" href="#connection">connection details</a>
        </article>
    </div>
</section>

<nav class="tr-djinfo-jump" aria-label="DJ handbook sections">
    <a href="#become">become a DJ</a>
    <a href="#profile">your profile</a>
    <a href="#connection">connect</a>
    <a href="#going-live">going live</a>
    <a href="#testing">testing</a>
    <a href="#linux-audio">linux audio</a>
    <a href="#help">help</a>
</nav>

<section class="tr-section" id="become">
    <div class="tr-title"><span class="tr-badge">BECOME A DJ</span></div>
    <h2>getting a slot</h2>

    <ol class="tr-djinfo-steps">
        <li>
            <strong>Join #tilderadio.</strong>
            <span>Introduce yourself and let us know you are interested in broadcasting.</span>
        </li>
        <li>
            <strong>Tell us what you want to do.</strong>
            <span>
                A regular show, occasional set, one-off experiment, or simply an hour of music are all reasonable
                starting points.
            </span>
        </li>
        <li>
            <strong>Get a DJ account and schedule slot.</strong>
            <span>Your streamer credentials are personal. Do not put them in a profile, repository, or public config.</span>
        </li>
        <li>
            <strong>Test before your first broadcast.</strong>
            <span>Make sure the server connection, audio routing, levels, and metadata all behave before show time.</span>
        </li>
    </ol>

    <p class="tr-muted">
        Not ready to broadcast yet? Listening, hanging out in IRC, and interacting with DJs are useful contributions too.
    </p>
</section>

<section class="tr-section" id="profile">
    <div class="tr-title"><span class="tr-badge">YOUR PROFILE</span></div>
    <h2>make your DJ page yours</h2>
    <p>
        Scheduled DJs get a basic page automatically. If you want a bio, avatar, show name, links, genres, favorites,
        or other details, add one JSON file named after your schedule/DJ slug.
    </p>

    <pre><code>git clone https://github.com/TildeRadio/site.git
cd site

cp data/djs/example.json.sample data/djs/your-nick.json
$EDITOR data/djs/your-nick.json
php bin/validate-djs.php</code></pre>

    <ul class="tr-djinfo-checklist">
        <li>The JSON filename is your profile slug. Keep it lowercase with letters, numbers, and hyphens.</li>
        <li>Delete any optional fields you do not want to publish.</li>
        <li>Never put your AzuraCast/DJ password, email credentials, or other secrets in the profile.</li>
        <li>Run <code>php bin/validate-djs.php</code> before submitting the change.</li>
        <li>A profile can be hidden temporarily with <code>"published": false</code>.</li>
    </ul>

    <p>
        The full field reference lives in <code>data/djs/README.md</code>. If your recurring show has different
        weekday formats, the profile can define them once and the schedule and Carrier will resolve the right one
        automatically. Episode titles remain optional and can be set live with
        <code>!show episode &lt;title&gt;</code>.
    </p>

    <p>
        If Git is not your thing, ask in
        <a href="https://tilde.chat/kiwi/#tilderadio" rel="noopener">#tilderadio</a> and somebody can help get the
        profile into place.
    </p>
</section>

<section class="tr-section" id="connection">
    <div class="tr-title"><span class="tr-badge">CONNECTION</span></div>
    <h2>live streamer settings</h2>

    <div class="tr-djinfo-connection">
        <div>
            <span>server</span>
            <strong>azuracast.tilderadio.org</strong>
        </div>
        <div>
            <span>port</span>
            <strong>8005</strong>
        </div>
        <div>
            <span>mount</span>
            <strong>/</strong>
        </div>
        <div>
            <span>mode</span>
            <strong>Icecast</strong>
        </div>
    </div>

    <p>
        Use the DJ username and password supplied with your account. If your broadcaster is being difficult,
        <strong>128 kbps MP3</strong> is a good compatibility baseline.
    </p>

    <div class="tr-djinfo-links">
        <a href="https://www.azuracast.com/docs/user-guide/streaming-software/" rel="noopener">
            AzuraCast streaming software guide
        </a>
        <a href="<?= htmlspecialchars(asset('butt.cfg.txt'), ENT_QUOTES, 'UTF-8') ?>">
            example BUTT config
        </a>
    </div>

    <div class="tr-djinfo-note">
        <strong>software:</strong>
        BUTT is a simple choice when you just need to send audio. Mixxx is useful when you want a fuller DJ interface,
        playlists, decks, and live mixing. Other Icecast-compatible broadcasters can work too.
    </div>
</section>

<section class="tr-section" id="going-live">
    <div class="tr-title"><span class="tr-badge">GOING LIVE</span></div>
    <h2>before you press the button</h2>

    <ul class="tr-djinfo-checklist">
        <li>Test your audio source and make sure it is not clipping.</li>
        <li>Make sure notifications, browser sounds, and other desktop audio are routed intentionally.</li>
        <li>Check that artist/title metadata is being sent if your setup supports it.</li>
        <li>Be around in <a href="https://tilde.chat/kiwi/#tilderadio" rel="noopener">#tilderadio</a> during your set when practical.</li>
        <li>Connect shortly before your scheduled slot and disconnect when you are finished so normal station automation can resume.</li>
    </ul>
</section>

<section class="tr-section" id="testing">
    <div class="tr-title"><span class="tr-badge">TESTING</span></div>
    <h2>test before taking over the main stream</h2>

    <p>
        The test broadcaster connection uses port <strong>8015</strong>. You can monitor the test station while
        configuring levels and routing:
    </p>

    <div class="tr-djinfo-links">
        <a href="https://azuracast.tilderadio.org/public/test" rel="noopener">test station page</a>
        <a href="https://azuracast.tilderadio.org/radio/8010/radio.ogg" rel="noopener">direct OGG test stream</a>
    </div>

    <p class="tr-muted">
        Testing first is strongly encouraged for a new setup or whenever you change broadcaster, audio device, or routing.
    </p>
</section>

<section class="tr-section" id="linux-audio">
    <div class="tr-title"><span class="tr-badge">LINUX AUDIO</span></div>
    <h2>routing desktop audio into your broadcaster</h2>

    <p>
        If you need to combine application audio and a microphone on a PulseAudio-style setup, this older but useful
        null-sink recipe is available as a starting point.
    </p>

    <details class="tr-djinfo-details">
        <summary>show PulseAudio routing example</summary>
        <pre><code># Setup virtual null sink &amp; two loopbacks
pulseaudio -k
pactl load-module module-null-sink sink_name=v1 sink_properties=device.description="v1"
pactl load-module module-loopback sink=v1
pactl load-module module-loopback sink=v1

# Run pavucontrol
pavucontrol &amp;

# Configure

Playback:
Loopbacks =&gt; v1
Applications =&gt; Headset stereo device

Recording:
Butt =&gt; Monitor of v1
Loopback 1 =&gt; Headset stereo device
Loopback 2 =&gt; Monitor of input device

All volume sliders to 100%</code></pre>
    </details>

    <p class="tr-muted">
        PipeWire systems may expose the same routing controls through PipeWire compatibility tools instead. The exact
        device names depend on your desktop and audio hardware.
    </p>
</section>

<section class="tr-section" id="help">
    <div class="tr-title"><span class="tr-badge">NEED HELP?</span></div>
    <h2>bring the problem to IRC</h2>
    <p>
        If your broadcaster will not connect, your audio is silent, or you are not sure how to route something,
        ask in <a href="https://tilde.chat/kiwi/#tilderadio" rel="noopener">#tilderadio</a>. Include the software and
        operating system you are using and the error message, but never paste your DJ password.
    </p>
    <p>
        <a href="<?= htmlspecialchars(asset('djs/'), ENT_QUOTES, 'UTF-8') ?>">browse DJs &amp; shows</a>
        &nbsp;&middot;&nbsp;
        <a href="<?= htmlspecialchars(asset('schedule/'), ENT_QUOTES, 'UTF-8') ?>">view the schedule</a>
    </p>
</section>

<?php include dirname(__DIR__) . '/footer.php'; ?>
