import Link from "next/link";
import { notFound, redirect } from "next/navigation";
import { setGameVisibility, setVersionVisibility } from "@/app/games/actions";
import { VisibilityFlash } from "@/components/VisibilityFlash";
import { VisibilityToggleForm } from "@/components/VisibilityToggleForm";
import { editableGame } from "@/lib/games/editor";
import { loadGamePage } from "@/lib/games/page";
import { createClient } from "@/lib/supabase/server";
import { GameDetailsForm } from "./GameDetailsForm";
import { GameImageForm } from "./GameImageForm";
import { GameImageRemoveForm } from "./GameImageRemoveForm";

/**
 * The edit page for a game's owner or a moderator (#229, #350).
 *
 * Four forms, because they are two different kinds of write: words (a plain
 * update through row level security), and three pictures (bytes to the staging
 * bucket, then a path onto the row). One form per job means a failed upload
 * never takes the description with it.
 *
 * Everything here is ordinary forms posting to server actions, except the three
 * uploads, which check a file's size in the browser first (`GameImageForm`),
 * and the controls that remove a picture (`GameImageRemoveForm`, #360).
 * The links rows are a fixed set of five pairs rather than a dynamic add
 * button, because a button that needs a bundle to work is a worse trade than
 * five rows nobody has to fill in.
 */

export default async function EditGame({
  params,
  searchParams,
}: {
  params: Promise<{ shortname: string }>;
  searchParams: Promise<{ visibility?: string | string[] }>;
}) {
  const { shortname } = await params;
  const { visibility } = await searchParams;
  const flash = typeof visibility === "string" ? visibility : undefined;

  const supabase = await createClient();
  const {
    data: { user },
  } = await supabase.auth.getUser();
  if (!user) redirect("/auth/sign-in");

  // Read with the session client rather than the cached one, for the reason
  // that makes this page exist at all: an owner or a moderator must be able to
  // reach a game's edit form after hiding it, and the cached read answers as
  // anon, which a hidden row is invisible to. The read policy on the table is
  // what lets this read through for both.
  const page = await loadGamePage(supabase, shortname);
  if (!page) notFound();

  // Neither the owner nor a moderator (#350)? The route exists but holds
  // nothing for them, which is the same answer an unknown shortname gets.
  if (!(await editableGame(supabase, user.id, shortname))) notFound();

  // Every release reported so far, including hidden ones, since managing them
  // is what this page is for. The public pickers filter those themselves.
  const { data: versions } = await supabase
    .from("game_version")
    .select("version,hidden_at,game!inner(shortname)")
    .eq("game.shortname", shortname)
    .order("last_seen_at", { ascending: false });
  const versionRows = (versions ?? []) as unknown as { version: string; hidden_at: string | null }[];

  return (
    <main className="mx-auto w-full max-w-3xl flex-1 px-6 py-10">
      <nav className="text-sm text-neutral-500" aria-label="Breadcrumb">
        <Link href={`/games/${shortname}`} className="underline-offset-4 hover:underline active:underline">
          {shortname}
        </Link>
        <span aria-hidden> / </span>
        <span className="text-neutral-300">Edit</span>
      </nav>

      <div className="flex flex-col gap-10 pt-8">
        <div className="flex flex-col gap-2">
          <h1 className="text-2xl font-semibold tracking-tight">Edit {page.shortname}</h1>
          <p className="text-sm text-neutral-400">
            Your words sit on top of whatever was backfilled from the archives, and a reader sees
            them where they exist.
          </p>
        </div>

        <GameDetailsForm
          shortname={shortname}
          displayName={page.display_name ?? ""}
          description={page.description ?? ""}
          links={page.links}
        />

        <section className="flex flex-col gap-3 border-t border-neutral-900 pt-6">
          <h2 className="text-sm uppercase tracking-wide text-neutral-400">Visibility</h2>
          <VisibilityFlash flashKey={flash} />
          <p className="text-sm text-neutral-500">
            Hidden means off the site for everybody but the owner and moderators. Facts keep flowing; unhiding brings
            everything back.
          </p>
          <div className="flex items-center gap-3">
            <VisibilityToggleForm
              action={setGameVisibility}
              fields={{ shortname, hidden: page.hidden_at ? "false" : "true" }}
              label={page.hidden_at ? "Unhide this game" : "Hide this game"}
              pendingLabel={page.hidden_at ? "Unhiding…" : "Hiding…"}
              buttonClassName="rounded-md border border-neutral-800 px-4 py-2 text-sm text-neutral-300 transition-colors hover:border-neutral-600 active:border-neutral-500 hover:text-white active:text-white disabled:opacity-60"
            />
            {page.hidden_at ? (
              <span className="text-xs text-neutral-500">This game is hidden right now.</span>
            ) : null}
          </div>

          <ul className="flex flex-col gap-1.5 text-sm">
            {versionRows.map((row) => (
              <li key={row.version} className="flex items-center justify-between gap-3">
                <span className="font-mono text-neutral-300">{row.version}</span>
                <VisibilityToggleForm
                  action={setVersionVisibility}
                  fields={{ shortname, version: row.version, hidden: row.hidden_at ? "false" : "true" }}
                  label={row.hidden_at ? "Unhide release" : "Hide release"}
                  pendingLabel={row.hidden_at ? "Unhiding…" : "Hiding…"}
                  buttonClassName="rounded-md border border-neutral-800 px-3 py-1 text-xs text-neutral-400 transition-colors hover:border-neutral-600 active:border-neutral-500 hover:text-neutral-200 active:text-neutral-200 disabled:opacity-60"
                />
              </li>
            ))}
          </ul>
        </section>

        <section className="flex flex-col gap-3 border-t border-neutral-900 pt-6">
          <h2 className="text-sm uppercase tracking-wide text-neutral-400">Logo</h2>
          <p className="text-sm text-neutral-500">Square, PNG or WebP, up to 512 KB.</p>
          <GameImageForm shortname={shortname} kind="logo" />
          <GameImageRemoveForm shortname={shortname} kind="logo" present={page.logo_path !== null} />
        </section>

        <section className="flex flex-col gap-3 border-t border-neutral-900 pt-6">
          <h2 className="text-sm uppercase tracking-wide text-neutral-400">Banner</h2>
          <p className="text-sm text-neutral-500">Wide, PNG or WebP, up to 512 KB.</p>
          <GameImageForm shortname={shortname} kind="banner" />
          <GameImageRemoveForm shortname={shortname} kind="banner" present={page.banner_path !== null} />
        </section>

        <section className="flex flex-col gap-3 border-t border-neutral-900 pt-6">
          <h2 className="text-sm uppercase tracking-wide text-neutral-400">Card art</h2>
          <p className="text-sm text-neutral-500">
            16:9, PNG or WebP, up to 512 KB. The games listing draws this above the name, and a lobby
            shows it on its own card for the game.
          </p>
          <GameImageForm shortname={shortname} kind="card" />
          <GameImageRemoveForm shortname={shortname} kind="card" present={page.card_path !== null} />
        </section>
      </div>
    </main>
  );
}
