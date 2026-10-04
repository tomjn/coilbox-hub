export type ButtonVariant = "primary" | "ghost" | "quiet" | "destructive";

export type ButtonSize = "xs" | "sm" | "md" | "base" | "lg";

const BASE =
  "rounded-md transition-colors disabled:opacity-60 group-aria-busy:cursor-progress group-aria-busy:opacity-60";

const SIZES: Record<ButtonSize, string> = {
  xs: "px-2 py-1 text-xs",
  sm: "px-3 py-1 text-xs",
  md: "px-3 py-1.5 text-sm",
  base: "px-4 py-2 text-sm",
  lg: "px-5 py-2.5 text-sm",
};

const VARIANTS: Record<ButtonVariant, string> = {
  primary:
    "bg-neutral-100 font-medium text-neutral-900 hover:bg-white active:bg-neutral-300",
  ghost:
    "border border-neutral-800 text-neutral-300 hover:border-neutral-600 active:border-neutral-500 hover:text-white active:text-white",
  quiet:
    "border border-neutral-800 text-neutral-400 hover:border-neutral-600 active:border-neutral-500 hover:text-neutral-200 active:text-neutral-200",
  destructive:
    "border border-red-900 text-red-300 hover:border-red-700 active:border-red-600 hover:text-red-200 active:text-red-200",
};

/**
 * The class string for a button. Use it directly on a `<Link>` or any other
 * element styled as a button, and through `Button` for a real `<button>`.
 *
 * `group-aria-busy` dims the button and shows the progress cursor while the
 * `BusyForm` around it is pending.
 */
export function buttonClass(
  variant: ButtonVariant = "ghost",
  { size = "base", className }: { size?: ButtonSize; className?: string } = {},
): string {
  return [BASE, SIZES[size], VARIANTS[variant], className].filter(Boolean).join(" ");
}

export function Button({
  variant,
  size,
  className,
  type = "button",
  ...props
}: {
  variant?: ButtonVariant;
  size?: ButtonSize;
} & React.ComponentProps<"button">) {
  return (
    <button
      type={type}
      className={buttonClass(variant, { size, className })}
      {...props}
    />
  );
}
