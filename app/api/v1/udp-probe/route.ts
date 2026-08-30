import { randomBytes } from "node:crypto";
import { createSocket, type Socket } from "node:dgram";
import { NextResponse } from "next/server";

/**
 * A throwaway capability probe. Delete it once it has been read.
 *
 * It answers one question, coilbox issue 2139: can a function on this platform
 * send a UDP datagram and get a reply? Coilbox wants to tell a host whether
 * anybody outside can reach them, and today it guesses from a router's answer
 * and a STUN reflexive address, neither of which is somebody outside connecting
 * in. The hub is the only machine coilbox has that could do the connecting. The
 * whole design in `docs/superpowers/specs/2026-08-30-reachability-probe-design.md`
 * in the coilbox repository rests on this one fact, and it cannot be read out of
 * any documentation, so it has to be measured on a real deployment.
 *
 * This is the capability probe, not the probe service. The design's own rules
 * are what make that service safe, and none of them are here because none of
 * them are needed to answer this question.
 *
 * ## The address is hardcoded and never comes from the request
 *
 * A route that sends a packet to an address a caller names is a port scanner.
 * This route takes no input at all: no body, no query, no header is read. It has
 * one destination, written below, and there is no code path that could send
 * anywhere else. `stun.l.google.com` is already in coilbox's own STUN server
 * list, and a binding request is what that server exists to answer, so this is
 * indistinguishable from ordinary use of it.
 *
 * A binding request also proves both directions at once. The datagram has to
 * leave for the server to see it, and the reply has to arrive back through
 * whatever the platform puts in front of the function.
 *
 * ## Node.js, not Edge
 *
 * `node:dgram` does not exist on the Edge runtime, so this route has to be on
 * Node.js, and it cannot say so with `export const runtime`. `cacheComponents`
 * is on in `next.config.ts` and the build rejects that export outright, which is
 * why no route in this repository carries one. Node.js is the default and the
 * Edge runtime is deprecated in this version of Next, so there is nothing left
 * to choose. Rather than assert that, the answer carries `nodeVersion` straight
 * out of `process.version`, which is evidence from the running function instead
 * of a claim about it. Nothing but Node has one.
 *
 * Nothing caches this. A route handler without `use cache` runs per request
 * under `cacheComponents`, and the answer carries `no-store` so no CDN in front
 * can hold a measurement either.
 */
const STUN_HOST = "stun.l.google.com";
const STUN_PORT = 19302;

/**
 * The whole budget, covering the DNS lookup, the send and the wait for a reply.
 *
 * The slowest round trip anybody has measured for coilbox is 234 ms, across 90
 * `LISTCOMPFLAGS` exchanges with three real lobby servers on 30 August 2026,
 * recorded on `relay_host::MOVE_ANSWER_PATIENCE`. A STUN round trip to a public
 * server is the same kind of hop. Coilbox already doubles that figure to 500 ms
 * where it needs a budget rather than a deadline, in `EXIT_RENEWAL_BUDGET`.
 *
 * This doubles it again, because unlike those cases the DNS lookup for the host
 * name is inside the same budget and a cold function has no warm resolver behind
 * it. `sendMs` in the answer separates the two, so a slow lookup cannot be
 * mistaken for a datagram that never got out.
 */
const BUDGET_MS = 1000;

const MAGIC_COOKIE = [0x21, 0x12, 0xa4, 0x42];
const BINDING_SUCCESS = 0x0101;
const XOR_MAPPED_ADDRESS = 0x0020;

type ProbeAnswer = {
  server: string;
  /** Evidence the function is on Node.js rather than Edge, which has no dgram. */
  nodeVersion: string | null;
  budgetMs: number;
  socketOpened: boolean;
  sent: boolean;
  /** Time to resolve the host name and hand the datagram to the kernel. */
  sendMs: number | null;
  replied: boolean;
  /** The address the STUN server saw the datagram come from. */
  reflexive: string | null;
  elapsedMs: number;
  error: string | null;
};

