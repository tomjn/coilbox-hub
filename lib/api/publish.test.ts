import { expect, test } from "bun:test";
import { parsePublishBody, statusForPublishFailure } from "./publish";

test("a well formed publish body parses", () => {
  const result = parsePublishBody({
    code: "some-code",
    title: "Title",
    description: "A description",
    tags: ["eco", "rush"],
  });

  expect(result.ok).toBe(true);
  if (!result.ok) return;
  expect(result.fields).toEqual({
    code: "some-code",
    title: "Title",
    description: "A description",
    tags: ["eco", "rush"],
  });
});

test("description and tags are optional and default to empty", () => {
  const result = parsePublishBody({ code: "some-code", title: "Title" });

  expect(result.ok).toBe(true);
  if (!result.ok) return;
  expect(result.fields.description).toBe("");
  expect(result.fields.tags).toEqual([]);
});

test("a non-object body is rejected", () => {
  const result = parsePublishBody("just a string");

  expect(result.ok).toBe(false);
  if (result.ok) return;
  expect(result.error).toBe("The request body must be a JSON object.");
});

test("an array body is rejected", () => {
  const result = parsePublishBody([1, 2, 3]);

  expect(result.ok).toBe(false);
  if (result.ok) return;
  expect(result.error).toBe("The request body must be a JSON object.");
});

test("null is rejected", () => {
  const result = parsePublishBody(null);

  expect(result.ok).toBe(false);
  if (result.ok) return;
  expect(result.error).toBe("The request body must be a JSON object.");
});

test("an unknown field is rejected rather than silently ignored", () => {
  const result = parsePublishBody({ code: "c", title: "t", author_name: "someone else" });

  expect(result.ok).toBe(false);
  if (result.ok) return;
  expect(result.error).toBe("Unknown field: author_name");
});

test("a missing code is rejected", () => {
  const result = parsePublishBody({ title: "Title" });

  expect(result.ok).toBe(false);
  if (result.ok) return;
  expect(result.error).toBe("`code` is required and must be a string.");
});

test("a blank code is rejected", () => {
  const result = parsePublishBody({ code: "   ", title: "Title" });

  expect(result.ok).toBe(false);
  if (result.ok) return;
  expect(result.error).toBe("`code` is required and must be a string.");
});

test("a non-string code is rejected", () => {
  const result = parsePublishBody({ code: 123, title: "Title" });

  expect(result.ok).toBe(false);
  if (result.ok) return;
  expect(result.error).toBe("`code` is required and must be a string.");
});

test("a missing title is rejected", () => {
  const result = parsePublishBody({ code: "c" });

  expect(result.ok).toBe(false);
  if (result.ok) return;
  expect(result.error).toBe("`title` is required and must be a string.");
});

test("a non-string description is rejected", () => {
  const result = parsePublishBody({ code: "c", title: "t", description: 5 });

  expect(result.ok).toBe(false);
  if (result.ok) return;
  expect(result.error).toBe("`description` must be a string.");
});

test("non-array tags are rejected", () => {
  const result = parsePublishBody({ code: "c", title: "t", tags: "eco,rush" });

  expect(result.ok).toBe(false);
  if (result.ok) return;
  expect(result.error).toBe("`tags` must be an array of strings.");
});

test("a tags array with a non-string entry is rejected", () => {
  const result = parsePublishBody({ code: "c", title: "t", tags: ["eco", 5] });

  expect(result.ok).toBe(false);
  if (result.ok) return;
  expect(result.error).toBe("`tags` must be an array of strings.");
});

test("every publishItem failure status maps to the right HTTP status", () => {
  expect(statusForPublishFailure("invalid")).toBe(422);
  expect(statusForPublishFailure("rate_limited")).toBe(429);
  expect(statusForPublishFailure("storage_error")).toBe(500);
});
