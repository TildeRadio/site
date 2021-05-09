<?php include 'header.php'; ?>

<h1>
  <a href="/"><img style="width:72px;margin-top:-30px;margin-right:5px;" src="./logos/tilderadio.png" alt="">tilderadio.org</a>
</h1>
<h4><?=json_decode(file_get_contents("https://bot.tildegit.org/api/slogan"))?></h4>

<p>
TildeRadio is Internet radio streamed by / for users of the <a href="https://tildeverse.org/">tildeverse</a>.
</p>

<p><a href="https://kiwi.tilde.chat/#tilderadio" target="_blank">Join us in #tilderadio</a>, our dedicated IRC channel on <a href="https://tilde.chat">tilde.chat</a></p>
<p>Follow us on Mastodon where we announce when DJ's go live! <a href="https://tilde.zone/@tilderadio" target="_blank">@tilderadio</a></p>


<hr>
<h4>how to listen</h4>
<p><em><?php include 'schedule/nextdj.php'; ?></em></p>
<p>
  <iframe src="https://radio.tildeverse.org/public/tilderadio/embed" frameborder="0" allowtransparency="true" style="width: 100%; min-height: 160px; border: 0;"></iframe>
</p>

<p>
Or use the following links in your media player of choice:
<ul>
<li>https://radio.tildeverse.org/radio/8000/radio.ogg</li>
<li>https://radio.tildeverse.org/radio/8000/radio.mp3</li>
</ul>
</p>

<hr>

<?php include 'footer.php'; ?>
