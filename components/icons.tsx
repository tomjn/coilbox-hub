import type { ReactNode } from "react";

/**
 * Every icon is drawn on the same 24px grid and stroke weight as `CoilLogo` so
 * the mark, the nav and the kind badges read as one set. They all sit next to
 * their own text label, so they are hidden from assistive technology.
 */
export function Icon({
  children,
  className,
}: {
  children: ReactNode;
  className?: string;
}) {
  return (
    <svg
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth={2}
      strokeLinecap="round"
      strokeLinejoin="round"
      className={className}
      aria-hidden="true"
    >
      {children}
    </svg>
  );
}

export function GalleryIcon({ className }: { className?: string }) {
  return (
    <Icon className={className}>
      <rect x="3" y="3" width="7" height="7" rx="1" />
      <rect x="14" y="3" width="7" height="7" rx="1" />
      <rect x="3" y="14" width="7" height="7" rx="1" />
      <rect x="14" y="14" width="7" height="7" rx="1" />
    </Icon>
  );
}

/** A folded paper map, which is the one shape nothing else in the nav uses. The
 *  gallery's four panes are the closest thing to it and read as a grid rather
 *  than as terrain. */
export function MapsIcon({ className }: { className?: string }) {
  return (
    <Icon className={className}>
      <path d="M9 3 3 6v15l6-3 6 3 6-3V3l-6 3-6-3z" />
      <path d="M9 3v15" />
      <path d="M15 6v15" />
    </Icon>
  );
}

/** A gamepad: two grips and a d-pad cross, drawn at the set's stroke weight. */
export function GamesIcon({ className }: { className?: string }) {
  return (
    <Icon className={className}>
      <path d="M6 9h4" />
      <path d="M8 7v4" />
      <path d="M15 8h0.01" />
      <path d="M18 11h0.01" />
      <path d="M17.3 5H6.7a4.7 4.7 0 0 0-4.66 5.28l.72 5.76A2.94 2.94 0 0 0 7.87 18l1.63-2h5l1.63 2a2.94 2.94 0 0 0 5.11-1.96l.72-5.76A4.7 4.7 0 0 0 17.3 5z" />
    </Icon>
  );
}

export function PublishIcon({ className }: { className?: string }) {
  return (
    <Icon className={className}>
      <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
      <path d="M17 8l-5-5-5 5" />
      <path d="M12 3v12" />
    </Icon>
  );
}

export function DownloadIcon({ className }: { className?: string }) {
  return (
    <Icon className={className}>
      <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
      <path d="M7 10l5 5 5-5" />
      <path d="M12 15V3" />
    </Icon>
  );
}

export function ModerationIcon({ className }: { className?: string }) {
  return (
    <Icon className={className}>
      <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />
    </Icon>
  );
}

export function AccountIcon({ className }: { className?: string }) {
  return (
    <Icon className={className}>
      <path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2" />
      <circle cx="12" cy="7" r="4" />
    </Icon>
  );
}

export function SignInIcon({ className }: { className?: string }) {
  return (
    <Icon className={className}>
      <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4" />
      <path d="M10 17l5-5-5-5" />
      <path d="M15 12H3" />
    </Icon>
  );
}

export function SignOutIcon({ className }: { className?: string }) {
  return (
    <Icon className={className}>
      <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
      <path d="M16 17l5-5-5-5" />
      <path d="M21 12H9" />
    </Icon>
  );
}
