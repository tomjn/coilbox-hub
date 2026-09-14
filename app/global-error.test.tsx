import { expect, test } from "bun:test";
import { renderToStaticMarkup } from "react-dom/server";
import GlobalError from "@/app/global-error";

// #363: a root layout failure (for example `NavAccount` throwing) loses the
// header regardless, since `error.tsx` does not wrap `layout.tsx`. This file
// still owes the visitor the same message and a way back, in its own
// `<html>` and `<body>`.

test("renders its own document with the same message and controls as the page-level fallback", () => {
  const html = renderToStaticMarkup(
    <GlobalError error={new Error("boom")} retry={() => {}} />,
  );
  expect(html).toContain("<html");
  expect(html).toContain("<body");
  expect(html).toContain("Something went wrong");
  expect(html).toContain('role="alert"');
  expect(html).toContain("Nothing you entered was necessarily saved");
  expect(html).toContain("Try again");
  expect(html).toContain('href="/"');
  expect(html).toContain("Go home");
});
