import { sendGameImage } from "./imageUpload";

/**
 * The parts of a game's edit page that need the browser: the two ordered
 * lists, from the Next app's `DownloadSourcesForm.tsx` and
 * `ConquestFactionsForm.tsx`, and the picture uploads, from `GameImageForm.tsx`.
 *
 * Each list is held here and written into one hidden JSON field on every
 * change, because order is part of what it means and parallel fields would
 * only be zipped back into the same shape on the server. The rows carry no
 * names, so nothing but that field is posted.
 */

interface Column {
  key: string;
  placeholder: string;
  label: (index: number) => string;
  wrapper: string;
  maxLength?: number;
  pattern?: string;
}

interface ListKind {
  max: number;
  noun: string;
  empty: string;
  rowClass: string;
  blank: () => Record<string, string>;
  fromStored: (stored: Record<string, unknown>) => Record<string, string>;
  toPayload: (rows: Record<string, string>[]) => string;
  /** The controls one row shows, which for a download depend on its kind. */
  columns: (row: Record<string, string>) => Column[];
  select?: { key: string; label: (index: number) => string; wrapper: string; options: [string, string][] };
}

const text = (value: unknown): string => (typeof value === "string" ? value : "");

/** What the value box and the detail box ask for, per kind of download. */
const PROMPTS: Record<string, { value: string; detail: string | null }> = {
  rapid: { value: "metalfactions:stable", detail: null },
  url: { value: "https://example.com/game.sdz", detail: "Save as, e.g. game.sdz" },
  github: { value: "owner/repo", detail: "Release file, e.g. TAP_v4" },
};

const LISTS: Record<string, ListKind> = {
  downloads: {
    max: 12,
    noun: "source",
    empty: "No download sources yet.",
    rowClass: "flex flex-wrap items-center gap-2",
    blank: () => ({ kind: "rapid", value: "", asset: "", filename: "" }),
    fromStored: (stored) => ({
      kind: text(stored.kind) || "rapid",
      value: text(stored.value),
      asset: text(stored.asset),
      filename: text(stored.filename),
    }),
    toPayload: (rows) =>
      JSON.stringify(
        rows
          .filter((row) => row.value.trim() !== "")
          .map((row) => ({
            kind: row.kind,
            value: row.value.trim(),
            asset: row.asset.trim(),
            filename: row.filename.trim(),
          })),
      ),
    select: {
      key: "kind",
      label: (index) => `Source ${index + 1} kind`,
      // The width sits on a wrapper because every control carries w-full.
      wrapper: "w-32 shrink-0",
      options: [
        ["rapid", "Rapid tag"],
        ["url", "Address"],
        ["github", "GitHub repo"],
      ],
    },
    // A github source wants part of a release file's name and a url source the
    // name to save as. Different questions, so the box is relabelled rather
    // than shared. Text typed under one kind is kept if the kind changes back.
    columns: (row) => {
      const prompt = PROMPTS[row.kind] ?? PROMPTS.rapid;
      const columns: Column[] = [
        {
          key: "value",
          placeholder: prompt.value,
          label: (index) => `Source ${index + 1} tag, address or repo`,
          wrapper: "min-w-48 flex-1",
          maxLength: 512,
        },
      ];
      if (prompt.detail) {
        const url = row.kind === "url";
        columns.push({
          key: url ? "filename" : "asset",
          placeholder: prompt.detail,
          label: (index) => (url ? `Source ${index + 1} filename to save as` : `Source ${index + 1} release file`),
          wrapper: "w-44 shrink-0",
          maxLength: 256,
        });
      }
      return columns;
    },
  },
  factions: {
    max: 12,
    noun: "faction",
    empty: "No factions named yet.",
    rowClass: "flex items-center gap-2",
    blank: () => ({ name: "", color: "", side: "" }),
    fromStored: (stored) => ({ name: text(stored.name), color: text(stored.color), side: text(stored.side) }),
    toPayload: (rows) =>
      JSON.stringify(
        rows
          .map((row) => ({ name: row.name.trim(), color: row.color.trim(), side: row.side.trim() }))
          .filter((faction) => faction.name !== "")
          .map((faction) => ({
            name: faction.name,
            ...(faction.color ? { color: faction.color } : {}),
            ...(faction.side ? { side: faction.side } : {}),
          })),
      ),
    columns: () => [
      { key: "name", placeholder: "Faction name", label: (i) => `Faction ${i + 1} name`, wrapper: "w-40", maxLength: 256 },
      { key: "color", placeholder: "#rrggbb", label: (i) => `Faction ${i + 1} colour`, wrapper: "w-28", pattern: "^#[0-9a-fA-F]{6}$" },
      { key: "side", placeholder: "In-game side", label: (i) => `Faction ${i + 1} in-game side`, wrapper: "w-32", maxLength: 64 },
    ],
  },
};

function element<K extends keyof HTMLElementTagNameMap>(tag: K, className = ""): HTMLElementTagNameMap[K] {
  const made = document.createElement(tag);
  if (className) made.className = className;
  return made;
}

