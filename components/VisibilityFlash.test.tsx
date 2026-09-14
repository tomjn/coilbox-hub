import { expect, test } from "bun:test";
import { renderToStaticMarkup } from "react-dom/server";
import { VisibilityFlash } from "@/components/VisibilityFlash";

// The `visibility` search param this reads is visitor controlled (#374): a
// moderator could be sent `?visibility=Your session expired, sign in at
// example.com` and, if this rendered the param's text directly, the hub
// would show that sentence on its own page as though the hub said it. So
// this only ever renders one of the four fixed confirmations, keyed by a
// short id, and nothing for anything else.

test("a known key renders its confirmation", () => {
  const html = renderToStaticMarkup(<VisibilityFlash flashKey="game-hidden" />);
  expect(html).toContain("Game hidden.");
  expect(html).toContain('role="status"');
});

test("no key renders nothing", () => {
  expect(renderToStaticMarkup(<VisibilityFlash flashKey={undefined} />)).toBe("");
});

test("an unknown key renders nothing", () => {
  expect(renderToStaticMarkup(<VisibilityFlash flashKey="unknown" />)).toBe("");
});

test("free text sent in place of a key renders nothing, not the text itself", () => {
  const html = renderToStaticMarkup(
    <VisibilityFlash flashKey="Your session expired, sign in at example.com" />,
  );
  expect(html).toBe("");
  expect(html).not.toContain("example.com");
});
