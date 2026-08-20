import { expect, test } from "bun:test";
import { renderToStaticMarkup } from "react-dom/server";
import { BusyForm } from "@/components/BusyForm";

test("renders a plain form, not busy, with the group class its button reads", () => {
  const html = renderToStaticMarkup(
    <BusyForm action="/gallery" className="flex gap-2">
      <button type="submit">Search</button>
    </BusyForm>,
  );
  expect(html).toContain('<form class="group flex gap-2" action="/gallery">');
  expect(html).not.toContain("aria-busy");
});

test("a form with no classes of its own still carries group", () => {
  const html = renderToStaticMarkup(<BusyForm action="/maps">x</BusyForm>);
  expect(html).toContain('class="group"');
});
