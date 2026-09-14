import { expect, test } from "bun:test";
import { renderToStaticMarkup } from "react-dom/server";
import ErrorPage from "@/app/error";

// #363: an error thrown while rendering a page, or by a server action a
// page's form submitted, should show inside the hub's own layout rather
// than replacing it. This only checks the markup this file renders. The
// header staying in place is a property of where Next mounts it, verified
// in a browser rather than here.

test("names the problem, says nothing was necessarily saved, and offers a way back", () => {
  const html = renderToStaticMarkup(
    <ErrorPage error={new Error("boom")} retry={() => {}} />,
  );
  expect(html).toContain("Something went wrong");
  expect(html).toContain('role="alert"');
  expect(html).toContain("Nothing you entered was necessarily saved");
});

test("offers a try again control and a link home", () => {
  const html = renderToStaticMarkup(
    <ErrorPage error={new Error("boom")} retry={() => {}} />,
  );
  expect(html).toContain("Try again");
  expect(html).toContain('href="/"');
  expect(html).toContain("Go home");
});