function bindingRequest(transactionId: Buffer): Buffer {
  const message = Buffer.alloc(20);
  message.writeUInt16BE(0x0001, 0); // Binding request.
  message.writeUInt16BE(0, 2); // No attributes.
  Buffer.from(MAGIC_COOKIE).copy(message, 4);
  transactionId.copy(message, 8);
  return message;
}

/**
 * Pulls the reflexive address out of a binding success response, or null if the
 * datagram is not an answer to this request. The port and address are stored
 * exclusive-ored with the magic cookie, which is what the X in the attribute
 * name is.
 */
function reflexiveAddress(
  message: Buffer,
  transactionId: Buffer,
): string | null {
  if (message.length < 20) return null;
  if (message.readUInt16BE(0) !== BINDING_SUCCESS) return null;
  if (!message.subarray(4, 8).equals(Buffer.from(MAGIC_COOKIE))) return null;
  if (!message.subarray(8, 20).equals(transactionId)) return null;

  const end = Math.min(message.length, 20 + message.readUInt16BE(2));
  let offset = 20;
  while (offset + 4 <= end) {
    const type = message.readUInt16BE(offset);
    const valueLength = message.readUInt16BE(offset + 2);
    const value = message.subarray(offset + 4, offset + 4 + valueLength);
    // Attributes are padded to a four byte boundary.
    offset += 4 + valueLength + ((4 - (valueLength % 4)) % 4);

    if (type !== XOR_MAPPED_ADDRESS) continue;
    if (value.length < 8) continue;
    if (value.readUInt8(1) !== 0x01) continue; // IPv4 only.

    const port = value.readUInt16BE(2) ^ ((MAGIC_COOKIE[0] << 8) | MAGIC_COOKIE[1]);
    const host = Array.from(value.subarray(4, 8))
      .map((byte, index) => byte ^ MAGIC_COOKIE[index])
      .join(".");
    return `${host}:${port}`;
  }
  return null;
}

function probe(): Promise<ProbeAnswer> {
  return new Promise((resolve) => {
    const started = Date.now();
    const transactionId = randomBytes(12);
    const answer: ProbeAnswer = {
      server: `${STUN_HOST}:${STUN_PORT}`,
      nodeVersion: process.versions.node ?? null,
      budgetMs: BUDGET_MS,
      socketOpened: false,
      sent: false,
      sendMs: null,
      replied: false,
      reflexive: null,
      elapsedMs: 0,
      error: null,
    };

    let socket: Socket;
    try {
      socket = createSocket("udp4");
      answer.socketOpened = true;
    } catch (cause) {
      answer.error = `socket: ${String(cause)}`;
      answer.elapsedMs = Date.now() - started;
      resolve(answer);
      return;
    }

    let settled = false;
    // The hard stop. Without it a socket that never hears anything holds the
    // function open until the platform kills it.
    const deadline = setTimeout(() => {
      if (answer.error === null) answer.error = "no reply within the budget";
      finish();
    }, BUDGET_MS);

    function finish() {
      if (settled) return;
      settled = true;
      clearTimeout(deadline);
      answer.elapsedMs = Date.now() - started;
      try {
        socket.close();
      } catch {
        // Already closed, or never bound. Nothing left to release.
      }
      resolve(answer);
    }

    socket.on("error", (cause) => {
      answer.error = `socket: ${String(cause)}`;
      finish();
    });

    socket.on("message", (message) => {
      const reflexive = reflexiveAddress(message, transactionId);
      // A datagram from anything but this exchange is not an answer, so keep
      // waiting rather than reporting somebody else's packet as a reply.
      if (reflexive === null) return;
      answer.replied = true;
      answer.reflexive = reflexive;
      answer.error = null;
      finish();
    });

    socket.send(
      bindingRequest(transactionId),
      STUN_PORT,
      STUN_HOST,
      (cause) => {
        answer.sendMs = Date.now() - started;
        if (cause) {
          answer.error = `send: ${String(cause)}`;
          finish();
          return;
        }
        answer.sent = true;
      },
    );
  });
}

export async function GET() {
  return NextResponse.json(await probe(), {
    headers: { "Cache-Control": "no-store" },
  });
}
