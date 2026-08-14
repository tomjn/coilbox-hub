import { expect, test } from "bun:test";
import { clientIp, isIpAddress } from "./uploadIp";

function headers(values: Record<string, string>): Headers {
  return new Headers(values);
}

test("the platform's own header wins, since a client cannot add to it", () => {
  expect(
    clientIp(
      headers({
        "x-real-ip": "203.0.113.7",
        "x-forwarded-for": "10.0.0.1, 203.0.113.7",
      }),
    ),
  ).toBe("203.0.113.7");
});

test("the forwarded list falls back to its first entry", () => {
  expect(clientIp(headers({ "x-forwarded-for": "203.0.113.7, 10.0.0.1" }))).toBe("203.0.113.7");
});

test("an address the hub cannot make sense of is recorded as nothing at all", () => {
  expect(clientIp(headers({ "x-real-ip": "not-an-address" }))).toBeNull();
  expect(clientIp(headers({}))).toBeNull();
});

/** A header full of anything reaching an `inet` column is a failed insert
 * discovered as a missing row weeks later, so it is refused here first. */
test("what counts as an address", () => {
  expect(isIpAddress("203.0.113.7")).toBe(true);
  expect(isIpAddress("2001:db8::1")).toBe(true);
  expect(isIpAddress("::1")).toBe(true);
  expect(isIpAddress("203.0.113.999")).toBe(false);
  expect(isIpAddress("203.0.113")).toBe(false);
  expect(isIpAddress("")).toBe(false);
  expect(isIpAddress("'; delete from asset_upload_ip")).toBe(false);
});
