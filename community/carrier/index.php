<?php

declare(strict_types=1);

$title = 'Carrier';
$page_stylesheets = ['css/carrier.css'];
include dirname(__DIR__, 2) . '/header.php';
?>

<section class="tr-section">
    <div class="tr-title"><span class="tr-badge">CARRIER</span></div>
    <h1>TildeRadio in IRC</h1>
    <p class="tr-lede">
        Carrier is the bridge between the live TildeRadio stream, the schedule, DJ profiles, IRC, and the website.
        It watches what the station is already doing, then turns each live broadcast into a numbered set with useful
        show context, listener interaction, a track log, and a permanent transmission entry on the site.
    </p>
    <p>
        DJs do not start or stop Carrier manually. Go live normally and Carrier notices the stream transition.
        You can use it in <code>#tilderadio</code>, or invite it to another channel on a network where it is connected.
    </p>
</section>

<section class="tr-section">
    <div class="tr-carrier-grid">
        <article class="tr-carrier-card">
            <span class="tr-carrier-kicker">LISTENING</span>
            <h2>check the station</h2>
            <p>See what is playing, who is live, how many people are listening, or what is coming up next.</p>
            <pre><code>!np
!listeners
!dj
!next
!schedule</code></pre>
        </article>
        <article class="tr-carrier-card">
            <span class="tr-carrier-kicker">LIVE SHOWS</span>
            <h2>take part</h2>
            <p>Check into the couch, give the DJ props, ask a question, react, or send a request when requests are open.</p>
            <pre><code>!tunein tilde.club
!props
!ask what was that last track?
!request Artist - Track</code></pre>
        </article>
        <article class="tr-carrier-card">
            <span class="tr-carrier-kicker">YOUR CHANNEL</span>
            <h2>bring Carrier with you</h2>
            <p>Invite Carrier to another channel for TildeRadio commands. Station announcements can be enabled separately.</p>
            <pre><code>/invite carrier #yourchannel
!carrier status
!carrier announce on</code></pre>
        </article>
    </div>
</section>

<section class="tr-section" id="how-it-works">
    <div class="tr-title"><span class="tr-badge">HOW IT FITS TOGETHER</span></div>
    <h2>Carrier does not replace the stream or the website</h2>
    <p>
        Each part of TildeRadio has a different job. Carrier joins those pieces together instead of becoming another
        place where the same information has to be maintained by hand.
    </p>

    <ol class="tr-carrier-steps">
        <li><strong>AzuraCast says what is live.</strong><span>Carrier watches the live DJ, current track, listener count, and track changes.</span></li>
        <li><strong>The schedule says who is next.</strong><span>That lets the next scheduled DJ prepare their show metadata before taking the stream.</span></li>
        <li><strong>The DJ profile says what the show is.</strong><span>The website profile supplies the recurring show title, timezone, and optional weekday-specific formats.</span></li>
        <li><strong>IRC adds what is unique tonight.</strong><span><code>!show</code> can add an episode title, topic, mood, prompt, note, or link for this one set.</span></li>
        <li><strong>Carrier records what actually happened.</strong><span>Tracks and live-set activity are collected automatically while the DJ is on air.</span></li>
        <li><strong>The website gets the finished transmission.</strong><span>The same set appears in the transmission archive and on the DJ's profile without a second playlist or post-show form.</span></li>
    </ol>

    <div class="tr-carrier-note">
        <strong>short version:</strong> AzuraCast says <em>when</em>, the DJ profile says <em>what show</em>, IRC says
        <em>what is special about this episode</em>, and Carrier ties it together.
    </div>
</section>

<section class="tr-section" id="station">
    <div class="tr-title"><span class="tr-badge">STATION COMMANDS</span></div>
    <h2>the useful everyday stuff</h2>

    <div class="tr-carrier-commands">
        <div><code>!np</code><span>current track, live DJ, and listener count</span></div>
        <div><code>!listeners</code><span>current listener count</span></div>
        <div><code>!dj [name]</code><span>current DJ or a DJ profile</span></div>
        <div><code>!next</code><span>next scheduled show</span></div>
        <div><code>!unn</code><span>the show after the next one</span></div>
        <div><code>!schedule [N]</code><span>upcoming schedule</span></div>
        <div><code>!listen</code><span>available TildeRadio stream URLs</span></div>
        <div><code>!status</code><span>current station state with the listen URL</span></div>
        <div><code>!site</code><span>TildeRadio website</span></div>
        <div><code>!utc</code><span>current UTC time</span></div>
    </div>

    <div class="tr-carrier-flow">
        <strong>example</strong>
        <pre><code>&lt;alice&gt; !np
