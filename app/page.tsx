import { CoilLogo } from "@/components/CoilLogo";

export default function Home() {
  return (
    <main className="flex flex-1 flex-col items-center justify-center gap-8 px-6 text-center">
      <CoilLogo className="w-28" />
      <div className="flex flex-col gap-3">
        <h1 className="text-4xl font-semibold tracking-tight sm:text-5xl">
          Coming soon
        </h1>
        <p className="max-w-md text-balance text-neutral-400">
          A place to share the presets, challenges, setup packs and scenarios
          you make in Coilbox.
        </p>
      </div>
      <a
        href="https://github.com/tomjn/coilbox"
        className="text-sm text-neutral-500 underline-offset-4 transition-colors hover:text-neutral-300 hover:underline"
      >
        github.com/tomjn/coilbox
      </a>
    </main>
  );
}
