async function checkArcadeStyle(frame) {
    await checkStylePage(frame, "app.php/arcade", [1180, 390], async (doc, width) => {
        const win = doc.defaultView;
        const category = doc.getElementById("arc-cat-10");
        const icon = category?.querySelector("img.arc-cat-icon");
        const narrow = width <= 700;
        if (!icon || !category.textContent.includes("Spielearchiv") || category.cells.length !== 4 ||
                win.getComputedStyle(category).display !== (narrow ? "grid" : "table-row") ||
                (win.getComputedStyle(category.querySelector(".arc-cat-label")).display !== "none") !== narrow) {
            throw Error("The Arcade category overview lost its headings or layout at " + width + "px");
        }
        icon.loading = "eager";
        await icon.decode();
        if (icon.naturalWidth !== 16 || icon.naturalHeight !== 12) { throw Error("The category picture did not load"); }
        const form = doc.getElementById("arc-directory-options");
        if (!form?.elements.search_word?.labels?.length || !form.elements.sort_mode?.labels?.length ||
                !form.elements.search_type?.getAttribute("aria-label") || !form.elements.order?.getAttribute("aria-label")) {
            throw Error("The Arcade directory filters are missing accessible labels at " + width + "px");
        }
        const links = doc.querySelectorAll(".arc-directory .row .topictitle, .arc-directory .row .forumtitle");
        if (![...links].some(link => link.href.includes("/arcade/game/60") && link.querySelector("img"))) {
            throw Error("Missing the game picture or title in the Arcade directory");
        }
        for (const link of links) {
            const ink = luminance(win.getComputedStyle(link).color);
            const paper = luminance(win.getComputedStyle(link.closest(".row")).backgroundColor);
            if ((paper + .05) / (ink + .05) < 4.5) { throw Error("Unreadable Arcade directory link at " + width + "px"); }
        }
    });
    await checkStylePage(frame, "app.php/arcade/monthly?month=2008-05", [390, 1180], async (doc, width) => {
        const grid = doc.querySelector("#arc-champions");
        const link = grid?.querySelector("a[href*='/arcade/game/60']");
        const icon = link?.querySelector("img");
        if (!icon || !link.textContent.includes("Öffentliches Denkspiel") || !doc.querySelector("#arc-months option:checked")) {
            throw Error("The monthly Arcade archive lost its winner, artwork or period chooser");
        }
        icon.loading = "eager";
        await icon.decode();
        const win = doc.defaultView;
        const columns = win.getComputedStyle(grid).gridTemplateColumns.trim().split(/\s+/).length;
        const box = icon.getBoundingClientRect();
        const fill = luminance(win.getComputedStyle(link.closest("li")).backgroundColor);
        if (columns !== (width > 700 ? 5 : 2) || icon.naturalWidth !== 12 || icon.naturalHeight !== 8 ||
                box.width !== 50 || box.height !== 50 || win.getComputedStyle(icon).objectFit !== "contain" ||
                (fill + .05) / (luminance(win.getComputedStyle(link).color) + .05) < 4.5) {
            throw Error("The monthly champions or their game pictures are unreadable at " + width + "px");
        }
    });
}
