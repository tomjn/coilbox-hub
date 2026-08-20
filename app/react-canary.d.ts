/// <reference types="react/canary" />

/**
 * `ViewTransition` is in the React the App Router runs on, which is a canary
 * release, and `@types/react` keeps those declarations in a file the default
 * types do not pull in. Referencing it here is what makes the component's props
 * typed rather than an error, and it costs nothing at runtime.
 */
