import type { Metadata } from "next";
import { Geist, Geist_Mono } from "next/font/google";
import Link from "next/link";
import { CoilLogo } from "@/components/CoilLogo";
import {
  AccountIcon,
  GalleryIcon,
  ModerationIcon,
  PublishIcon,
  SignOutIcon,
} from "@/components/icons";
import { NavSignIn } from "@/components/NavSignIn";
import { displayName } from "@/lib/author";
import { createClient } from "@/lib/supabase/server";
import "./globals.css";

const geistSans = Geist({
  variable: "--font-geist-sans",
  subsets: ["latin"],
});

const geistMono = Geist_Mono({
  variable: "--font-geist-mono",
  subsets: ["latin"],
});

/* An unfurler needs an absolute URL for the preview image. Vercel sets
   VERCEL_PROJECT_PRODUCTION_URL on every deployment, so production resolves
   against the real domain and local development against localhost. */
const siteUrl = process.env.VERCEL_PROJECT_PRODUCTION_URL
  ? `https://${process.env.VERCEL_PROJECT_PRODUCTION_URL}`
  : "http://localhost:3000";

export const metadata: Metadata = {
  metadataBase: new URL(siteUrl),
  title: "Coilbox Hub",
  description:
    "A place to share the presets, challenges, setup packs and scenarios you make in Coilbox.",
  openGraph: {
    title: "Coilbox Hub",
    description:
      "A place to share the presets, challenges, setup packs and scenarios you make in Coilbox.",
    type: "website",
  },
};

/* Icons carry the meaning on a narrow screen, where the labels collapse to
   screen reader only text rather than wrapping the header onto two lines. */
const navItem =
  "flex items-center gap-2 rounded-md px-2 py-1.5 transition-colors hover:text-white";

export default async function RootLayout({ children }: LayoutProps<"/">) {
  const supabase = await createClient();
  const {
    data: { user },
  } = await supabase.auth.getUser();
  const author = user ? displayName(user.user_metadata ?? {}) : null;
  // Only signed in visitors can be moderators, so nobody else pays for the call.
  const { data: moderator } = user
    ? await supabase.rpc("is_moderator")
    : { data: false };

  return (
    <html
      lang="en"
      className={`${geistSans.variable} ${geistMono.variable} h-full antialiased`}
    >
      <body className="min-h-full flex flex-col">
        <header className="flex items-center justify-between px-6 py-4">
          <Link
            href="/"
            className="flex items-center gap-2 text-sm font-medium text-neutral-300 transition-colors hover:text-white"
          >
            <CoilLogo className="w-5" />
            Coilbox Hub
          </Link>
          <nav className="-mr-2 flex items-center gap-1 text-sm text-neutral-400 sm:gap-3">
            <Link href="/gallery" className={navItem}>
              <GalleryIcon className="w-4" />
              <span className="sr-only sm:not-sr-only">Gallery</span>
            </Link>
            <Link href="/publish" className={navItem}>
              <PublishIcon className="w-4" />
              <span className="sr-only sm:not-sr-only">Publish</span>
            </Link>
            {moderator ? (
              <Link href="/moderation" className={navItem}>
                <ModerationIcon className="w-4" />
                <span className="sr-only sm:not-sr-only">Moderation</span>
              </Link>
            ) : null}
            {author ? (
              <>
                <Link href="/account" className={navItem}>
                  <AccountIcon className="w-4" />
                  <span className="sr-only sm:not-sr-only">
                    <span className="block max-w-32 truncate">{author}</span>
                  </span>
                </Link>
                <form action="/auth/signout" method="post">
                  <button type="submit" className={navItem}>
                    <SignOutIcon className="w-4" />
                    <span className="sr-only sm:not-sr-only">Sign out</span>
                  </button>
                </form>
              </>
            ) : (
              <NavSignIn className={navItem} />
            )}
          </nav>
        </header>
        {children}
      </body>
    </html>
  );
}
