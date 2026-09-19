import type { LobbyCommands as Commands } from "@/lib/workshop/lobbyCommands";
import { CopyCommands } from "./CopyCommands";

/**
 * The way to use an item without coilbox (issue #418): the autohost commands,
 * one per line, each with its own copy button because a lobby chat takes one
 * line at a time.
 *
 * Two items reach here and they are not read the same way. A project's lines
 * each set one option, so their order is only for reading. A preset's rebuild a
 * game setup step by step, and the map has to be set before the lines that
 * resolve against it, so the page asks for them in order.
 *
 * Whether the game in question reads tweak options is not checked here, and
 * the page names no game: more than one reads them, and which do is coilbox's
 * to know.
 */
export function LobbyCommands({ commands }: { commands: Commands }) {
  const { kind, lines, withheld, notCarried, chunks } = commands;
  const preset = kind === "preset";

  return (
    <section
      id="lobby"
      className="flex min-w-0 flex-col gap-4 rounded-md border border-neutral-800 bg-card p-5"
    >
      <div className="flex flex-col gap-1.5">
        <h2 className="text-lg font-semibold tracking-tight">Autohost commands</h2>
        {lines.length > 0 ? (
          <p className="text-sm text-neutral-400">
            Paste {lines.length === 1 ? "this command" : `these ${lines.length} commands`} into
            a lobby&rsquo;s chat.
          </p>
        ) : null}
      </div>

      {lines.length > 0 ? (
        <CopyCommands lines={lines} />
      ) : (
        <div className="flex flex-col gap-1.5 text-sm text-neutral-400">
          <p>
            This {preset ? "preset" : "project"} cannot be{" "}
            {preset ? "rebuilt in a lobby" : "pasted into a lobby whole"}, so no commands are
            offered.
          </p>
          <ul className="list-disc pl-5">
            {withheld.map((reason) => (
              <li key={reason}>{reason}</li>
            ))}
          </ul>
          <p>Import it into Coilbox to play {preset ? "it" : "with it"}.</p>
        </div>
      )}

      {lines.length > 0 && notCarried.length > 0 ? (
        <div className="flex flex-col gap-1.5 text-sm text-neutral-400">
          <p>What these commands could not set:</p>
          <ul className="list-disc pl-5">
            {notCarried.map((note) => (
              <li key={note}>{note}</li>
            ))}
          </ul>
        </div>
      ) : null}

      {/* A preset carries no Lua at all: its commands name a map, an option or
          a bot, and there is nothing behind them to read. */}
      {chunks.length === 0 ? null : (
      <details className="group text-sm">
        <summary className="cursor-pointer self-start text-neutral-300 underline-offset-4 hover:text-white hover:underline">
          Read the Lua {lines.length > 0 ? "these commands carry" : "it compiles to"}
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
      )}
    </section>
  );
}
