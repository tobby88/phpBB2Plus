// Run inside the existing synthetic board's same-origin renderer.
async function checkGalleryStyle(frame) {
    function fillsBoard(doc, section) {
        const board = doc.querySelector("#page-body").getBoundingClientRect();
        const box = section.getBoundingClientRect();
        return box.width >= .95 * board.width && box.left >= board.left - 1 && box.right <= board.right + 1;
    }
    function crumbs(doc) {
        return Array.from(doc.querySelectorAll("#nav-breadcrumbs a")).filter(a => a.getBoundingClientRect().width > 0)
            .map(a => new URL(a.href).pathname);
    }
    async function image(doc, selector, width, height) {
        const img = doc.querySelector(selector);
        if (!img) { throw Error("Missing album image: " + selector); }
        img.loading = "eager";
        await img.decode();
        const box = img.getBoundingClientRect();
        if (img.naturalWidth !== width || img.naturalHeight !== height || box.width !== width || box.height !== height) {
            throw Error("Album preview was distorted or enlarged: " + selector);
        }
        return box;
    }
    await checkStylePage(frame, "app.php/album", [1180, 390], (home, width) => {
        const directory = home.querySelector(".plus-album-categories");
        const previews = Array.from(home.querySelectorAll(".plus-album-grid"));
        if (!directory || !fillsBoard(home, directory.parentElement) || previews.length !== 3 ||
            home.defaultView.getComputedStyle(previews[0]).gridTemplateColumns.trim().split(/\s+/).length !== (width > 700 ? 3 : 2) ||
            !previews[1].querySelector("li:first-child a[href*='/album/picture/100']") ||
            previews.some(grid => grid.querySelector("a[href*='/album/picture/101'], a[href*='/album/picture/102'], a[href*='/album/picture/103'], a[href*='/album/picture/104']"))) {
            throw Error("The album directory or filtered home picture grids are missing at " + width + "px");
        }
    });
    await checkStylePage(frame, "app.php/album/category/1", [390, 1180], async (cat, width) => {
        const categoryBlocks = Array.from(cat.querySelectorAll("[data-album-grid]"));
        const recent = categoryBlocks[0]?.querySelector(".plus-album-grid");
        const grid = cat.querySelector('[data-album-grid="category"] .plus-album-grid');
        const size = width > 700 ? 4 : 2;
        if (!recent || categoryBlocks[0].dataset.albumGrid !== "recent" || !grid ||
            cat.defaultView.getComputedStyle(recent).gridTemplateColumns.trim().split(/\s+/).length !== (width > 700 ? 3 : 2) ||
            !recent.querySelector("a[href*='/album/picture/100']") || recent.querySelector("a[href*='/album/picture/104']") ||
            cat.defaultView.getComputedStyle(grid).gridTemplateColumns.trim().split(/\s+/).length !== size ||
            !fillsBoard(cat, grid.parentElement) || !crumbs(cat).some(path => path.endsWith("/album"))) {
            throw Error("The category did not use its gallery columns, board width or album navigation at " + width + "px");
        }
        const sort = cat.querySelector("#album-sort");
        if (!sort || sort.method !== "get" || sort.elements.sort_method.options.length !== 7 ||
            sort.elements.sort_order.getBoundingClientRect().right > cat.defaultView.innerWidth) {
            throw Error("The gallery sort controls do not fit at " + width + "px");
        }
        const cell = grid.querySelector("a[href*='/album/picture/100']").parentElement;
        const thumb = await image(cell, "img", 16, 10);
        const box = cell.getBoundingClientRect();
        const text = cat.defaultView.getComputedStyle(cell.querySelector("dt"));
        const fill = cat.defaultView.getComputedStyle(cell).backgroundColor;
        const colour = cell.querySelector(".username-coloured");
        const time = cell.querySelector("time[datetime]");
        if (Math.abs(thumb.left + thumb.width / 2 - box.left - box.width / 2) > 1 ||
            !time || !["Date", "Datum"].includes(time.parentElement.previousElementSibling.textContent) ||
            (luminance(fill) + .05) / (luminance(text.color) + .05) < 7 ||
            (colour && (luminance(fill) + .05) / (luminance(cat.defaultView.getComputedStyle(colour).webkitTextFillColor) + .05) < 7)) {
            throw Error("The thumbnail or its caption is unreadable at " + width + "px");
        }
    });
    await checkStylePage(frame, "app.php/album/picture/100", [1180, 390], async (pic, width) => {
        const section = pic.querySelector(".plus-album-detail");
        const parents = crumbs(pic);
        if (!section || !fillsBoard(pic, section) || !parents.some(path => path.endsWith("/album")) ||
            !parents.some(path => path.endsWith("/album/category/1")) || !section.querySelector("figcaption a[href*='/100/full']") ||
            !section.querySelector("time[datetime]") || !section.querySelector("dd.plus-album-copy").textContent.includes("Beschreibung & Grüße") ||
            !["Description", "Beschreibung"].includes(section.querySelector("dd.plus-album-copy").previousElementSibling.textContent)) {
            throw Error("The picture lost its category path, metadata or full-size link at " + width + "px");
        }
        const prev = section.querySelector('a[rel="prev"]').getBoundingClientRect();
        const next = section.querySelector('a[rel="next"]').getBoundingClientRect();
        const title = section.querySelector("h2").getBoundingClientRect();
        if (prev.width < 20 || next.width < 20 || prev.right > title.left || title.right > next.left) {
            throw Error("The gallery arrows overlap the title at " + width + "px");
        }
        const photo = await image(pic, ".plus-album-photo img", 30, 18);
        const figure = section.querySelector("figure").getBoundingClientRect();
        if (Math.abs(photo.left + photo.width / 2 - figure.left - figure.width / 2) > 1) {
            throw Error("The picture is not centred at " + width + "px");
        }
        // Exercise the same template with a larger rendition of the loaded synthetic picture.
        const current = section.querySelector("figure img");
        const original = current.src;
        const larger = pic.createElement("canvas");
        larger.width = 800;
        larger.height = 480;
        larger.getContext("2d").drawImage(current, 0, 0, larger.width, larger.height);
        current.src = larger.toDataURL("image/png");
        await current.decode();
        const resized = current.getBoundingClientRect();
        if (resized.width <= 200 || resized.width > figure.width || (width > 700 && resized.width !== 800) ||
            Math.abs(resized.height - resized.width * .6) > 1 || pic.documentElement.scrollWidth > pic.defaultView.innerWidth + 1) {
            throw Error("A large album picture no longer fits its page at " + width + "px");
        }
        const comment = pic.querySelector("#comment-95");
        const author = comment.querySelector("header").getBoundingClientRect();
        const reply = comment.querySelector(".content").getBoundingClientRect();
        if (width > 700 ? author.right > reply.left : author.bottom > reply.top) {
            throw Error("The album comment author overlaps its text at " + width + "px");
        }
        current.src = original;
        await current.decode();
    });
    await checkStylePage(frame, "app.php/album/search?mode=name&search=Fotoprobe", [390, 1180], (found, width) => {
        const form = found.querySelector("#album-search");
        const results = found.querySelector("#album-search-results");
        if (!form || form.elements.mode.value !== "name" || form.elements.search.value !== "Fotoprobe" || !results ||
            !fillsBoard(found, results) || !results.querySelector("td a[href*='/album/picture/100']") ||
            results.querySelector("td a[href*='/album/picture/104']")) {
            throw Error("The search form or its visible photo results changed at " + width + "px");
        }
    });
}