&lt;carrier&gt; SIGNAL LOCKED | deepend | Apashe - Lord &amp; Master | 12 listeners

&lt;alice&gt; !next
&lt;carrier&gt; NEXT | ffog | Wed Aug 19 23:00 UTC</code></pre>
    </div>
</section>

<section class="tr-section" id="couch">
    <div class="tr-title"><span class="tr-badge">THE RADIO COUCH</span></div>
    <h2>who is actually hanging around</h2>
    <p>
        The couch is an opt-in roll call. Checking in can include your tilde community, which lets Carrier show which
        parts of the tildeverse are listening during a set.
    </p>

    <pre><code>!tunein
!tunein tilde.club
!tuneout
!couch
!rollcall
!tildes</code></pre>

    <p>
        Check-ins expire automatically if you disappear for long enough. Carrier may also notice when enough people
        from the same tilde are checked into one live set.
    </p>

    <div class="tr-carrier-flow">
        <strong>example</strong>
        <pre><code>&lt;alice&gt; !tunein tilde.club
&lt;carrier&gt; COUCH | alice | tilde.club | 6 checked in

&lt;bob&gt; !tildes
&lt;carrier&gt; TILDEVERSE | tilde.club 3 | ctrl-c.club 2 | thunix.net 1</code></pre>
    </div>
</section>

<section class="tr-section" id="live">
    <div class="tr-title"><span class="tr-badge">DURING A LIVE SET</span></div>
    <h2>props, questions, reactions, and requests</h2>

    <h3>Props</h3>
    <p><code>!props</code> gives the current DJ one prop. Each IRC identity can do this once per set.</p>

    <h3>Questions</h3>
    <p>Ask something without making the DJ dig through IRC scrollback:</p>
    <pre><code>!ask how did you find this band?</code></pre>
    <p>The question goes into the live DJ's queue. The DJ can take or skip questions when there is a good point in the show.</p>

    <h3>Reactions</h3>
    <p>Quick reactions are intentionally quiet. Carrier collects them and summarizes the room instead of replying to every one.</p>
    <pre><code>!fire
!love
!bass
!lol
!wtf
!banger
!weird
!questionable

!react fire
!reactions
!vibe</code></pre>

    <h3>Requests</h3>
    <p>
        Requests are for <strong>live DJs only</strong>. Carrier does not send requests to AutoDJ, and every live set starts
        with requests closed. The DJ decides when to open them.
    </p>
    <pre><code>!request Artist - Track
!dedicate Artist - Track | nick | optional message</code></pre>

    <div class="tr-carrier-flow">
        <strong>request flow</strong>
        <pre><code>&lt;deepend&gt; !requests on
&lt;carrier&gt; REQUEST LINE OPEN | !request &lt;artist - track&gt;

&lt;alice&gt; !request Massive Attack - Teardrop
&lt;carrier&gt; REQUEST LOCKED | #23 | for deepend

&lt;deepend&gt; !played 23
&lt;carrier&gt; REQUEST CLEARED | Massive Attack - Teardrop | requested by alice</code></pre>
    </div>
</section>

<section class="tr-section" id="dj">
    <div class="tr-title"><span class="tr-badge">DJ CONTROLS</span></div>
    <h2>your recurring show lives on the site; tonight's details live in Carrier</h2>
    <p>
        Carrier uses the DJ identity from the schedule, IRC account mappings, and the DJ profile's <code>irc</code> value
        to decide who may control a set. The recurring parts of a show belong in the website profile so they do not need
        to be typed into IRC every week.
    </p>

    <h3>Recurring show and format information</h3>
    <p>
        A DJ profile may define one overall show plus different recurring formats for different weekdays.
        Carrier resolves the format when the set actually begins, using <code>show.timezone</code>. If no timezone is
        supplied, it uses UTC.
    </p>
    <pre><code>"show": {
  "title": "~/deepend",
  "timezone": "America/Edmonton",
  "formats": [
    {
      "id": "pull",
      "days": ["tuesday"],
      "title": "~/pull",
      "tagline": "Interesting things that escaped the algorithm."
    },
    {
      "id": "dig",
      "days": ["saturday"],
      "title": "~/dig",
      "tagline": "One musical rabbit hole at a time."
    }
  ]
}</code></pre>
    <p>
        The website can display those formats on the DJ profile, and Carrier stores the resolved show and format with
        the transmission. A local Tuesday show therefore stays Tuesday even if UTC has already crossed midnight.
    </p>

    <h3>Before you go live</h3>
    <p>
        If nobody is live and you are the <strong>next scheduled DJ</strong>, <code>!show</code> works as a staging area.
        This is useful for naming the episode and setting tonight's context before you connect to the stream.
    </p>
    <pre><code>!show episode songs I found through IRC
