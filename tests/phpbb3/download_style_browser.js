// Reuse the converted board and the browser already running the Plus style checks.
async function checkDownloadStyle(frame) {
    await checkStylePage(frame, "app.php/downloads", [1180, 390], async (doc, width) => {
        const table = doc.querySelector("#dl-categories");
        const search = doc.querySelector("#dl-tools a[href*='/downloads/search'] img");
        if (!table || !search || !new URL(search.src).pathname.endsWith("/de/icon_pa_search.gif") || !search.alt) {
            throw Error("The public directory lost its category list or localized search button");
        }
        await search.decode();
        const link = search.closest("a").getBoundingClientRect();
        if (search.naturalWidth !== 59 || search.naturalHeight !== 18 || link.height < 30) {
            throw Error("The historical paFileDB artwork or its clickable area was lost");
        }
        const jump = doc.querySelector("#dl-category-menu details");
        jump.querySelector("summary").click();
        if (!jump.open || !jump.querySelector("a[href*='/category/21']").getBoundingClientRect().height ||
            jump.querySelector("a[href*='/category/22']")) {
            throw Error("The directory menu did not open or it listed a private category");
        }
        jump.open = false;
        const folder = table.tBodies[0].rows[0].cells[0];
        if (width > 700 && (folder.getBoundingClientRect().width < .56 * table.clientWidth ||
                !doc.defaultView.getComputedStyle(folder).backgroundImage.includes("folder.gif"))) {
            throw Error("The download directory lost its folder column or classic folder image");
        }
    });
    await checkStylePage(frame, "app.php/downloads/category/21", [390, 1180], (doc, width) => {
        const table = doc.querySelector(".dl-single-category");
        const name = table.tBodies[0].rows[0].cells[0].getBoundingClientRect();
        if (name.width < (width > 700 ? .43 : .9) * table.getBoundingClientRect().width) {
            throw Error("The file title column is too narrow at " + width + "px");
        }
    });
    await checkStylePage(frame, "app.php/downloads/file/50", [1180, 390], (doc, width) => {
        const details = doc.querySelector("#dl-file-details");
        const label = details.querySelector("dt").getBoundingClientRect();
        const value = details.querySelector("dd").getBoundingClientRect();
        const sources = doc.querySelector("#downloads h3").getBoundingClientRect();
        if (Math.abs(label.top - value.top) > 1 || label.right > value.left + 1 ||
            label.width < .16 * details.clientWidth || value.width < .55 * details.clientWidth ||
            sources.width < .9 * details.clientWidth || sources.top < details.getBoundingClientRect().bottom) {
            throw Error("The file detail columns or download sources overlap at " + width + "px");
        }
    });
}
