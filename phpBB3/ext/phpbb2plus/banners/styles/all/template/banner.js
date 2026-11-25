(() => {
    'use strict';
    if (!window.RufflePlayer || typeof window.RufflePlayer.newest !== 'function') return;
    for (const container of document.querySelectorAll('.plus-banner-movie')) {
        const label = container.textContent;
        const player = window.RufflePlayer.newest().createPlayer();
        player.setAttribute('aria-label', label);
        player.style.width = (Number(container.dataset.width) || 122) + 'px';
        player.style.height = (Number(container.dataset.height) || 55) + 'px';
        player.style.maxWidth = '100%';
        container.replaceChildren(player);
        player.ruffle(1).load({
            url: new URL(container.dataset.source, document.baseURI).href,
            allowScriptAccess: false, allowNetworking: 'none', openUrlMode: 'deny',
            autoplay: 'on', showSwfDownload: false
        }).catch(() => { container.textContent = label; });
    }
})();
