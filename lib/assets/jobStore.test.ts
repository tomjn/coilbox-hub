import { expect, test } from "bun:test";
import { hubPromotionStore, promotionStoreFromEnv } from "./jobStore";
import { StagingReadError } from "./promote";

/**
 * The promotion job's client for the hub (#616): the routes it calls, the
 * token it sends, and how a refusal reaches the run.
 */

interface Sent {
  url: string;
  method: string;
  headers: Record<string, string>;
  body: unknown;
}

function fakeHub(reply: (sent: Sent) => Response): { fetcher: typeof fetch; sent: Sent[] } {
  const sent: Sent[] = [];
  const fetcher = (async (url: string, init: RequestInit = {}) => {
    const request: Sent = {
      url,
      method: init.method ?? "GET",
      headers: init.headers as Record<string, string>,
      body: init.body ? JSON.parse(init.body as string) : null,
    };
    sent.push(request);
    return reply(request);
  }) as unknown as typeof fetch;
  return { fetcher, sent };
}

const json = (body: unknown, status = 200) =>
  new Response(JSON.stringify(body), { status, headers: { "content-type": "application/json" } });

test("every request goes to the hub's promotion routes with the token", async () => {
  const { fetcher, sent } = fakeHub((request) =>
    request.url.endsWith("/promote") ? json([{ id: "a", blob_path: "units/x.webp" }]) : json({ paths: ["units/x.webp"] }),
  );
  const store = hubPromotionStore("https://hub.test/", "cbpj_secret", fetcher);

  expect(await store.promote(["a"], ["units/bar/x.webp"])).toEqual([{ id: "a", blob_path: "units/x.webp" }]);
  expect(await store.reserve(["units/x.webp"], true)).toEqual(["units/x.webp"]);

  expect(sent.map((request) => `${request.method} ${request.url}`)).toEqual([
    "POST https://hub.test/api/v1/promotion/promote",
    "POST https://hub.test/api/v1/promotion/staged/reserve",
  ]);
  expect(sent[0].headers.authorization).toBe("Bearer cbpj_secret");
  expect(sent[0].body).toEqual({ ids: ["a"], paths: ["units/bar/x.webp"] });
  expect(sent[1].body).toEqual({ paths: ["units/x.webp"], queued_is_claimed: true });
});

test("a refusal is an error naming the route and the hub's reason, never an empty answer", async () => {
  const { fetcher } = fakeHub(() => json({ error: "Reserve every path before deleting it." }, 409));
  const store = hubPromotionStore("https://hub.test", "t", fetcher);

  await expect(store.discard(["units/x.webp"])).rejects.toThrow(
    "409 from the hub for staged/delete: Reserve every path before deleting it.",
  );
  await expect(store.due(200)).rejects.toThrow("409 from the hub for due?limit=200");
});

test("a staged read answers the bytes, and a refusal keeps its status so absent and refused differ", async () => {
  const { fetcher, sent } = fakeHub((request) =>
    request.url.includes("there") ? new Response(new Uint8Array([1, 2, 3])) : json({ error: "no" }, 404),
  );
  const store = hubPromotionStore("https://hub.test", "t", fetcher);

  expect(await store.readStaged("games/BA/there.webp")).toEqual(new Uint8Array([1, 2, 3]));
  expect(sent[0].url).toBe("https://hub.test/api/v1/promotion/staged?path=games%2FBA%2Fthere.webp");

  const refused = await store.readStaged("units/gone.webp").catch((error) => error);
  expect(refused).toBeInstanceOf(StagingReadError);
  expect(refused.status).toBe(404);
});

test("the scripts need both the hub's address and the token", () => {
  expect(promotionStoreFromEnv({ HUB_URL: "https://hub.test" })).toHaveProperty("error");
  expect(promotionStoreFromEnv({ PROMOTION_TOKEN: "t" })).toHaveProperty("error");
  const made = promotionStoreFromEnv({ HUB_URL: "http://localhost:8100", PROMOTION_TOKEN: "t" });
  expect("host" in made && made.host).toBe("localhost:8100");
});
