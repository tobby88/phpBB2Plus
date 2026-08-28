(function () {
    'use strict';

    function positiveDimension(value, fallback) {
        var number = parseInt(value, 10);
        return isFinite(number) && number > 0 ? number : fallback;
    }

    function handleFSCommand(container, command, args) {
        var normalized = String(command || '').toLowerCase();
        var event;

        if (normalized === 'quit' || normalized === 'close') {
            window.close();
            return;
        }

        if (typeof window.CustomEvent === 'function') {
            event = new CustomEvent('phpbbArcadeFSCommand', {
                detail: {
                    command: command,
                    args: args,
                    container: container
                }
            });
            window.dispatchEvent(event);
        }
    }

    function showError(container, error) {
        var message = document.createElement('p');
        message.className = 'arcade-ruffle-error';
        message.appendChild(document.createTextNode(
            container.getAttribute('data-error-message') ||
            'The Flash game could not be started with Ruffle.'
        ));
        container.textContent = '';
        container.appendChild(message);

        if (window.console && typeof window.console.error === 'function') {
            window.console.error('Ruffle could not load the arcade game:', error);
        }
    }

    function loadGame(container) {
        var source = container.getAttribute('data-swf');
        var width = positiveDimension(container.getAttribute('data-width'), 550);
        var height = positiveDimension(container.getAttribute('data-height'), 400);
        var ruffle;
        var player;
        var api;

        if (!source || !window.RufflePlayer || typeof window.RufflePlayer.newest !== 'function') {
            showError(container, 'Ruffle is unavailable or no SWF URL was provided.');
            return;
        }

        ruffle = window.RufflePlayer.newest();
        player = ruffle.createPlayer();
        player.setAttribute('aria-label', container.getAttribute('data-title') || 'Flash game');
        player.style.display = 'block';
        player.style.width = width + 'px';
        player.style.height = height + 'px';
        player.style.maxWidth = '100%';

        container.textContent = '';
        container.appendChild(player);

        api = player.ruffle(1);
        api.addFSCommandHandler(function (command, args) {
            handleFSCommand(container, command, args);
        });
        api.load({
            url: source,
            allowNetworking: 'all',
            allowScriptAccess: true,
            autoplay: 'on',
            compatibilityRules: true,
            contextMenu: 'on',
            showSwfDownload: false
        }).catch(function (error) {
            showError(container, error);
        });
    }

    function initialize() {
        var containers = document.querySelectorAll('.arcade-ruffle-player');
        var i;

        for (i = 0; i < containers.length; i += 1) {
            if (containers[i].getAttribute('data-ruffle-ready') === '1') {
                continue;
            }
            containers[i].setAttribute('data-ruffle-ready', '1');
            loadGame(containers[i]);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize);
    } else {
        initialize();
    }
}());
