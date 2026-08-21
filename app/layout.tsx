import { SpeedInsights } from "@vercel/speed-insights/next";
import type { Metadata } from "next";
import { Geist, Geist_Mono } from "next/font/google";
import Link from "next/link";
import { Suspense, ViewTransition } from "react";
import { CoilLogo } from "@/components/CoilLogo";
import { DownloadIcon, GalleryIcon, GamesIcon, MapsIcon, PublishIcon } from "@/components/icons";
import { LinkPending } from "@/components/LinkPending";
import { NavAccount, NavAccountFallback } from "@/components/NavAccount";
import { COILBOX_URL } from "@/lib/coilbox";
import { kindsPluralLower } from "@/lib/gallery/label";
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

/* Built from the kinds the gallery carries rather than written out, so the
   sentence cannot say four when there are five (tomjn/coilbox#1502). */
const description = `A place to share the ${kindsPluralLower()} you make in Coilbox.`;

export const metadata: Metadata = {
  metadataBase: new URL(siteUrl),
  title: "Coilbox Hub",
  description,
  openGraph: {
    title: "Coilbox Hub",
    description,
    type: "website",
  },
};

/* Icons carry the meaning on a narrow screen, where the labels collapse to
   screen reader only text rather than wrapping the header onto two lines. */
const navItem =
  "flex items-center gap-2 rounded-md px-2 py-1.5 transition-colors hover:text-white active:bg-neutral-900 active:text-white";

/* The inside of a nav link, which `LinkPending` dims while the page it leads
   to is still on its way. The same row layout as the link itself, so the icon
   and label sit exactly where they did. */
const navBody = "flex items-center gap-2";

/**
 * Nothing here reads the request.
 *
 * That is the point: everything but the account controls is the same for every
 * visitor, so it is built once and held, and `NavAccount` is the one piece
 * rendered per request. A single session read out here used to make every route
 * on the site dynamic.
 */
export default function RootLayout({ children }: LayoutProps<"/">) {
  return (
    <html
      lang="en"
      className={`${geistSans.variable} ${geistMono.variable} h-full antialiased`}
    >
      <body className="min-h-full flex flex-col">
        <header className="flex items-center justify-between px-6 py-4">
          <Link
            href="/"
            className="flex items-center gap-2 text-sm font-medium text-neutral-300 transition-colors hover:text-white active:text-white"
          >
            <CoilLogo className="w-5" />
            Coilbox Hub
          </Link>
          <nav className="-mr-2 flex items-center gap-1 text-sm text-neutral-400 sm:gap-3">
            <Link href="/gallery" className={navItem}>
              <LinkPending className={navBody}>
                <GalleryIcon className="w-4" />
                <span className="sr-only sm:not-sr-only">Gallery</span>
              </LinkPending>
            </Link>
            <Link href="/maps" className={navItem}>
              <LinkPending className={navBody}>
                <MapsIcon className="w-4" />
                <span className="sr-only sm:not-sr-only">Maps</span>
              </LinkPending>
            </Link>
            <Link href="/games" className={navItem}>
              <LinkPending className={navBody}>
                <GamesIcon className="w-4" />
                <span className="sr-only sm:not-sr-only">Games</span>
              </LinkPending>
            </Link>
            <Link href="/publish" className={navItem}>
              <LinkPending className={navBody}>
                <PublishIcon className="w-4" />
                <span className="sr-only sm:not-sr-only">Publish</span>
              </LinkPending>
            </Link>
            {/* The only outbound link in the nav, and it opens in a new tab so
                that following it from an item page does not lose the item the
                visitor came to import. */}
            <a
              href={COILBOX_URL}
              target="_blank"
              rel="noreferrer"
              className={navItem}
            >
              <DownloadIcon className="w-4" />
              <span className="sr-only sm:not-sr-only">Get Coilbox</span>
            </a>
            {/* The one part of the page that differs per visitor, so it is the
                one part rendered per request. The rest of the header is served
                from the held shell while this is read. */}
            <Suspense fallback={<NavAccountFallback />}>
              <NavAccount className={navItem} />
            </Suspense>
          </nav>
        </header>
        {/* One page fading into the next rather than being replaced by it. Only
            the page: the header is outside this and stays put, which is what it
            already did. A browser without the View Transitions API renders the
            children exactly as before. */}
        <ViewTransition>{children}</ViewTransition>
        {/* Reports Core Web Vitals from real visits (issue 94). It renders no
            markup, and in development it only logs to the console. */}
        <SpeedInsights />
      </body>
    </html>
  );
}