function listEditor(root: HTMLElement): void {
  const kind = LISTS[root.dataset.listEditor ?? ""];
  const container = root.querySelector<HTMLElement>("[data-rows]");
  const payload = root.querySelector<HTMLInputElement>("[data-payload]");
  const add = root.querySelector<HTMLButtonElement>("[data-add]");
  if (!kind || !container || !payload || !add) return;
  const control = root.dataset.controlClass ?? "";
  const rowButton = root.dataset.rowButtonClass ?? "";

  let stored: unknown = [];
  try {
    stored = JSON.parse(payload.value || "[]");
  } catch {
    stored = [];
  }
  let rows = (Array.isArray(stored) ? stored : [])
    .filter((entry): entry is Record<string, unknown> => typeof entry === "object" && entry !== null)
    .map(kind.fromStored);

  const save = () => {
    payload.value = kind.toPayload(rows);
    add.disabled = rows.length >= kind.max;
  };

  const render = (focus?: { index: number; action: string }) => {
    container.replaceChildren();
    rows.forEach((row, index) => {
      const line = element("div", kind.rowClass);
      if (kind.select) {
        const { key, label, wrapper, options } = kind.select;
        const box = element("div", wrapper);
        const select = element("select", control);
        select.setAttribute("aria-label", label(index));
        for (const [value, name] of options) {
          const option = element("option");
          option.value = value;
          option.textContent = name;
          select.append(option);
        }
        select.value = row[key];
        select.addEventListener("change", () => {
          row[key] = select.value;
          save();
          render({ index, action: "select" });
        });
        box.append(select);
        line.append(box);
      }
      for (const column of kind.columns(row)) {
        const box = element("div", column.wrapper);
        const input = element("input", control);
        input.value = row[column.key];
        input.placeholder = column.placeholder;
        input.setAttribute("aria-label", column.label(index));
        if (column.maxLength) input.maxLength = column.maxLength;
        if (column.pattern) input.pattern = column.pattern;
        input.addEventListener("input", () => {
          row[column.key] = input.value;
          save();
        });
        box.append(input);
        line.append(box);
      }
      const button = (name: string, label: string, disabled: boolean, act: () => void) => {
        const made = element("button", rowButton);
        made.type = "button";
        made.textContent = name;
        made.disabled = disabled;
        made.dataset.action = name;
        made.setAttribute("aria-label", label);
        made.addEventListener("click", act);
        line.append(made);
      };
      const move = (delta: number) => {
        const target = index + delta;
        [rows[index], rows[target]] = [rows[target], rows[index]];
        save();
        render({ index: target, action: delta < 0 ? "Up" : "Down" });
      };
      button("Up", `Move ${kind.noun} ${index + 1} up`, index === 0, () => move(-1));
      button("Down", `Move ${kind.noun} ${index + 1} down`, index === rows.length - 1, () => move(1));
      button("Remove", `Remove ${kind.noun} ${index + 1}`, false, () => {
        rows = rows.filter((_, at) => at !== index);
        save();
        render();
        add.focus();
      });
      container.append(line);
    });
    if (rows.length === 0) {
      const empty = element("p", "text-sm text-neutral-400");
      empty.textContent = kind.empty;
      container.append(empty);
    }
    // Keep the keyboard where it was after a row moves or changes kind.
    if (focus) {
      const line = container.children[focus.index];
      const target =
        focus.action === "select"
          ? line?.querySelector("select")
          : (line?.querySelector(`[data-action="${focus.action}"]:not(:disabled)`) ?? line?.querySelector("input"));
      (target as HTMLElement | null | undefined)?.focus();
    }
  };

  add.addEventListener("click", () => {
    if (rows.length >= kind.max) return;
    rows.push(kind.blank());
    save();
    render();
    (container.lastElementChild?.querySelector("input, select") as HTMLElement | null)?.focus();
  });

  save();
  render();
}

/**
 * A picture upload. A file that needs shrinking or converting is handled in
 * the browser, put back into the file box, and the form is then sent as an
 * ordinary post, so the page reloads with the answer beside the form.
 */
function imageForm(form: HTMLFormElement): void {
  const input = form.querySelector<HTMLInputElement>('input[type="file"]');
  const note = form.querySelector<HTMLInputElement>('input[name="note"]');
  const submit = form.querySelector<HTMLButtonElement>('button[type="submit"]');
  const refusal = form.querySelector<HTMLElement>("[data-refusal]");
  if (!input || !note || !submit || !refusal) return;

  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    refusal.classList.add("hidden");
    submit.disabled = true;
    submit.textContent = "Uploading…";

    const data = new FormData(form);
    const original = data.get("image");
    const state = await sendGameImage(
      (_previous, sent) => {
        const image = sent.get("image");
        if (image instanceof File && image !== original) {
          const transfer = new DataTransfer();
          transfer.items.add(image);
          input.files = transfer.files;
        }
        note.value = String(sent.get("note") ?? "");
        form.submit();
        // The page is leaving, so there is no answer to wait for.
        return new Promise(() => {});
      },
      null,
      data,
    );

    if (!state.ok) {
      refusal.textContent = state.message;
      refusal.classList.remove("hidden");
    }
    submit.disabled = false;
    submit.textContent = submit.dataset.label ?? "Upload";
  });
}

document.querySelectorAll<HTMLElement>("[data-list-editor]").forEach(listEditor);
document.querySelectorAll<HTMLFormElement>("form[data-game-image]").forEach(imageForm);
