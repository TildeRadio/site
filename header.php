<!DOCTYPE html>
<html lang="en">

    <head>
        <meta charset="UTF-8">
        <title>tilderadio
            <?=isset($title) ? " | $title" : "" ?>
        </title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="stylesheet" href="/css/hacker.css">
        <link rel="icon" type="image/png" href="/logos/tilderadio.png">
        <?=isset($additional_head) ? PHP_EOL . "        " . $additional_head . PHP_EOL : ""?>
    </head>

    <body>
        <div class="container">
            <nav class="navbar navbar-default navbar-fixed-top">
                <div class="container">
                    <div class="navbar-header">
                        <button type="button" class="navbar-toggle collapsed" data-toggle="collapse" data-target="#navbar" aria-expanded="false" aria-controls="navbar">
                            <span class="sr-only">Toggle navigation</span>
                            <span class="icon-bar"> </span>
                            <span class="icon-bar"> </span>
                            <span class="icon-bar"> </span>
                        </button>
                    </div>
                    <div id="navbar" class="navbar-collapse collapse">
                        <ul class="nav navbar-nav navbar-right">
                            <li><a href="/">home</a></li>
                            <li><a href="/schedule/">schedule</a></li>
                            <li><a href="/listen">listen now</a></li>
                        </ul>

                            <!--/.nav-collapse -->
                    </div>
                </div>
            </nav>
        </div>
        <br>
        <br>
        <div class="container">
            <h1>
                <a href="/"><img style="width:72px;margin-top:-30px;margin-right:5px;" src="/logos/tilderadio.png" alt="">tilderadio.org</a>
            </h1>
            <hr>