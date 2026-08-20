import { expect, test } from "bun:test";
import { renderToStaticMarkup } from "react-dom/server";
import { Skeleton } from "@/components/Skeleton";

test("a skeleton is decorative and stops pulsing when motion is reduced", () => {
  const html = renderToStaticMarkup(<Skeleton className="h-4 w-24" />);
  expect(html).toContain('aria-hidden="true"');
  expect(html).toContain("animate-pulse");
  expect(html).toContain("motion-reduce:animate-none");
  expect(html).toContain("h-4 w-24");
});
