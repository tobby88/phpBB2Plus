<!DOCTYPE html>
<html>
<head><meta charset="UTF-8" /></head>
<body style="margin:0;background-color:{T_BODY_BGCOLOR}">
<div id="links" style="white-space:nowrap;line-height:0"></div>
<script type="text/javascript">
(function () {
    'use strict';
    var items = {LINKS_LOGO}, interval = {DISPLAY_INTERVAL}, visible = {DISPLAY_LOGO_NUM};
    var width = {SITE_LOGO_WIDTH}, height = {SITE_LOGO_HEIGHT};
    var target = document.getElementById('links'), start = 0, timer = null;
    var hovered = false, focused = false, stopped = false;
    if (!target || !Array.isArray(items)) { return; }
    function cancel() { if (timer !== null) { window.clearTimeout(timer); timer = null; } }
    function schedule() {
        cancel();
        if (!stopped && !hovered && !focused && document.visibilityState !== 'hidden' && items.length > visible) {
            timer = window.setTimeout(function () { timer = null; start = (start + 1) % items.length; draw(); }, interval);
        }
    }
    function draw() {
        while (target.firstChild) { target.removeChild(target.firstChild); }
        for (var i = 0; i < Math.min(visible, items.length); i++) {
            var item = items[(start + i) % items.length];
            var anchor = document.createElement('a'), image = document.createElement('img');
            anchor.setAttribute('href', item.url);
            anchor.setAttribute('target', '_blank'); anchor.setAttribute('rel', 'noopener noreferrer');
            image.setAttribute('alt', item.title); image.setAttribute('width', width); image.setAttribute('height', height);
            image.setAttribute('referrerpolicy', 'no-referrer'); image.setAttribute('src', item.image);
            image.style.border = '0'; image.style.margin = '0 1px'; image.style.verticalAlign = 'top';
            anchor.appendChild(image); target.appendChild(anchor);
        }
        schedule();
    }
    target.addEventListener('mouseenter', function () { hovered = true; cancel(); });
    target.addEventListener('mouseleave', function () { hovered = false; schedule(); });
    target.addEventListener('focusin', function () { focused = true; cancel(); });
    target.addEventListener('focusout', function (event) { focused = target.contains(event.relatedTarget); schedule(); });
    document.addEventListener('visibilitychange', schedule);
    window.addEventListener('pagehide', function () { stopped = true; cancel(); });
    window.addEventListener('pageshow', function () { stopped = false; schedule(); });
    draw();
}());
</script>
</body>
</html>
