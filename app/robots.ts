import type { MetadataRoute } from "next";
import { robotsRules } from "@/lib/robots";
import { siteUrl } from "@/lib/site";

export default function robots(): MetadataRoute.Robots {
  return robotsRules(siteUrl(), process.env.VERCEL_ENV);
}
