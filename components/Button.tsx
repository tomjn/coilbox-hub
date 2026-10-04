export type ButtonVariant = "primary" | "ghost" | "destructive";

const BASE =
  "rounded-md px-4 py-2 text-sm transition-colors disabled:opacity-60 group-aria-busy:cursor-progress group-aria-busy:opacity-60";

const VARIANTS: Record<ButtonVariant, string> = {
  primary:
    "bg-neutral-100 font-medium text-neutral-900 hover:bg-white active:bg-neutral-300",
  ghost:
    "border border-neutral-800 text-neutral-300 hover:border-neutral-600 active:border-neutral-500 hover:text-white active:text-white",
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
  className?: string,
): string {
  return [BASE, VARIANTS[variant], className].filter(Boolean).join(" ");
}

export function Button({
  variant,
  className,
  type = "button",
  ...props
}: {
  variant?: ButtonVariant;
} & React.ComponentProps<"button">) {
  return (
    <button
      type={type}
      className={buttonClass(variant, className)}
      {...props}
    />
  );
}
