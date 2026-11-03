const pageList = document.querySelector("#page-list");
const pageOrderInput = document.querySelector("#page-order");

if (pageList && pageOrderInput) {
    let draggedRow = null;

    const updateOrder = () => {
        pageOrderInput.value = JSON.stringify(
            [...pageList.querySelectorAll(".page-row")].map((row) => row.dataset.page),
        );
        pageList.querySelectorAll(".page-row").forEach((row, index, rows) => {
            row.querySelector(".move-up").disabled = index === 0;
            row.querySelector(".move-down").disabled = index === rows.length - 1;
            row.querySelector(".page-number").textContent = String(index + 1);
        });
    };

    pageList.addEventListener("click", (event) => {
        const button = event.target.closest("button");
        if (!button) return;

        const row = button.closest(".page-row");
        if (!row) return;

        if (button.classList.contains("move-up") && row.previousElementSibling) {
            pageList.insertBefore(row, row.previousElementSibling);
        } else if (button.classList.contains("move-down") && row.nextElementSibling) {
            pageList.insertBefore(row.nextElementSibling, row);
        } else if (button.classList.contains("remove-page")) {
            row.remove();
        }
        updateOrder();
    });

    pageList.addEventListener("dragstart", (event) => {
        const row = event.target.closest(".page-row");
        if (!row) return;
        draggedRow = row;
        row.classList.add("is-dragging");
        event.dataTransfer.effectAllowed = "move";
    });

    pageList.addEventListener("dragover", (event) => {
        if (!draggedRow) return;
        event.preventDefault();
        const target = event.target.closest(".page-row");
        if (!target || target === draggedRow) return;
        const bounds = target.getBoundingClientRect();
        pageList.insertBefore(draggedRow, event.clientY < bounds.top + bounds.height / 2 ? target : target.nextSibling);
    });

    pageList.addEventListener("dragend", () => {
        draggedRow?.classList.remove("is-dragging");
        draggedRow = null;
        updateOrder();
    });

    updateOrder();
}

const fileInput = document.querySelector("#pdf-files");
if (fileInput) {
    fileInput.addEventListener("change", () => {
        const label = fileInput.closest(".file-picker");
        const summary = label.querySelector("small");
        summary.textContent = fileInput.files.length
            ? `${fileInput.files.length} file${fileInput.files.length === 1 ? "" : "s"} selected`
            : "PDF documents only";
    });
}
// Repair legacy mojibake in pages saved with a mis-decoded UTF-8 character set.
document.addEventListener('DOMContentLoaded', () => {
    const replacements = new Map([
        [String.fromCharCode(0xC2, 0xB7), '·'],
        [String.fromCodePoint(0xE2, 0x2020, 0x2018), '↑'],
        [String.fromCodePoint(0xE2, 0x2020, 0x201C), '↓'],
        [String.fromCodePoint(0xC3, 0x2014), '×'],
        [String.fromCodePoint(0xE2, 0x25A0, 0xA4), '◇'],
        [String.fromCodePoint(0xE2, 0xA0, 0xBF), '⠿'],
    ]);

    const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
    const textNodes = [];
    while (walker.nextNode()) {
        textNodes.push(walker.currentNode);
    }
    for (const node of textNodes) {
        for (const [bad, good] of replacements) {
            node.nodeValue = node.nodeValue.replaceAll(bad, good);
        }
    }
});
