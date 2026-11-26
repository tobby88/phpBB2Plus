async function checkArcadeStyle(frame) {
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
