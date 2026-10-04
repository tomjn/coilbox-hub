export type ShortcutEvent = {
  key: string;
  ctrlKey: boolean;
  metaKey: boolean;
  altKey: boolean;
  isComposing: boolean;
  defaultPrevented: boolean;
  targetTag: string;
  targetEditable: boolean;
};

const TEXT_FIELDS = new Set(["INPUT", "TEXTAREA", "SELECT"]);

/**
 * Whether a keypress should move focus to the search box (tomjn/coilbox-hub#462).
 * Shift is not checked, because some layouts need it to type a slash.
 */
export function shouldFocusSearch(event: ShortcutEvent): boolean {
  if (event.key !== "/") return false;
  if (event.defaultPrevented || event.isComposing) return false;
  if (event.ctrlKey || event.metaKey || event.altKey) return false;
  if (event.targetEditable) return false;
  return !TEXT_FIELDS.has(event.targetTag.toUpperCase());
}
