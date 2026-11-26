async function checkKnowledgeStyle(frame) {
    await checkStylePage(frame, "app.php/knowledge/category/8", [390, 1180], (doc, width) => {
        const table = doc.querySelector("#kb-articles");
        const title = table?.querySelector("a[href*='/knowledge/article/201']");
        const row = title?.closest("tr");
        if (!row || row.cells.length !== 5) { throw Error("Missing Knowledge Base article columns"); }
        const win = doc.defaultView;
        const titleWidth = row.cells[0].getBoundingClientRect().width;
        const ink = luminance(win.getComputedStyle(title).color);
        const fill = luminance(win.getComputedStyle(row).backgroundColor);
        if (titleWidth < (width > 700 ? .30 : .90) * table.getBoundingClientRect().width ||
            (fill + .05) / (ink + .05) < 4.5) {
            throw Error("The Knowledge Base article title is too narrow or unreadable at " + width + "px");
        }
        for (const cell of Array.from(row.cells).slice(1)) {
            const label = cell.querySelector("dfn");
            if (width <= 700 && (!label || !label.textContent.trim() || !label.getBoundingClientRect().height)) {
                throw Error("An article detail lost its mobile label");
            }
        }
    });
}
