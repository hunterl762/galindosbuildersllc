document.querySelectorAll("[data-confirm]").forEach((form) =>
  form.addEventListener("submit", (event) => {
    if (!confirm(form.dataset.confirm)) event.preventDefault();
  }),
);
document
  .querySelector("[data-password-toggle]")
  ?.addEventListener("click", function () {
    const input = document.getElementById("admin-password");
    input.type = input.type === "password" ? "text" : "password";
    this.textContent = input.type === "password" ? "Show" : "Hide";
  });
document
  .querySelector("[data-media-filter]")
  ?.addEventListener("input", (event) => {
    document
      .querySelectorAll("[data-media-search]")
      .forEach(
        (card) =>
          (card.hidden = !card.dataset.mediaSearch.includes(
            event.target.value.toLowerCase(),
          )),
      );
  });
document.querySelectorAll("[data-upload]").forEach((uploadForm) =>
  uploadForm.addEventListener("submit", async (event) => {
    event.preventDefault();
    const form = event.currentTarget,
      status = form.querySelector("[data-upload-status]"),
      button = form.querySelector("button");
    button.disabled = true;
    status.textContent = "Uploading…";
    try {
      const response = await fetch(form.action, {
        method: "POST",
        headers: { "x-csrf-token": form.elements.csrf.value },
        body: new FormData(form),
      });
      if (!response.ok) {
        const doc = new DOMParser().parseFromString(
          await response.text(),
          "text/html",
        );
        throw new Error(
          doc.querySelector("[role=alert]")?.textContent || "Upload failed.",
        );
      }
      const data = await response.json();
      location.assign(data.redirect);
    } catch (error) {
      status.textContent = error.message;
      button.disabled = false;
    }
  }),
);