!show topic accidental discoveries
!show mood late-night
!show prompt what track did the internet lead you to?
!show note requests after the first half hour
!show link https://example.com/notes
!show
!show clear mood</code></pre>
    <p>
        These queued values are attached automatically when your live set starts, then the queue is cleared. You do not
        need a separate <code>!start</code> command.
    </p>

    <h3>While you are live</h3>
    <p>
        <code>!show</code> displays the resolved recurring show/format and the metadata for the current set. The same
        fields can be changed while live. <code>episode</code> is the best place for the title of this particular broadcast.
    </p>

    <div class="tr-carrier-two">
        <div>
            <h3>Show context</h3>
            <pre><code>!show
!show episode songs I found through IRC
!show topic accidental discoveries
!show mood questionable
!show prompt what song did you find by accident?
!show note requests later
!show link https://example.com/
!show clear mood</code></pre>
            <p>Episode metadata becomes part of the archived transmission and is updated on the website export while the set is live.</p>
        </div>
        <div>
            <h3>Question queue</h3>
            <pre><code>!questions
!qanswer 12
!qskip 13</code></pre>
            <p><code>!questions</code> is sent privately to the DJ so the public channel does not get a wall of queued questions.</p>
        </div>
        <div>
            <h3>Request queue</h3>
            <pre><code>!requests on
!requests list
!played 23
!reject 24
!requests off</code></pre>
            <p>The queue is private to the DJ. Every set begins with requests closed; open them only if they fit the show.</p>
        </div>
        <div>
            <h3>Listener goal</h3>
            <pre><code>!goal 10
!goal 15 if we hit this I play the terrible cover
!goal clear</code></pre>
            <p>Carrier announces the goal once the live listener count reaches it.</p>
        </div>
    </div>

    <h3>Mastodon from the booth</h3>
    <pre><code>!show mastodon tonight is mostly weird synths</code></pre>
    <p>
        This is intentionally separate from the archived episode fields. It sends one short station transmission to
        Mastodon during the live set rather than becoming permanent show metadata.
    </p>
</section>

<section class="tr-section" id="website">
    <div class="tr-title"><span class="tr-badge">WEBSITE INTEGRATION</span></div>
    <h2>the archive builds itself while you broadcast</h2>
    <p>
        Carrier records track changes from AzuraCast during the live set and exports a public episode archive for the
        website. The DJ does not need to re-enter a playlist after the show.
    </p>

    <div class="tr-carrier-two">
        <div>
            <h3>On the DJ profile</h3>
            <p>
                Recurring show formats are displayed with their days, and recent Carrier transmissions are linked directly
                from the DJ's profile.
            </p>
            <pre><code>https://tilderadio.org/djs/?dj=deepend</code></pre>
        </div>
        <div>
            <h3>Transmission archive</h3>
            <p>
                Each set can include its episode title, resolved show/format, start time, duration, track log, and the
                station activity Carrier observed.
            </p>
            <pre><code>https://tilderadio.org/episodes/</code></pre>
        </div>
        <div>
            <h3>Public API</h3>
            <p>The same archive is available as read-only JSON for small tools, bots, and other tilde projects.</p>
            <pre><code>https://tilderadio.org/api/episodes/</code></pre>
        </div>
        <div>
            <h3>Automatic track log</h3>
            <p>
                Carrier watches the now-playing metadata while the set is live, deduplicates repeated polls, and records
                each track it actually sees. Good stream metadata therefore produces a better archive automatically.
            </p>
        </div>
    </div>

    <div class="tr-carrier-note">
        <strong>no duplicate paperwork:</strong> edit the recurring show/profile in the website repository; use IRC for
        tonight's episode details; let Carrier collect the live history.
    </div>
</section>

<section class="tr-section" id="mastodon">
    <div class="tr-title"><span class="tr-badge">MASTODON</span></div>
    <h2>selected station activity can leave IRC</h2>
    <p>
        When Mastodon posting is enabled, Carrier can publish a few station events outside IRC. It does not post every
        command, reaction, track change, or listener change.
    </p>

    <div class="tr-carrier-two">
        <div>
            <h3>Automatic posts</h3>
            <p>
                Live starts and DJ handoffs are the main notices. Carrier can use the resolved show/format and current
                episode context when it announces a live set. Optional posts can also include configured listener
                milestones, selected station milestones, new station records, and noteworthy set summaries.
            </p>
        </div>
        <div>
            <h3>DJ transmission</h3>
            <pre><code>!show mastodon tonight is mostly weird synths</code></pre>
            <p>The current DJ can send one short Mastodon transmission during a live set.</p>
        </div>
    </div>

    <p>
        By default, a completed set is noteworthy if it runs for at least 90 minutes, peaks at 20 listeners, reaches
        8 couch check-ins, 20 props, 5 requests, 5 represented tildes, or sets a new station record.
    </p>

    <div class="tr-carrier-note">
        <strong>kept quiet:</strong> optional posts share a 15-minute cooldown, published events are remembered across
        restarts, and Mastodon/API failures are logged without interrupting IRC or station tracking.
    </div>
