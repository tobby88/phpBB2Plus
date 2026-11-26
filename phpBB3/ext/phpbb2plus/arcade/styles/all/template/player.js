(() => {
    'use strict';
    const container = document.getElementById('plus-arc-player');
    if (!container) return;
    const failed = () => { container.textContent = container.dataset.error; };
    if (!window.RufflePlayer || typeof window.RufflePlayer.newest !== 'function') { failed(); return; }
    const player = window.RufflePlayer.newest().createPlayer();
    player.setAttribute('aria-label', container.dataset.title);
    player.style.width = container.dataset.width + 'px';
    player.style.height = container.dataset.height + 'px';
    player.style.maxWidth = '100%';
    container.replaceChildren(player);
    const api = player.ruffle(1);
    if (container.dataset.autoSize) {
        player.addEventListener('loadedmetadata', () => {
            const metadata = api.metadata;
            if (!metadata || !(metadata.width > 0 && metadata.height > 0)) return;
            player.style.width = Math.min(4096, metadata.width) + 'px';
            player.style.height = Math.min(4096, metadata.height) + 'px';
        }, { once: true });
    }
    api.addFSCommandHandler((command, args) => {
        if (/^(quit|close)$/i.test(command) && window.opener) { window.close(); return; }
        window.dispatchEvent(new CustomEvent('phpbbArcadeFSCommand', {detail: {command, args, container}}));
    });
    const source = new URL(container.dataset.source, document.baseURI);
    api.load({
        url: source.href,
        base: new URL('.', source).href,
        allowScriptAccess: false,
        allowNetworking: 'all',
        autoplay: 'on',
        openUrlMode: 'confirm',
        showSwfDownload: false
    }).catch(failed);
})();
