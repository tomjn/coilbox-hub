import { Fragment } from "react";
import type { InlineNode, Paragraph } from "@/lib/text/richText";
import { parseRichText, richTextInline } from "@/lib/text/richText";

/**
 * Turns the tree `lib/text/richText.ts` parses into React elements (#357).
 * Never a string of HTML: a bold or italic node becomes a real `<strong>` or
 * `<em>`, so a description cannot smuggle in a tag of its own.
 */

function Inline({ nodes }: { nodes: InlineNode[] }) {
  return (
    <>
      {nodes.map((node, index) => {
        if (node.type === "text") return <Fragment key={index}>{node.value}</Fragment>;
        if (node.type === "bold")
          return (
            <strong key={index}>
              <Inline nodes={node.children} />
            </strong>
          );
        return (
          <em key={index}>
            <Inline nodes={node.children} />
          </em>
        );
      })}
    </>
  );
}

function ParagraphLines({ lines }: { lines: Paragraph }) {
  return (
    <>
      {lines.map((line, index) => (
        <Fragment key={index}>
          {index > 0 ? <br /> : null}
          <Inline nodes={line} />
        </Fragment>
      ))}
    </>
  );
}

/**
 * The full shape: one `<p>` per paragraph, a `<br>` for every line break
 * inside one. `className` lands on each `<p>`, for a game's own page, where
 * paragraph spacing is what a reader expects from ordinary written text.
 */
export function RichText({ text, className }: { text: string; className?: string }) {
  const paragraphs = parseRichText(text);
  if (paragraphs.length === 0) return null;

  return (
    <div className="flex flex-col gap-3">
      {paragraphs.map((lines, index) => (
        <p key={index} className={className}>
          <ParagraphLines lines={lines} />
        </p>
      ))}
    </div>
  );
}

/**
 * Bold and italic with paragraph and line breaks flattened to a space, for a
 * spot that clamps to a fixed number of lines (the listing card, #357):
 * nothing here forces a line of its own, so the clamp keeps counting wrapped
 * lines rather than the writer's paragraphs.
 */
export function RichTextInline({ text }: { text: string }) {
  return <Inline nodes={richTextInline(text)} />;
}