</section>

<section class="tr-section" id="handoff">
    <div class="tr-title"><span class="tr-badge">DJ HANDOFFS</span></div>
    <h2>tell Carrier who is taking the stream next</h2>
    <p>Before leaving the stream, the outgoing DJ can name the expected replacement:</p>
    <pre><code>!handoff ffog</code></pre>
    <p>
        Carrier keeps the expected handoff through a short AutoDJ gap. When the next DJ appears, it can recognize the relay
        instead of treating it as an unrelated new show.
    </p>

    <div class="tr-carrier-flow">
        <strong>example</strong>
        <pre><code>&lt;deepend&gt; !handoff ffog
&lt;carrier&gt; RELAY ARMED | deepend -&gt; ffog

... AutoDJ briefly holds the stream ...

&lt;carrier&gt; RELAY INTACT | ffog has the stream | 11 listeners</code></pre>
    </div>
</section>

<section class="tr-section" id="games">
    <div class="tr-title"><span class="tr-badge">ROOM STUFF</span></div>
    <h2>polls, challenges, bingo, battles, and The Button</h2>

    <div class="tr-carrier-two">
        <div>
            <h3>Polls</h3>
            <pre><code>!poll start best editor? | vim | emacs | ed
!poll
!vote 3
!poll stop</code></pre>
            <p>Votes can be changed until the poll closes.</p>
        </div>
        <div>
            <h3>Challenges</h3>
            <pre><code>!challenge name a song you secretly love
!answer Barbie Girl
!challenge results
!challenge random
!challenge stop</code></pre>
        </div>
        <div>
            <h3>Bingo</h3>
            <pre><code>!bingo</code></pre>
            <p>Carrier privately gives you a randomized 3x3 card for the live set. Some squares are marked automatically.</p>
        </div>
        <div>
            <h3>Tilde battles</h3>
            <pre><code>!battle start tilde.club | ctrl-c.club
!battle
!battle stop</code></pre>
            <p>Check in with <code>!tunein your.tilde</code> to identify your side. The prize is the score itself.</p>
        </div>
    </div>

    <h3>The Button</h3>
    <pre><code>!button</code></pre>
    <p>The Button has a cooldown and chooses one of a small set of station actions. Carrier does not explain The Button.</p>
</section>

<section class="tr-section" id="set-commands">
    <div class="tr-title"><span class="tr-badge">TEMPORARY COMMANDS</span></div>
    <h2>commands that only exist for one show</h2>
    <p>A live DJ can add a simple command for show-specific information:</p>
    <pre><code>!setcmd lore https://example.com/show-lore
!setcmd rules no rules
!delcmd rules</code></pre>
    <p>Listeners can then use <code>!lore</code> like a normal command. Built-in Carrier commands cannot be replaced.</p>
</section>

<section class="tr-section" id="history">
    <div class="tr-title"><span class="tr-badge">HISTORY &amp; STATS</span></div>
    <h2>what happened after the stream moved on</h2>
    <p>Carrier numbers live broadcasts as sets and stores their history.</p>
    <pre><code>!last
!set 184
!djstats deepend
!stationstats
!records
!achievements
!randomdj</code></pre>
    <p>
        Set records include things Carrier actually observed, such as duration, track count, peak listeners, couch activity,
        props, questions, requests, reactions, handoffs, and other station activity. The richer public version, including
        the track log and resolved show/format, is available in the website transmission archive.
    </p>
</section>

