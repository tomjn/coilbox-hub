import type { LobbyCommands as Commands } from "@/lib/workshop/lobbyCommands";
import { CopyLine } from "./CopyLine";

/**
 * The way to play a project without coilbox (issue #418): the autohost
 * commands, one per line, each with its own copy button because a lobby chat
 * takes one line at a time.
 *
 * Whether the game in question reads tweak options is not checked here. The
 * page says what the lines are and what a game without them does, and leaves
 * it there.
 */
export function LobbyCommands({ commands }: { commands: Commands }) {
  const { lines, withheld, notCarried, chunks } = commands;

  return (
    <section id="lobby" className="flex flex-col gap-4">
      <div className="flex flex-col gap-1.5">
        <h2 className="text-lg font-semibold tracking-tight">Play it without Coilbox</h2>
        {lines.length > 0 ? (
          <p className="text-sm text-neutral-400">
            Paste {lines.length === 1 ? "this line" : `these ${lines.length} lines`} into a
            lobby&rsquo;s chat and everyone in it plays with the changes. No download, for you
            or for them.
          </p>
        ) : null}
      </div>

      {lines.length > 0 ? (
        <>
          <ol className="flex list-decimal flex-col gap-1.5 pl-5 text-sm text-neutral-300 marker:text-neutral-500">
            <li>Open a multiplayer lobby for this game. You may need to be its boss.</li>
            <li>
              Copy {lines.length === 1 ? "the line" : "each line"} below and send it as a chat
              message{lines.length === 1 ? "" : ", one at a time"}.
            </li>
            <li>Start the game.</li>
          </ol>
          <ul className="flex flex-col gap-2">
            {lines.map((line, index) => (
              <CopyLine key={line} line={line} index={index} total={lines.length} />
            ))}
          </ul>
          <p className="text-xs text-neutral-400">
            These set the <code>tweakdefs</code> and <code>tweakunits</code> options that
            Beyond All Reason&rsquo;s lobbies read. A game that has no such options ignores
            them, and Coilbox can apply the project there instead.
          </p>
        </>
      ) : (
        <div className="flex flex-col gap-1.5 text-sm text-neutral-400">
          <p>This project cannot be pasted into a lobby whole, so no lines are offered.</p>
          <ul className="list-disc pl-5">
            {withheld.map((reason) => (
              <li key={reason}>{reason}</li>
            ))}
          </ul>
          <p>Import it into Coilbox to play with it.</p>
        </div>
      )}

      {lines.length > 0 && notCarried.length > 0 ? (
        <div className="flex flex-col gap-1.5 text-sm text-neutral-400">
          <p>The lines leave out what a lobby cannot carry:</p>
          <ul className="list-disc pl-5">
            {notCarried.map((note) => (
              <li key={note}>{note}</li>
            ))}
          </ul>
        </div>
      ) : null}

      <details className="group text-sm">
        <summary className="cursor-pointer self-start text-neutral-300 underline-offset-4 hover:text-white hover:underline">
          Read the Lua {lines.length > 0 ? "these lines carry" : "it compiles to"}
        </summary>
        <div className="mt-3 flex flex-col gap-4">
          {chunks.map((chunk) => (
            <figure key={chunk.title} className="flex flex-col gap-1.5">
              <figcaption className="flex flex-col gap-0.5">
                <span className="text-neutral-100">{chunk.title}</span>
                <span className="text-xs text-neutral-400">{chunk.reason}</span>
              </figcaption>
              <pre className="max-h-96 overflow-auto rounded border border-neutral-800 bg-black p-3 text-xs text-neutral-300">
                {chunk.lua}
              </pre>
            </figure>
          ))}
        </div>
      </details>
    </section>
  );
}
