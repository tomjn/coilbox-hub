import type { NextConfig } from "next";

// The hub serves its own pictures at the size it shows them, so nothing here
// goes through next/image and no remote host has to be allowed.
const nextConfig: NextConfig = {};

export default nextConfig;
