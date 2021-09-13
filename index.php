<?php include 'header.php'; ?>

<p><em><?php include 'schedule/nextdj.php'; ?></em></p>
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



<h2>how to listen</h2>

<p>use one of the following links in your media player of choice or visit them in your browser:</p>

<ul>
    <li><a href="https://tilderadio.org/listen">https://tilderadio.org/listen</a></li>
    <li>
        <a href="https://azuracast.tilderadio.org/radio/8000/radio.ogg">
        https://azuracast.tilderadio.org/radio/8000/radio.ogg</a> (default, 192 kbps ogg vorbus)
    </li>
    <li>
        <a href="https://azuracast.tilderadio.org/radio/8000/320k.ogg">
        https://azuracast.tilderadio.org/radio/8000/320k.ogg</a> (320 kbps ogg vorbus)
    </li>
    <li>
        <a href="https://azuracast.tilderadio.org/radio/8000/radio.mp3">
        https://azuracast.tilderadio.org/radio/8000/radio.mp3</a> (192 kbps mp3)
    </li>
    <li>
        <a href="https://azuracast.tilderadio.org/radio/8000/320k.mp3">
        https://azuracast.tilderadio.org/radio/8000/320k.mp3</a> (320 kbps mp3)
    </li>
</ul>

<hr>
<iframe src="https://azuracast.tilderadio.org/public/tilderadio/embed"  style="width: 100%; min-height: 160px; border: 0;"></iframe>

<?php include 'footer.php'; ?>
