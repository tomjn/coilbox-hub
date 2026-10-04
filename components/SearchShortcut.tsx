"use client";

import { useEffect } from "react";
import { shouldFocusSearch } from "@/lib/searchShortcut";

/** Focuses the input with the given id when `/` is pressed. Renders nothing. */
export function SearchShortcut({ inputId }: { inputId: string }) {
  useEffect(() => {
    function onKeyDown(event: KeyboardEvent) {
      const target = event.target instanceof HTMLElement ? event.target : null;
      const allowed = shouldFocusSearch({
        key: event.key,
        ctrlKey: event.ctrlKey,
        metaKey: event.metaKey,
        altKey: event.altKey,
        isComposing: event.isComposing,
        defaultPrevented: event.defaultPrevented,
        targetTag: target?.tagName ?? "",
        targetEditable: target?.isContentEditable ?? false,
      });
      if (!allowed) return;
      const input = document.getElementById(inputId);
      if (!(input instanceof HTMLInputElement)) return;
      event.preventDefault();
      input.focus();
      input.select();
    }
    document.addEventListener("keydown", onKeyDown);
    return () => document.removeEventListener("keydown", onKeyDown);
  }, [inputId]);

  return null;
}
