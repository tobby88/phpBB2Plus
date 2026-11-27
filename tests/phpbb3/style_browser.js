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
    const forumIcons = ["folder_new", "folder", "folder_lock"];
    const topicIcons = ["folder_new", "folder", "folder_announce", "folder_new_hot", "folder_hot",
                        "folder_sticky", "folder_lock_new", "folder_lock", "folder_moved"];
    const loaded = new Set();
    async function checkLegend(doc, width, names) {
        const legend = doc.querySelector(".plus-folder-legend");
        const items = Array.from(legend?.children || []);
        if (!legend?.getAttribute("aria-label") || items.length !== names.length) {
            throw Error("The folder key is missing from " + doc.location.pathname);
        }
        await Promise.all(items.map(async (item, i) => {
            const icon = item.querySelector('[aria-hidden="true"]');
            const label = item.textContent.trim();
            const box = icon.getBoundingClientRect(), row = item.getBoundingClientRect();
            const image = doc.defaultView.getComputedStyle(icon).backgroundImage.slice(5, -2);
            if (!label || label.includes("_") || !image.endsWith("/" + names[i] + ".gif") ||
                box.width !== 25 || box.height !== 25 || row.right > width || item.scrollWidth > item.clientWidth + 1) {
                throw Error("Folder symbol or caption unavailable at " + width + "px: " + names[i]);
            }
            if (!loaded.has(image)) {
                loaded.add(image);
                const graphic = new Image(); graphic.src = image; await graphic.decode();
                if (graphic.naturalWidth !== 25 || graphic.naturalHeight !== 25) throw Error("Unexpected folder artwork");
            }
        }));
    }
    function latestPost(doc, width) {
        let publicPost;
        for (const time of doc.querySelectorAll(".forums li.row dd.lastpost time")) {
            const details = time.closest("dd"), row = details.closest("dl");
            const title = row.querySelector(".forumtitle"), link = details.querySelector('a[href*="viewtopic.php"]');
            const linkBox = link?.getBoundingClientRect(), titleBox = title.getBoundingClientRect();
            const rowBox = row.closest("li.row").getBoundingClientRect(), timeBox = time.getBoundingClientRect();
            if (!linkBox?.width || !timeBox.width || linkBox.right > width ||
                (width < 701 && (linkBox.top < titleBox.bottom || titleBox.top < rowBox.top || timeBox.bottom > rowBox.bottom + 1))) {
                throw Error("The latest-post link or time disappeared from the forum row at " + width + "px");
            }
            // The ballot forum has a visible last post for both guests and signed-in readers.
            if (new URL(title.href).searchParams.get("f") === "14") publicPost = link.href;
        }
        if (!publicPost) throw Error("The public ballot forum is missing from the fixture");
        return publicPost;
    }
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
    await checkStylePage(frame, "index.php", [1180, 390], async (forums, width) => {
        const folder = forums.querySelector(".forums dl:is(.forum_read_subforum, .forum_read, .forum_unread, .forum_unread_subforum)");
        const background = folder && forums.defaultView.getComputedStyle(folder).backgroundImage;
        const file = folder?.matches(".forum_unread, .forum_unread_subforum") ? "folder_new.gif" : "folder.gif";
        if (!background || !background.includes("/fisubsilversh/theme/images/" + file) ||
            forums.defaultView.getComputedStyle(folder).backgroundSize.split(" ")[0] !== "25px") {
            throw Error("Unexpected FI Subsilver forum row at " + width + "px: " + (folder?.className || "missing folder") +
                ", " + (folder ? forums.defaultView.getComputedStyle(folder).backgroundSize : "no size") + ", expected " + file +
                ", found " + (background || "").split("/").pop());
        }
        latestPost(forums, width);
        await checkLegend(forums, width, forumIcons);
    });
    let category;
    await checkStylePage(frame, "viewforum.php?f=1", [390, 1180], async (forum, width) => {
        await checkLegend(forum, width, topicIcons);
        const parents = Array.from(forum.querySelectorAll(".breadcrumbs .crumb a")).map(a => new URL(a.href));
        category = parents.find(u => u.pathname.endsWith("/viewforum.php") && u.searchParams.get("f") !== "1");
        if (!category || !forum.querySelector(".topics .topictitle")) throw Error("The topic list lost its category or title");
    });
    let last;
    await checkStylePage(frame, category.href, [1180, 390], async (forums, width) => {
        if (!forums.querySelector(".forums .forumtitle")) throw Error("The category has no link to its forum");
        last = latestPost(forums, width);
        await checkLegend(forums, width, forumIcons);
    });
    await checkStylePage(frame, last, [390], post => {
        const anchor = new URL(last).hash;
        if (!anchor || !post.getElementById(anchor.slice(1))) {
            const posts = Array.from(post.querySelectorAll(".post[id]")).map(p => p.id).slice(0, 6);
            throw Error("Mobile last-post link " + anchor + " opened " + post.location.pathname + " with post IDs " + posts.join(", "));
        }
    });
}
