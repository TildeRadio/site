<?php include 'header.php'; ?>

<blockquote><?=json_decode(file_get_contents("https://bot.tildegit.org/api/slogan"))?></blockquote>

<p>tilderadio is internet radio streamed by / for users of the <a href="https://tildeverse.org/">tildeverse</a>.</p>

<p>
    <a href="https://tilde.chat/kiwi/#tilderadio" target="_blank">Join us in #tilderadio</a>,
    our dedicated IRC channel on <a href="https://tilde.chat">tilde.chat</a>
</p>

<p>
    follow us on mastodon where we announce when DJ's go live!
    <a href="https://tilde.zone/@tilderadio" target="_blank">@tilderadio</a>
</p>


<hr>
<h4>how to listen</h4>
<p><em><?php include 'schedule/nextdj.php'; ?></em></p>

<iframe src="https://azuracast.tilderadio.org/public/tilderadio/embed"  style="width: 100%; min-height: 160px; border: 0;"></iframe>

<p>Or use the following links in your media player of choice:</p>

<ul>
    <li>https://azuracast.tilderadio.org/radio/8000/radio.ogg</li>
    <li>https://azuracast.tilderadio.org/radio/8000/radio.mp3</li>
</ul>

<hr>

<?php include 'footer.php'; ?>
