// Load each real page once. Start some pages on mobile and some on desktop,
// then exercise the other viewport using the same responsive document.
async function checkStylePage(frame, path, widths, inspect) {
    frame.style.cssText = "border:0;height:760px;width:" + widths[0] + "px";
    await new Promise(resolve => {
        frame.addEventListener("load", resolve, {once: true});
        frame.src = path;
    });
    const doc = frame.contentDocument;
    for (const width of widths) {
        frame.style.width = width + "px";
        await new Promise(resolve => requestAnimationFrame(resolve));
        if (doc.documentElement.scrollWidth > doc.defaultView.innerWidth + 1) {
            throw Error(path + " overflows at " + width + "px");
        }
        await inspect(doc, width);
    }
}

function luminance(rgb) {
    const parts = rgb.match(/\d+(?:\.\d+)?/g).slice(0, 3).map(n => {
        const v = Number(n) / 255;
        return v <= .04045 ? v / 12.92 : ((v + .055) / 1.055) ** 2.4;
    });
    return parts[0] * .2126 + parts[1] * .7152 + parts[2] * .0722;
}

async function checkPlusStyle(frame) {
    await checkStylePage(frame, "app.php/portal", [1180, 390], async (portal, width) => {
        const desktop = width > 700;
        const win = portal.defaultView;
        const logo = portal.querySelector("#logo .site_logo").getBoundingClientRect();
        const home = portal.querySelector(".plus-brand-portal");
        if (!home || !new URL(home.href).pathname.endsWith("/portal") || home.getBoundingClientRect().width < 45 ||
            (desktop ? logo.width !== 240 || logo.height !== 110 : logo.width < 88 || logo.width > 155)) {
            throw Error("The Plus header lost its linked logos at " + width + "px");
        }
        const recent = portal.querySelector(".plus-recent");
        const calendar = portal.querySelector("#plus-mini-calendar");
        const grid = calendar && calendar.querySelector("table");
        const today = grid && grid.querySelector('[aria-current="date"]');
        const days = grid ? Array.from(grid.querySelectorAll("thead th")) : [];
        if (!today || !today.offsetWidth || days.length !== 7 || days.some(day => day.scrollWidth > day.clientWidth + 1) ||
            grid.getBoundingClientRect().right > calendar.getBoundingClientRect().right + 1) {
            throw Error("The portal calendar's seven weekday columns do not fit at " + width + "px");
        }
        if (desktop) {
            const poll = portal.querySelector("#plus-portal-poll");
            const form = new Promise((resolve, reject) => {
                const timeout = setTimeout(() => { observer.disconnect(); reject(Error("The portal poll did not load")); }, 7000);
                const observer = new MutationObserver(() => {
                    const native = poll.querySelector("form.topic_poll");
                    if (native) { clearTimeout(timeout); observer.disconnect(); resolve(native); }
                });
                observer.observe(poll, {childList: true, subtree: true});
            });
            poll.open = true;
            if (!new URL((await form).action).pathname.endsWith("/viewtopic.php")) { throw Error("The portal poll did not use the native vote handler"); }
            poll.open = false;
        }
        const row = recent.tBodies[0].rows[0];
        const title = row.querySelector("a.topictitle");
        const ink = luminance(win.getComputedStyle(title).color);
        const fill = luminance(win.getComputedStyle(row).backgroundColor);
        if ((Math.max(ink, fill) + .05) / (Math.min(ink, fill) + .05) < 4.5 ||
            row.cells[0].offsetWidth < recent.offsetWidth / 2 || row.cells[1].offsetWidth < 50 ||
            portal.documentElement.scrollWidth > win.innerWidth + 1) {
            throw Error("The recent topics or last-post column is unreadable at " + width + "px");
        }
    });
    await checkStylePage(frame, "viewtopic.php?t=1", [390, 1180], (topic, width) => {
        const desktop = width > 700;
        const author = topic.querySelector(".post .postprofile").getBoundingClientRect();
        const body = topic.querySelector(".post .postbody").getBoundingClientRect();
        if ((desktop ? author.right > body.left + 1 : author.bottom > body.top + 1) ||
            topic.documentElement.scrollWidth > topic.defaultView.innerWidth + 1) {
            throw Error("The author column overlaps the post at " + width + "px");
        }
    });
    await checkStylePage(frame, "index.php", [1180], async forums => {
        const folder = forums.querySelector(".forum_read_subforum, .forum_read");
        const background = folder && forums.defaultView.getComputedStyle(folder).backgroundImage;
        if (!background || !background.includes("/fisubsilversh/theme/images/folder.gif")) {
            throw Error("The forum read state lost its FI Subsilver folder image");
        }
        const graphic = new Image();
        graphic.src = background.slice(5, -2);
        await graphic.decode();
        if (graphic.naturalWidth !== 25) throw Error("The original folder image could not be decoded");
    });
}
