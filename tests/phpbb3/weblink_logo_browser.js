(async function () {
    const frame = document.getElementById("board");
    const output = document.querySelector("output");
    const wait = ms => new Promise(resolve => setTimeout(resolve, ms));
    async function load(url, width) {
        frame.style.width = width + "px";
        frame.style.height = "600px";
        const loaded = new Promise(resolve => { frame.onload = resolve; });
        frame.src = url;
        await loaded;
        return frame.contentDocument;
    }
    try {
        const plain = await load("index.php", 390);
        let panel = plain.querySelector(".wl-index");
        if (panel) {
            panel.scrollIntoView();
            const boardLogo = panel.querySelector(".wl-board img");
            boardLogo.loading = "eager";
            await boardLogo.decode();
        }
        if (!panel || panel.scrollWidth > panel.clientWidth + 1 || !plain.querySelector(".wl-board img").naturalWidth) {
            throw Error("Index logos overflowed or were not displayed at 390px");
        }
        const doc = await load("links.js.php", 1180);
        panel = doc.querySelector(".wl-rotation");
        const ids = () => Array.from(panel.querySelectorAll(".wl-items a"), a => new URL(a.href).pathname.split("/").pop()).join(",");
        const first = ids();
        const control = panel.querySelector("button");
        if (first !== "4112,4111" || control.hidden) throw Error("Missing initial visible logos or rotation control");
        const changed = async original => {
            for (let i = 0; i < 22; i++) {
                await wait(50);
                if (ids() !== original) return;
            }
            throw Error("Logos did not advance");
        };
        await changed(first);
        if (ids() !== "4111,5") throw Error("Rotation did not advance by one logo");
        const old = ids();
        const link = panel.querySelector(".wl-items a");
        link.focus();
        await wait(950);
        if (ids() !== old || doc.activeElement !== link) throw Error("Moving logos displaced keyboard focus");
        link.blur();
        await changed(old);
        control.click();
        const paused = ids();
        await wait(950);
        if (ids() !== paused || control.title !== "Logowechsel fortsetzen") throw Error("Pause did not persist");
        control.click();
        await changed(paused);
        output.textContent = "OK";
    } catch (error) { output.textContent = String(error); }
}());
