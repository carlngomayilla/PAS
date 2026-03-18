(function () {
  const sidebar = document.querySelector(".csb");
  if (!sidebar) return;

  const nav = sidebar.querySelector(".csb-nav");
  const indicator = sidebar.querySelector(".csb-indicator");
  const items = sidebar.querySelectorAll(".csb-item");

  if (!nav || !indicator || items.length === 0) return;

  function moveToIndex(index) {
    const item = nav.querySelector(`.csb-item[data-index="${index}"]`);
    if (!item) return;

    const y = item.offsetTop;
    indicator.style.transform = `translateY(${y}px)`;

    items.forEach((el) => el.classList.remove("is-active"));
    item.classList.add("is-active");
  }

  const initial = Number.parseInt(sidebar.dataset.activeIndex || "0", 10);
  moveToIndex(Number.isFinite(initial) ? initial : 0);

  window.addEventListener("resize", () => {
    const active = nav.querySelector(".csb-item.is-active");
    if (!active) return;

    const idx = Number.parseInt(active.dataset.index, 10);
    if (!Number.isNaN(idx)) moveToIndex(idx);
  });
})();
