(function () {
    'use strict';

    var audio = document.getElementById('tr-audio');
    var globalPlayer = document.getElementById('tr-global-player');
    var globalPlay = document.getElementById('tr-global-play');
    var globalMute = document.getElementById('tr-global-mute');
    var globalVolume = document.getElementById('tr-global-vol');
    var globalSource = document.getElementById('tr-global-src');
    var navigationController = null;
    var hasPlayed = !audio.paused;
    var playRequested = !audio.paused;
    var reconnectTimer = null;
    var reconnectAttempts = 0;
    var lastProgress = Date.now();
    var preservePlayback = false;

    if (!audio) return;

    function currentSource() {
        return audio.currentSrc || audio.src || '';
    }

    function clearReconnect() {
        if (reconnectTimer) clearTimeout(reconnectTimer);
        reconnectTimer = null;
    }

    function reconnect(delay) {
        if (!playRequested || reconnectTimer) return;

        delay = Math.max(delay, Math.min(30000, 1000 * Math.pow(2, reconnectAttempts)));
        reconnectTimer = setTimeout(function () {
            reconnectTimer = null;
            if (!playRequested) return;

            reconnectAttempts++;
            preservePlayback = true;
            audio.load();
            audio.play()
                .catch(function () { reconnect(1000); })
                .then(function () { preservePlayback = false; });
        }, delay);
    }

    function noteProgress() {
        lastProgress = Date.now();
        reconnectAttempts = 0;
        clearReconnect();
    }

    function syncGlobalPlayer() {
        if (!globalPlayer) return;

        if (!audio.paused) hasPlayed = true;
        globalPlayer.classList.toggle('is-playing', !audio.paused);

        if (globalPlay) {
            globalPlay.textContent = audio.paused ? '▶ play' : '⏸ pause';
        }
        if (globalMute) {
            globalMute.textContent = audio.muted ? '🔇 unmute' : '🔈 mute';
        }
        if (globalVolume) {
            globalVolume.value = String(audio.volume);
        }
        if (globalSource) {
            var source = currentSource();
            var match = Array.from(globalSource.options).some(function (option) {
                return option.value === source;
            });
            if (match) globalSource.value = source;
        }
    }

    function updateGlobalPlayerVisibility() {
        if (!globalPlayer) return;
        globalPlayer.hidden = !hasPlayed || Boolean(document.querySelector('main .tr-player'));
    }

    function changeSource(source) {
        if (!source || source === currentSource()) return;

        var wasPlaying = playRequested;
        clearReconnect();
        preservePlayback = wasPlaying;
        audio.pause();
        audio.src = source;
        audio.load();

        if (wasPlaying) {
            audio.play()
                .catch(syncGlobalPlayer)
                .then(function () { preservePlayback = false; });
        }
    }

    if (globalPlay) {
        globalPlay.addEventListener('click', function () {
            if (audio.paused) {
                playRequested = true;
                audio.play().catch(function () { reconnect(1000); });
            } else {
                playRequested = false;
                clearReconnect();
                audio.pause();
            }
        });
    }

    if (globalMute) {
        globalMute.addEventListener('click', function () {
            audio.muted = !audio.muted;
        });
    }

    if (globalVolume) {
        globalVolume.addEventListener('input', function () {
            audio.volume = Number(globalVolume.value);
            if (audio.volume > 0 && audio.muted) audio.muted = false;
        });
    }

    if (globalSource) {
        globalSource.addEventListener('change', function () {
            changeSource(globalSource.value);
        });
    }

    ['play', 'pause', 'volumechange', 'loadstart', 'loadedmetadata'].forEach(function (eventName) {
        audio.addEventListener(eventName, function () {
            syncGlobalPlayer();
            updateGlobalPlayerVisibility();
        });
    });

    audio.addEventListener('play', function () {
        playRequested = true;
        noteProgress();
    });

    audio.addEventListener('pause', function () {
        if (preservePlayback) return;
        playRequested = false;
        clearReconnect();
    });

    audio.addEventListener('timeupdate', noteProgress);
    audio.addEventListener('playing', noteProgress);
    audio.addEventListener('error', function () { reconnect(1000); });
    audio.addEventListener('ended', function () { reconnect(1000); });
    audio.addEventListener('stalled', function () { reconnect(5000); });
    audio.addEventListener('waiting', function () { reconnect(12000); });

    setInterval(function () {
        if (playRequested && Date.now() - lastProgress > 15000) reconnect(0);
    }, 5000);

    function shouldNavigate(event, link, url) {
        if (audio.paused) return false;
        if (event.defaultPrevented || event.button !== 0) return false;
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return false;
        if (link.hasAttribute('download') || link.hasAttribute('data-tr-full-load')) return false;
        if (link.target && link.target !== '_self') return false;
        if (url.protocol !== 'http:' && url.protocol !== 'https:') return false;
        if (url.origin !== window.location.origin) return false;

        if (url.pathname === window.location.pathname &&
            url.search === window.location.search && url.hash) {
            return false;
        }

        if (/(?:^|\/)listen(?:\/|$)/.test(url.pathname)) return false;
        if (/\.(?:ogg|mp3|m3u|m3u8|pls|ics)(?:$|\?)/i.test(url.pathname + url.search)) return false;

        return true;
    }

    function syncPageStyles(nextDocument, pageUrl) {
        var wanted = new Map();

        nextDocument.querySelectorAll('link[data-tr-page-style]').forEach(function (link) {
            var href = link.getAttribute('href');
            if (!href) return;
            wanted.set(new URL(href, pageUrl).href, true);
        });

        var current = Array.from(document.querySelectorAll('link[data-tr-page-style]'));
        var currentHrefs = new Set(current.map(function (link) { return link.href; }));

        wanted.forEach(function (_, href) {
            if (currentHrefs.has(href)) return;
            var link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = href;
            link.setAttribute('data-tr-page-style', '');
            document.head.appendChild(link);
        });

        current.forEach(function (link) {
            if (!wanted.has(link.href)) link.remove();
        });
    }

    function syncNavigationState(nextDocument) {
        var currentLinks = Array.from(document.querySelectorAll('.site-nav a'));
        var nextLinks = Array.from(nextDocument.querySelectorAll('.site-nav a'));

        currentLinks.forEach(function (link, index) {
            var next = nextLinks[index];
            if (next && next.hasAttribute('aria-current')) {
                link.setAttribute('aria-current', next.getAttribute('aria-current') || 'page');
            } else {
                link.removeAttribute('aria-current');
            }
        });
    }

    function activateScripts(root, pageUrl) {
        root.querySelectorAll('script').forEach(function (oldScript) {
            var script = document.createElement('script');

            Array.from(oldScript.attributes).forEach(function (attribute) {
                script.setAttribute(attribute.name, attribute.value);
            });

            var src = oldScript.getAttribute('src');
            if (src) script.src = new URL(src, pageUrl).href;
            else script.textContent = oldScript.textContent;

            oldScript.replaceWith(script);
        });
    }

    function scrollAfterNavigation(url) {
        if (!url.hash) {
            window.scrollTo(0, 0);
            return;
        }

        var id;
        try {
            id = decodeURIComponent(url.hash.slice(1));
        } catch (error) {
            id = url.hash.slice(1);
        }
        var target = id ? document.getElementById(id) : null;
        if (target) target.scrollIntoView();
        else window.scrollTo(0, 0);
    }

    function navigate(url, pushState, formBody) {
        if (navigationController) navigationController.abort();
        navigationController = new AbortController();

        fetch(url.href, {
            method: formBody ? 'POST' : 'GET',
            body: formBody || undefined,
            cache: 'no-store',
            credentials: 'same-origin',
            headers: {
                'Accept': 'text/html',
                'X-TildeRadio-Navigation': '1'
            },
            signal: navigationController.signal
        })
            .then(function (response) {
                var contentType = response.headers.get('content-type') || '';
                if (contentType.indexOf('text/html') === -1) {
                    window.location.href = url.href;
                    return null;
                }

                return response.text().then(function (html) {
                    return {
                        html: html,
                        url: new URL(response.url || url.href)
                    };
                });
            })
            .then(function (result) {
                if (!result) return;

                var nextDocument = new DOMParser().parseFromString(result.html, 'text/html');
                var nextMain = nextDocument.querySelector('main');
                var currentMain = document.querySelector('main');

                if (!nextMain || !currentMain) {
                    window.location.href = result.url.href;
                    return;
                }

                window.dispatchEvent(new Event('tilderadio:before-navigate'));
                syncPageStyles(nextDocument, result.url);
                syncNavigationState(nextDocument);

                var importedMain = document.importNode(nextMain, true);
                currentMain.replaceWith(importedMain);
                document.title = nextDocument.title || document.title;

                if (pushState) {
                    window.history.pushState({tilderadio: true}, '', result.url.href);
                }

                activateScripts(importedMain, result.url);
                updateGlobalPlayerVisibility();
                syncGlobalPlayer();
                scrollAfterNavigation(result.url);
                window.dispatchEvent(new Event('tilderadio:after-navigate'));
                var authAlert = importedMain.querySelector('[data-dj-auth-alert]');
                if (authAlert) authAlert.focus();
            })
            .catch(function (error) {
                if (error && error.name === 'AbortError') return;
                window.location.href = url.href;
            });
    }

    document.addEventListener('click', function (event) {
        var target = event.target;
        if (!(target instanceof Element)) return;

        var link = target.closest('a[href]');
        if (!link) return;

        var url;
        try {
            url = new URL(link.href, window.location.href);
        } catch (error) {
            return;
        }

        if (!shouldNavigate(event, link, url)) return;

        event.preventDefault();
        navigate(url, true);
    });

    window.addEventListener('popstate', function () {
        navigate(new URL(window.location.href), false);
    });

    // Keep the existing audio element alive through DJ login/logout. These forms
    // still use normal server-side POST/redirect/GET when JavaScript is unavailable.
    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-tr-dj-auth') || audio.paused) return;
        var url = new URL(form.action, window.location.href);
        if (url.origin !== window.location.origin || form.method.toLowerCase() !== 'post') return;
        event.preventDefault();
        if (form.getAttribute('aria-busy') === 'true') return;
        form.setAttribute('aria-busy', 'true');
        form.querySelectorAll('button[type="submit"]').forEach(function (button) { button.disabled = true; });
        var body = new URLSearchParams();
        new FormData(form).forEach(function (value, key) {
            if (typeof value === 'string') body.append(key, value);
        });
        navigate(url, true, body);
    });

    window.addEventListener('pageshow', function (event) {
        if (event.persisted && document.querySelector('[data-tr-dj-auth]')) {
            navigate(new URL(window.location.href), false);
        }
    });

    syncGlobalPlayer();
    updateGlobalPlayerVisibility();
})();