<section class="tr-section" id="invite">
    <div class="tr-title"><span class="tr-badge">INVITE CARRIER</span></div>
    <h2>use TildeRadio commands in another IRC channel</h2>
    <p>
        On networks where Carrier is connected and invites are enabled, invite it the normal IRC way:
    </p>
    <pre><code>/invite carrier #yourchannel</code></pre>
    <p>
        Carrier joins and remembers invited channels across restarts. Commands work immediately, but automatic station
        announcements are <strong>off by default</strong> so inviting the bot does not suddenly flood a channel.
    </p>

    <div class="tr-carrier-commands">
        <div><code>!carrier status</code><span>show Carrier settings for the current channel</span></div>
        <div><code>!carrier announce on</code><span>enable automatic TildeRadio announcements here</span></div>
        <div><code>!carrier announce off</code><span>leave commands available but stop announcements</span></div>
        <div><code>!carrier part</code><span>remove Carrier and forget the invited channel</span></div>
    </div>

    <p>
        The person who invited Carrier, a channel operator, or a Carrier admin can manage those channel settings.
        Kicking Carrier from an invited channel also removes that channel from its saved invite list.
    </p>

    <div class="tr-carrier-note">
        <strong>commands vs. announcements:</strong> asking <code>!np</code> only answers the channel that asked.
        Enabling announcements subscribes the channel to the station events Carrier normally announces, such as live DJ
        changes, handoffs, listener milestones, goals, and other live-set activity.
    </div>
</section>

<section class="tr-section" id="flow">
    <div class="tr-title"><span class="tr-badge">A DJ'S SHOW, START TO FINISH</span></div>
    <h2>what you actually do during a normal broadcast</h2>
    <ol class="tr-carrier-steps">
        <li>
            <strong>Before the show: check that Carrier knows you are next.</strong>
            <span>Use <code>!next</code> or <code>!schedule</code>. If you want an episode title or prompt, stage it now with <code>!show episode ...</code> and the other <code>!show</code> fields.</span>
        </li>
        <li>
            <strong>Connect to the stream normally.</strong>
            <span>Carrier notices AzuraCast switch from AutoDJ to your live streamer. It starts a numbered set, loads your DJ profile, resolves the weekday show format, applies anything you staged, and begins the track log.</span>
        </li>
        <li>
            <strong>Run <code>!show</code> once if you want to verify the context.</strong>
            <span>You will see the recurring show/format plus the current episode details. Change any per-set field if tonight took a different direction.</span>
        </li>
        <li>
            <strong>Open only the interaction you want.</strong>
            <span>Requests start closed. Open them with <code>!requests on</code> if appropriate; questions, props, reactions, polls, goals, and temporary commands are there when useful, not requirements for running a show.</span>
        </li>
        <li>
            <strong>During the music, Carrier mostly gets out of the way.</strong>
            <span>It watches track metadata and listener changes automatically. Use <code>!questions</code> or <code>!requests list</code> when you have a moment rather than babysitting the bot.</span>
        </li>
        <li>
            <strong>If another DJ is taking over, arm the relay.</strong>
            <span><code>!handoff nextdj</code> tells Carrier who to expect. A short AutoDJ gap will not make the handoff look like unrelated station activity.</span>
        </li>
        <li>
            <strong>When your live stream ends, Carrier closes the set.</strong>
            <span>The final stats and track list are exported automatically. The transmission appears under <code>/episodes/</code> and among the recent transmissions on your DJ profile.</span>
        </li>
    </ol>

    <div class="tr-carrier-flow">
        <strong>minimal DJ workflow</strong>
        <pre><code>&lt;deepend&gt; !show episode songs I found through IRC
&lt;carrier&gt; episode saved for deepend's next set

... connect the live stream ...

&lt;carrier&gt; SIGNAL ACQUIRED | deepend | ~/pull
&lt;deepend&gt; !show
&lt;deepend&gt; !requests on

... do the radio show; Carrier logs tracks automatically ...

&lt;deepend&gt; !handoff ffog
... or simply end the live stream when nobody follows ...</code></pre>
    </div>
</section>

<section class="tr-section">
    <div class="tr-title"><span class="tr-badge">HELP</span></div>
    <h2>you do not need to memorize this page</h2>
    <pre><code>!help
!help dj
!help room
!help games
!help history</code></pre>
    <p>
        Carrier intentionally ignores unknown commands and keeps repetitive activity quiet. The point is to add some
        useful station context to IRC without becoming the loudest thing in the channel.
    </p>
    <p>
        <a href="<?= htmlspecialchars(asset('community/'), ENT_QUOTES, 'UTF-8') ?>">&larr; community</a>
        &nbsp;&middot;&nbsp;
        <a href="<?= htmlspecialchars(asset('djinfo/'), ENT_QUOTES, 'UTF-8') ?>">DJ handbook</a>
        &nbsp;&middot;&nbsp;
        <a href="https://tilde.chat/kiwi/#tilderadio" rel="noopener">#tilderadio</a>
    </p>
</section>

<?php include dirname(__DIR__, 2) . '/footer.php'; ?>
