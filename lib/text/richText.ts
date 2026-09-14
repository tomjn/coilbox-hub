/**
 * A minimal, dependency-free parser for the four marks a game's description
 * may carry (#357): paragraphs, line breaks, `**bold**` and `*italic*` or
 * `_italic_`. Nothing else is markup - no links, headings or raw HTML - which
 * is what lets the renderer in `components/RichText.tsx` turn the tree this
 * produces into React elements instead of a string of HTML. A description
 * cannot inject a tag of its own, because nothing here ever builds one.
 */

export type InlineNode =
  | { type: "text"; value: string }
  | { type: "bold"; children: InlineNode[] }
  | { type: "italic"; children: InlineNode[] };

/** One paragraph, as the lines a single line break inside it keeps apart. */
export type Paragraph = InlineNode[][];

const isWordChar = (ch: string): boolean => /[A-Za-z0-9]/.test(ch);

/**
 * The index of the mark that closes the one opened at `start`, or -1 when
 * none exists. A `**` run met while hunting for a different mark belongs to
 * somebody else's bold, so its own close is found first and the hunt resumes
 * past it - that is what lets `*italic **bold** italic*` nest rather than
 * having the inner `**` mistaken for the italic's own close.
 */
function findClose(text: string, start: number, marker: "**" | "*" | "_"): number {
  let i = start;
  while (i < text.length) {
    if (text.startsWith("**", i)) {
      if (marker === "**") return i;
      const nestedClose = findClose(text, i + 2, "**");
      i = nestedClose === -1 ? i + 2 : nestedClose + 2;
      continue;
    }
    if (marker === "*" && text[i] === "*") return i;
    if (marker === "_" && text[i] === "_") {
      const after = text[i + 1];
      if (after === undefined || !isWordChar(after)) return i;
    }
    i++;
  }
  return -1;
}

/**
 * One line's bold and italic, as a tree rather than a string. An underscore
 * only opens italic at a word boundary, so `snake_case_name` is not three
 * words fighting for emphasis, and a marker with no matching close is left as
 * the literal characters it is.
 */
export function parseInline(text: string): InlineNode[] {
  const nodes: InlineNode[] = [];
  let literal = "";
  const flush = () => {
    if (literal) {
      nodes.push({ type: "text", value: literal });
      literal = "";
    }
  };

  let i = 0;
  while (i < text.length) {
    const ch = text[i];

    if (ch === "*" && text[i + 1] === "*") {
      const close = findClose(text, i + 2, "**");
      if (close !== -1) {
        flush();
        nodes.push({ type: "bold", children: parseInline(text.slice(i + 2, close)) });
        i = close + 2;
        continue;
      }
    } else if (ch === "*") {
      const close = findClose(text, i + 1, "*");
      if (close !== -1) {
        flush();
        nodes.push({ type: "italic", children: parseInline(text.slice(i + 1, close)) });
        i = close + 1;
        continue;
      }
    } else if (ch === "_") {
      const before = text[i - 1];
      const boundary = before === undefined || !isWordChar(before);
      if (boundary) {
        const close = findClose(text, i + 1, "_");
        if (close !== -1) {
          flush();
          nodes.push({ type: "italic", children: parseInline(text.slice(i + 1, close)) });
          i = close + 1;
          continue;
        }
      }
    }

    literal += ch;
    i++;
  }
  flush();
  return nodes;
}

/**
 * The description as paragraphs of lines. Windows line endings normalise to
 * `\n` first. Two or more consecutive newlines start a new paragraph - three
 * or more collapse to that same single break, rather than an empty paragraph
 * for every extra blank line - and a lone newline inside a paragraph is kept
 * as a line break. Leading and trailing whitespace on the whole description,
 * and on each paragraph and line, is dropped.
 */
export function parseRichText(raw: string): Paragraph[] {
  const normalised = raw.replace(/\r\n?/g, "\n").trim();
  if (!normalised) return [];

  return normalised
    .split(/\n{2,}/)
    .map((block) => block.trim())
    .filter((block) => block !== "")
    .map((block) => block.split("\n").map((line) => parseInline(line.trim())));
}

function plainTextOf(nodes: InlineNode[]): string {
  return nodes.map((node) => (node.type === "text" ? node.value : plainTextOf(node.children))).join("");
}

/**
 * The description with every mark stripped and every break turned into a
 * space: the one place #357 asks for plain text, the meta and Open Graph
 * description. A social preview does not show paragraphs anyway, so one line
 * is the honest shape for it.
 */
export function richTextToPlainText(raw: string): string {
  return parseRichText(raw)
    .map((paragraph) => paragraph.map(plainTextOf).join(" "))
    .join(" ")
    .trim();
}

/**
 * All the inline formatting a description carries, run together into one flow
 * with paragraph and line breaks turned into a plain space. This is what the
 * listing card uses: bold and italic still show, but nothing forces a line of
 * its own the way a paragraph or a line break would, so a card's line clamp
 * still measures wrapped lines rather than the writer's paragraphs.
 */
export function richTextInline(raw: string): InlineNode[] {
  const nodes: InlineNode[] = [];
  for (const paragraph of parseRichText(raw)) {
    for (const line of paragraph) {
      if (nodes.length > 0) nodes.push({ type: "text", value: " " });
      nodes.push(...line);
    }
  }
  return nodes;
}
