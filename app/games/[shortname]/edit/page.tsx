import Link from "next/link";
import { notFound, redirect } from "next/navigation";
import { editGameDetails, setGameVisibility, setVersionVisibility, uploadGameImage } from "@/app/games/actions";
import { loadGamePage } from "@/lib/games/page";
import { createClient } from "@/lib/supabase/server";

/**
 * The owner's edit page (#229).
 *
 * Three forms, because they are three different kinds of write: words (a plain
 * update through row level security), and two images (bytes to Blob, then a
 * path onto the row). One form per job means a failed upload never takes the
 * description with it.
 *
 * Everything here is ordinary forms posting to server actions. The links rows
 * are a fixed set of five pairs rather than a dynamic add button, because a
 * button that needs a bundle to work is a worse trade than five rows nobody
 * has to fill in.
 */

const CONTROL =
  "w-full rounded-md border border-neutral-800 bg-neutral-950 px-3 py-2 text-sm text-neutral-100 placeholder:text-neutral-500 focus-visible:border-neutral-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-neutral-400";

const LABEL = "text-xs uppercase tracking-wide text-neutral-400";

export default async function EditGame({
  params,
}: {
  params: Promise<{ shortname: string }>;
}) {
  const { shortname } = await params;

  const supabase = await createClient();
  const {
    data: { user },
  } = await supabase.auth.getUser();
  if (!user) redirect("/auth/sign-in");

  // Read with the session client rather than the cached one, for the reason
  // that makes this page exist at all: an owner must be able to reach their
  // game's edit form after hiding it, and the cached read answers as anon,
  // which a hidden row is invisible to. The owner policy on the table is what
  // lets this read through.
  const page = await loadGamePage(supabase, shortname);
  if (!page) notFound();

  // Not the owner? The route exists but holds nothing for them, which is the
  // same answer an unknown shortname gets.
  if (page.owner_user_id !== user.id) notFound();

  // Every release reported so far, including hidden ones, since managing them
  // is what this page is for. The public pickers filter those themselves.
  const { data: versions } = await supabase
    .from("game_version")
    .select("version,hidden_at")
    .eq("game.shortname", shortname)
    .order("last_seen_at", { ascending: false });
  const versionRows = (versions ?? []) as unknown as { version: string; hidden_at: string | null }[];

  const rows = [0, 1, 2, 3, 4];

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

        <form action={editGameDetails} className="flex flex-col gap-4">
          <input type="hidden" name="shortname" value={shortname} />
          <div className="flex flex-col gap-1.5">
            <label htmlFor="display_name" className={LABEL}>
              Display name
            </label>
            <input
              id="display_name"
              name="display_name"
              defaultValue={page.display_name ?? ""}
              maxLength={256}
              className={CONTROL}
            />
          </div>
          <div className="flex flex-col gap-1.5">
            <label htmlFor="description" className={LABEL}>
              Description
            </label>
            <textarea
              id="description"
              name="description"
              defaultValue={page.description ?? ""}
              maxLength={4000}
              rows={5}
              className={CONTROL}
            />
          </div>

          <fieldset className="flex flex-col gap-2">
            <legend className={LABEL}>Links</legend>
            {rows.map((row) => (
              <div key={row} className="flex gap-2">
                <input
                  name="label"
                  placeholder="Label"
                  defaultValue={page.links[row]?.label ?? ""}
                  aria-label={`Link ${row + 1} label`}
                  className={`${CONTROL} w-40`}
                />
                <input
                  name="url"
                  placeholder="https://"
                  type="url"
                  defaultValue={page.links[row]?.url ?? ""}
                  aria-label={`Link ${row + 1} URL`}
                  className={CONTROL}
                />
              </div>
            ))}
          </fieldset>

          <button
            type="submit"
            className="self-start rounded-md border border-neutral-800 px-4 py-2 text-sm text-neutral-300 transition-colors hover:border-neutral-600 active:border-neutral-500 hover:text-white active:text-white"
          >
            Save
          </button>
        </form>

        <section className="flex flex-col gap-3 border-t border-neutral-900 pt-6">
          <h2 className="text-sm uppercase tracking-wide text-neutral-400">Visibility</h2>
          <p className="text-sm text-neutral-500">
            Hidden means off the site for everybody but you. Facts keep flowing; unhiding brings
            everything back.
          </p>
          <form action={setGameVisibility} className="flex items-center gap-3">
            <input type="hidden" name="shortname" value={shortname} />
            <input type="hidden" name="hidden" value={page.hidden_at ? "false" : "true"} />
            <button
              type="submit"
              className="rounded-md border border-neutral-800 px-4 py-2 text-sm text-neutral-300 transition-colors hover:border-neutral-600 active:border-neutral-500 hover:text-white active:text-white"
            >
              {page.hidden_at ? "Unhide this game" : "Hide this game"}
            </button>
            {page.hidden_at ? (
              <span className="text-xs text-neutral-500">This game is hidden right now.</span>
            ) : null}
          </form>

          <ul className="flex flex-col gap-1.5 text-sm">
            {versionRows.map((row) => (
              <li key={row.version} className="flex items-center justify-between gap-3">
                <span className="font-mono text-neutral-300">{row.version}</span>
                <form action={setVersionVisibility}>
                  <input type="hidden" name="shortname" value={shortname} />
                  <input type="hidden" name="version" value={row.version} />
                  <input type="hidden" name="hidden" value={row.hidden_at ? "false" : "true"} />
                  <button
                    type="submit"
                    className="rounded-md border border-neutral-800 px-3 py-1 text-xs text-neutral-400 transition-colors hover:border-neutral-600 active:border-neutral-500 hover:text-neutral-200 active:text-neutral-200"
                  >
                    {row.hidden_at ? "Unhide release" : "Hide release"}
                  </button>
                </form>
              </li>
            ))}
          </ul>
        </section>

        <section className="flex flex-col gap-3 border-t border-neutral-900 pt-6">
          <h2 className="text-sm uppercase tracking-wide text-neutral-400">Logo</h2>
          <p className="text-sm text-neutral-500">Square, PNG or WebP, up to half a megabyte.</p>
          <form action={uploadGameImage} className="flex items-end gap-3">
            <input type="hidden" name="shortname" value={shortname} />
            <input type="hidden" name="kind" value="logo" />
            <input
              type="file"
              name="image"
              accept="image/png,image/webp"
              required
              className="text-sm text-neutral-300"
            />
            <button
              type="submit"
              className="rounded-md border border-neutral-800 px-4 py-2 text-sm text-neutral-300 transition-colors hover:border-neutral-600 active:border-neutral-500 hover:text-white active:text-white"
            >
              Upload logo
            </button>
          </form>
        </section>

        <section className="flex flex-col gap-3 border-t border-neutral-900 pt-6">
          <h2 className="text-sm uppercase tracking-wide text-neutral-400">Banner</h2>
          <p className="text-sm text-neutral-500">Wide, PNG or WebP, up to half a megabyte.</p>
          <form action={uploadGameImage} className="flex items-end gap-3">
            <input type="hidden" name="shortname" value={shortname} />
            <input type="hidden" name="kind" value="banner" />
            <input
              type="file"
              name="image"
              accept="image/png,image/webp"
              required
              className="text-sm text-neutral-300"
            />
            <button
              type="submit"
              className="rounded-md border border-neutral-800 px-4 py-2 text-sm text-neutral-300 transition-colors hover:border-neutral-600 active:border-neutral-500 hover:text-white active:text-white"
            >
              Upload banner
            </button>
          </form>
        </section>
      </div>
    </main>
  );
}
