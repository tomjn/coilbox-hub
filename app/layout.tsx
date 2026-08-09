import type { Metadata } from "next";
import { Geist, Geist_Mono } from "next/font/google";
import Link from "next/link";
import { CoilLogo } from "@/components/CoilLogo";
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
            className="flex items-center gap-2 text-sm font-medium text-neutral-300 transition-colors hover:text-white"
          >
            <CoilLogo className="w-5" />
            Coilbox Hub
          </Link>
          <nav className="flex items-center gap-5 text-sm text-neutral-400">
            <Link href="/gallery" className="transition-colors hover:text-white">
              Gallery
            </Link>
            <Link href="/publish" className="transition-colors hover:text-white">
              Publish
            </Link>
          </nav>
        </header>
        {children}
      </body>
    </html>
  );
}
